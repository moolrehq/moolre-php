# Moolre PHP SDK

Framework-agnostic PHP package for initiating Moolre hosted payments and verifying transactions from any PHP application.

This package was extracted from the working Moolre WooCommerce gateway flow. It uses the same Moolre API endpoint:

- `state=starter` starts a payment and returns `data.authorization_url`
- `POST /open/transact/status` verifies a transaction by reference

## Requirements

- PHP 7.4 or newer
- JSON extension
- cURL extension recommended, with PHP streams used as a fallback
- HTTPS access to the Moolre API

For integration testing only, set `MOOLRE_FORCE_STREAMS=1` to exercise the PHP-stream transport even when cURL is installed.

## Installation

Install with Composer:

```bash
composer require moolre/moolre-php
```

For local package development, you can install a checkout as a path repository:

```bash
composer config repositories.moolre path /absolute/path/to/moolre-php
composer require moolre/moolre-php:@dev
```

### Upgrading to 1.0.2

No public methods were removed and no existing method signatures changed. This security release intentionally rejects inputs that older releases accepted:

- Successful verification responses must include the configured Moolre account number.
- Conflicting or malformed status, reference, and account-number fields are rejected.
- Payment-initiation responses are rejected when their reference differs from the requested reference.
- Callback and redirect URLs must use HTTPS, except for loopback development URLs.
- Floating-point amounts that require rounding beyond two decimal places are rejected.
- Generated references now contain a 128-bit random suffix, so applications must continue treating references as opaque strings.

Applications already using Moolre's documented status response, HTTPS public URLs, decimal-string amounts, and opaque references require no code changes. New integrations should use `verifySuccessfulPayment()` before fulfillment.

For development inside this package:

```bash
cd moolre-php
composer install
```

## Server SDK (`Moolre\Moolre`)

Since 1.1.0 the package also contains a full server SDK for the Moolre API. The hosted-checkout `Moolre\Client` below is unchanged; `Moolre\Moolre` adds collections (with the OTP flow), payouts, name validation, accounts and transactions, airtime and data sales, SMS, WhatsApp, payment links, bulk payouts, invoices and webhook verification. It needs PHP 7.4+, `ext-curl` and `ext-json`.

```php
use Moolre\Moolre;

$moolre = new Moolre([
    'environment'   => 'sandbox',            // 'live' (https://api.moolre.com) or 'sandbox' (https://sandbox.moolre.com)
    'user'          => getenv('MOOLRE_USER'),      // X-API-USER
    'apiKey'        => getenv('MOOLRE_API_KEY'),   // X-API-KEY    (payouts, accounts, sales, bulk)
    'pubKey'        => getenv('MOOLRE_PUBKEY'),    // X-API-PUBKEY (collections, payment links)
    'vasKey'        => getenv('MOOLRE_VASKEY'),    // X-API-VASKEY (SMS, WhatsApp)
    'accountNumber' => '10000001',                 // default wallet for every call
]);

// Collect from a customer (safe to retry with the same key)
$result = $moolre->payments->initiate([
    'channel' => 'MTN', 'currency' => 'GHS', 'payer' => '0241234567',
    'amount' => '1.00', 'externalRef' => 'PAY-1001',
], ['idempotencyKey' => Moolre::newIdempotencyKey()]);

echo $result->code, ' ', $result->requestId;
if ($result->otpRequired()) {            // code TP14: the customer was sent an SMS code
    $moolre->payments->verifyOtp([/* same params */ 'otpCode' => '123456']);
}
```

Every call returns a `Moolre\Result` (`ok`, `status`, `code`, `message`, `data`, `go`, `requestId`, `notices`, `replayed`, `httpStatus`, `raw`). When the API answers `status: 0` an `Moolre\Exceptions\ApiError` is thrown (subclasses `AuthenticationError`, `IdempotencyError`, `RateLimitError`, `NotFoundError`, `ValidationError`, `ServerError`) carrying `apiCode`, `apiMessage`, `fields`, `requestId` and `result`; pass `'throwOnError' => false` to get the result instead. Network failures throw `NetworkError`, non-JSON replies `InvalidResponseError`, bad configuration `ConfigurationError`.

Other options: `'strictHttpStatus' => true` (ask for real 4xx/5xx codes), `maxRetries` (default 2; only reads and calls that carry an `idempotencyKey` are retried), `timeoutSec` (60), `logger` (a `callable(string $message, array $context)`; API notices are logged once per code, default `error_log`).

### Payouts and name validation

```php
$name = $moolre->transfers->validateName(['channel' => 'MTN', 'currency' => 'GHS', 'receiver' => '0241234567']);

$moolre->transfers->create([
    'channel' => 'MTN', 'currency' => 'GHS', 'amount' => '25.00', 'receiver' => '0241234567',
    'externalRef' => 'PAYOUT-77', 'senderName' => 'Acme',
], ['idempotencyKey' => 'payout-77']);
```

### Transactions with cursor pagination

```php
foreach ($moolre->accounts->iterateTransactions(['status' => 1], ['pageSize' => 200]) as $tx) {
    echo $tx['transactionid'], ' ', $tx['amount'], "\n";
}
```

### Bulk payouts

```php
$batch = $moolre->bulk->create([
    'currency' => 'GHS', 'batchRef' => 'payroll-2026-11',
    'items' => [
        ['externalRef' => 'p-1', 'receiver' => '0241234567', 'channel' => 'MTN', 'amount' => '10.00'],
        ['externalRef' => 'p-2', 'receiver' => '0201234567', 'channel' => 'TELECEL', 'amount' => '5.50', 'senderName' => 'Acme'],
    ],
], ['idempotencyKey' => 'payroll-2026-11']);

print_r($batch->data['rejected']);                     // rows refused at submit, with index/code/message/externalref
$moolre->bulk->status(['batchRef' => 'payroll-2026-11']);   // counts + a page of items
foreach ($moolre->bulk->iterateItems(['batchRef' => 'payroll-2026-11', 'status' => 'failed']) as $item) {
    echo $item['externalref'], ' ', $item['code'], PHP_EOL;
}
```

Up to 500 items per batch, validated on submit; `'atomic' => true` queues nothing if any row is rejected. Sending the same `batchRef` and body again replays the original batch (`BLK02`). A `bulk_payout.completed` webhook is sent when every item is final.

### Invoices

```php
$inv = $moolre->invoices->create([
    'currency' => 'GHS', 'invoiceName' => 'Term 1 fees', 'customer' => ['name' => 'Ama Mensah', 'phone' => '0244000111'],
    'items' => [['description' => 'Tuition', 'quantity' => 1, 'amount' => '450.00']],
    'dueDate' => '2026-12-31', 'reference' => 'INV-1001',
]);
echo $inv->data['payment_link'];           // give this to the customer
$moolre->invoices->status(['reference' => 'INV-1001']);
foreach ($moolre->invoices->iterate(['status' => 'pending']) as $i) { echo $i['invoiceid']; }
$moolre->invoices->cancel($inv->data['invoiceid']);
```

### Verifying webhooks

Moolre signs every callback: `X-Moolre-Signature: t=<unix>,v1=<hex HMAC-SHA256(wallet secret, "<t>.<raw body>")>`. Verify the **raw** body before trusting it, and dedupe on `event_id`.

```php
$raw = file_get_contents('php://input');
try {
    $event = Moolre\Webhook::construct($raw, $_SERVER['HTTP_X_MOOLRE_SIGNATURE'] ?? null, getenv('MOOLRE_WALLET_SECRET'));
} catch (Moolre\Exceptions\WebhookSignatureError $e) {   // $e->reason: malformed | mismatch | timestamp
    http_response_code(400);
    exit;
}
if ($event->event === 'payment.succeeded') { /* fulfil once per $event->eventId */ }
http_response_code(200);
```

`Webhook::verify($raw, $header, $secret, $toleranceSec = 300)` returns a boolean. The comparison is constant-time and timestamps outside the tolerance are rejected.

### Single-file install

Without Composer, copy `moolre-sdk.php` (the server SDK only) and `require` it: `require 'moolre-sdk.php'; $moolre = new Moolre\Moolre([...]);`.

### Server SDK tests

`php tests/sdk/run.php` starts a local mock API with `php -S` and needs no dev dependencies; `MOOLRE_BUNDLE=dist/moolre-sdk.php php tests/sdk/run.php` tests the single-file bundle (build it with `php scripts/bundle.php`). Shared webhook vectors live in `tests/sdk/vectors/webhook.json`.

## Hosted checkout client (`Moolre\Client`)

The sections below describe the original hosted-checkout client.

## Example Project

A complete raw PHP demo app is included at [`examples/raw-php-app`](examples/raw-php-app). It shows a cart checkout, payment initiation, webhook callback handling, customer redirect verification, and a small local JSON order store. Its receipt is bound to the initiating browser session, and its redacted order list is available only from a loopback address.

Run it from the SDK repository:

```bash
cd examples/raw-php-app
composer install
php -S localhost:8080 -t public
```

Set your credentials before starting the server:

```bash
cp .env.example .env
```

Then edit `.env` with your Moolre values. You can also set environment variables directly:

```bash
export MOOLRE_PRODUCTION="false"
export MOOLRE_ACCOUNT_NUMBER="your_account_number"
export MOOLRE_API_USER="your_moolre_account_username"
export MOOLRE_PUBLIC_KEY=""
export MOOLRE_EXPIRATION_TIME="10"
export APP_URL="http://localhost:8080"
```

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Then edit `.env`, or set variables in the same terminal:

```powershell
$env:MOOLRE_PRODUCTION="false"
$env:MOOLRE_ACCOUNT_NUMBER="your_account_number"
$env:MOOLRE_API_USER="your_moolre_account_username"
$env:MOOLRE_PUBLIC_KEY=""
$env:MOOLRE_EXPIRATION_TIME="10"
$env:APP_URL="http://localhost:8080"
```

Then open `http://localhost:8080`. If Moolre needs to call your local callback/webhook, expose the app through a tunnel and set `APP_URL` to the public tunnel URL.

## Live and Sandbox Mode

The raw PHP example uses `MOOLRE_PRODUCTION` as the environment switch:

- `MOOLRE_PRODUCTION=true`: live mode, using `https://api.moolre.com/embed/src/start`
- `MOOLRE_PRODUCTION=false` or unset: sandbox mode, using `https://sandbox.moolre.com/embed/src/start`

Live payment initiation requires `MOOLRE_PUBLIC_KEY` and `MOOLRE_ACCOUNT_NUMBER`; live verification also requires `MOOLRE_API_USER`.

Sandbox mode requires `MOOLRE_ACCOUNT_NUMBER` and `MOOLRE_API_USER`. The SDK sends `MOOLRE_API_USER` as the `X-API-USER` header. `MOOLRE_PUBLIC_KEY` is optional only in sandbox.

`MOOLRE_API_USER` is required for every `verifyPayment()` call, including live mode. Transaction-status requests also include `X-API-PUBKEY` when a public key is configured.

For a direct SDK integration, choose the endpoint with your own config:

```php
use Moolre\Client;

$isProduction = false;

$client = new Client(
    $isProduction ? 'your_public_key' : '',
    'your_account_number',
    null,
    $isProduction ? Client::DEFAULT_BASE_URL : Client::SANDBOX_BASE_URL,
    60,
    false,
    'your_moolre_account_username',
    $isProduction ? Client::DEFAULT_VERIFICATION_URL : Client::SANDBOX_VERIFICATION_URL
);
```

`MOOLRE_BASE_URL` and `MOOLRE_VERIFICATION_URL` are supported in the raw PHP example as advanced overrides, but most apps should switch with `MOOLRE_PRODUCTION`.

## Raw PHP

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Moolre\Client;

$client = new Client(
    'your_public_key',
    'your_account_number',
    null,
    Client::DEFAULT_BASE_URL,
    60,
    false,
    'your_moolre_account_username'
);

$payment = $client->initiatePayment([
    'reference' => Client::generateReference('checkout'),
    'email' => 'customer@example.com',
    'amount' => '120.00',
    'currency' => 'GHS',
    'callback' => 'https://example.com/moolre/webhook',
    'redirect' => 'https://example.com/moolre/receipt',
    'expiration_time' => 10,
    'metadata' => [
        'order_id' => '1001',
        'customer_id' => '1141',
    ],
]);

header('Location: ' . $payment->authorizationUrl(), true, 302);
exit;
```

Use `callback` for the server-to-server notification URL and `redirect` for the customer return page. Your redirect page should verify the returned `reference` before showing a paid receipt. Your callback/webhook should also verify the posted reference before updating fulfillment state. The SDK requires Moolre to return the original external reference and rejects missing or mismatched references:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Moolre\Client;

$client = new Client(
    'your_public_key',
    'your_account_number',
    null,
    Client::DEFAULT_BASE_URL,
    60,
    false,
    'your_moolre_account_username'
);
// Load the pending order from your database by reference first.
$order = [
    'amount' => '120.00',
];

$transaction = $client->verifySuccessfulPayment(
    $_GET['reference'] ?? '',
    $order['amount']
);

// Atomically transition this exact pending order to paid. Fulfil only if
// your database reports that this request performed the first transition.
```

## Integration Examples

The SDK is framework-agnostic. Each framework only needs to provide configuration, routing, redirects, and your own order update logic.

The status endpoint provides `txstatus`, `externalref`, `amount`, and `accountnumber`; it does not provide currency or customer email. In every webhook and redirect handler, load the local pending order by its exact reference and call `verifySuccessfulPayment($reference, $orderAmount)` before fulfillment. The client automatically checks the configured account number. Update the order atomically so callback replays cannot fulfil it twice or reopen cancelled/refunded orders.

```php
$localOrder = $orders->findPendingByReference($reference); // Exact full-reference lookup.

if ($localOrder === null) {
    throw new RuntimeException('Unknown pending payment reference.');
}

$transaction = $client->verifySuccessfulPayment($reference, (string) $localOrder['amount']);

// Use a conditional database update such as:
// UPDATE orders SET status = 'paid', provider_transaction_id = ?
// WHERE id = ? AND status = 'pending'
// Fulfil only when exactly one row changed.
```

### Laravel

```php
use Moolre\Client;
use App\Models\Order;

Route::post('/pay', function () {
    $client = new Client(config('services.moolre.public_key'), config('services.moolre.account_number'));
    $order = Order::query()
        ->whereKey(request('order_id'))
        ->where('user_id', auth()->id())
        ->where('status', 'pending')
        ->firstOrFail();
    $reference = Client::generateReference('checkout');
    $order->update(['moolre_reference' => $reference]);

    $payment = $client->initiatePayment([
        'reference' => $reference,
        'email' => $order->billing_email,
        'amount' => (string) $order->total,
        'currency' => 'GHS',
        'callback' => route('moolre.webhook'),
        'redirect' => route('moolre.redirect'),
        'expiration_time' => 10,
        'metadata' => [
            'customer_id' => (string) auth()->id(),
        ],
    ]);

    return redirect()->away($payment->authorizationUrl());
});

Route::post('/moolre/webhook', function () {
    $client = new Client(
        config('services.moolre.public_key'),
        config('services.moolre.account_number'),
        null,
        Client::DEFAULT_BASE_URL,
        60,
        false,
        config('services.moolre.api_user')
    );
    $reference = (string) request('reference');
    $order = Order::where('moolre_reference', $reference)->where('status', 'pending')->firstOrFail();
    $transaction = $client->verifySuccessfulPayment($reference, (string) $order->total);
    $changed = Order::whereKey($order->id)->where('status', 'pending')->update([
        'status' => 'paid',
        'provider_transaction_id' => $transaction->data()['transactionid'] ?? null,
    ]);

    // Dispatch fulfillment only when $changed === 1.
    return response()->json(['status' => 'ok']);
})->name('moolre.webhook');

Route::get('/moolre/redirect', function () {
    $client = new Client(
        config('services.moolre.public_key'),
        config('services.moolre.account_number'),
        null,
        Client::DEFAULT_BASE_URL,
        60,
        false,
        config('services.moolre.api_user')
    );
    $reference = (string) request('reference');
    $order = Order::where('moolre_reference', $reference)->firstOrFail();
    abort_unless(auth()->check() && (int) $order->user_id === (int) auth()->id(), 403);
    $client->verifySuccessfulPayment($reference, (string) $order->total);

    return view('orders.receipt', ['order' => $order]);
})->name('moolre.redirect');
```

### Symfony

```php
<?php

namespace App\Controller;

use Moolre\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MoolreController extends AbstractController
{
    #[Route('/pay', methods: ['POST'])]
    public function pay(Request $request): RedirectResponse
    {
        $client = new Client(
            $_ENV['MOOLRE_PUBLIC_KEY'],
            $_ENV['MOOLRE_ACCOUNT_NUMBER']
        );

        $order = $this->orders->requirePendingForCustomer(
            (string) $request->request->get('order_id'),
            $this->getUser()
        );
        $reference = Client::generateReference('checkout');
        $this->orders->setMoolreReference($order, $reference);

        $payment = $client->initiatePayment([
            'reference' => $reference,
            'email' => $order->getBillingEmail(),
            'amount' => $order->getTotalAsDecimal(),
            'currency' => 'GHS',
            'callback' => $this->generateUrl('moolre_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'redirect' => $this->generateUrl('moolre_redirect', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'expiration_time' => 10,
            'metadata' => [
                'order_id' => (string) $request->request->get('order_id'),
            ],
        ]);

        return new RedirectResponse($payment->authorizationUrl());
    }

    #[Route('/moolre/webhook', name: 'moolre_webhook', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $client = new Client(
            $_ENV['MOOLRE_PUBLIC_KEY'],
            $_ENV['MOOLRE_ACCOUNT_NUMBER'],
            null,
            Client::DEFAULT_BASE_URL,
            60,
            false,
            $_ENV['MOOLRE_API_USER']
        );
        $payload = json_decode($request->getContent(), true) ?: $request->request->all();
        $reference = (string) ($payload['reference'] ?? '');
        $order = $this->orders->findPendingByMoolreReference($reference);

        if ($order === null) {
            return new Response('Unknown pending payment.', 404);
        }

        $transaction = $client->verifySuccessfulPayment($reference, $order->getTotalAsDecimal());
        $transitioned = $this->orders->markPaidIfPending($order, $transaction->data()['transactionid'] ?? null);

        // Dispatch fulfillment only when $transitioned is true.
        return new Response('Payment verified.', 200);
    }

    #[Route('/moolre/redirect', name: 'moolre_redirect', methods: ['GET'])]
    public function redirectResult(Request $request): Response
    {
        $client = new Client(
            $_ENV['MOOLRE_PUBLIC_KEY'],
            $_ENV['MOOLRE_ACCOUNT_NUMBER'],
            null,
            Client::DEFAULT_BASE_URL,
            60,
            false,
            $_ENV['MOOLRE_API_USER']
        );
        $reference = (string) $request->query->get('reference');
        $order = $this->orders->findByMoolreReference($reference);

        if ($order === null || $order->getCustomer() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $client->verifySuccessfulPayment($reference, $order->getTotalAsDecimal());

        return $this->render('orders/receipt.html.twig', ['order' => $order]);
    }
}
```

### Slim

```php
use Moolre\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->post('/pay', function (Request $request, Response $response) use ($orders, $currentUser) {
    $data = (array) $request->getParsedBody();
    $client = new Client($_ENV['MOOLRE_PUBLIC_KEY'], $_ENV['MOOLRE_ACCOUNT_NUMBER']);
    $order = $orders->requirePendingForCustomer((string) ($data['order_id'] ?? ''), $currentUser->id());
    $reference = Client::generateReference('checkout');
    $orders->setMoolreReference($order, $reference);

    $payment = $client->initiatePayment([
        'reference' => $reference,
        'email' => $order->billingEmail(),
        'amount' => $order->totalAsDecimal(),
        'currency' => 'GHS',
        'callback' => 'https://example.com/moolre/webhook',
        'redirect' => 'https://example.com/moolre/redirect',
        'expiration_time' => 10,
        'metadata' => [
            'order_id' => (string) ($data['order_id'] ?? ''),
        ],
    ]);

    return $response
        ->withHeader('Location', $payment->authorizationUrl())
        ->withStatus(302);
});

$app->post('/moolre/webhook', function (Request $request, Response $response) use ($orders) {
    $client = new Client(
        $_ENV['MOOLRE_PUBLIC_KEY'],
        $_ENV['MOOLRE_ACCOUNT_NUMBER'],
        null,
        Client::DEFAULT_BASE_URL,
        60,
        false,
        $_ENV['MOOLRE_API_USER']
    );
    $payload = (array) $request->getParsedBody();
    $reference = (string) ($payload['reference'] ?? '');
    $order = $orders->findPendingByMoolreReference($reference);

    if ($order === null) {
        return $response->withStatus(404);
    }

    $transaction = $client->verifySuccessfulPayment($reference, $order->totalAsDecimal());
    $transitioned = $orders->markPaidIfPending($order, $transaction->data()['transactionid'] ?? null);
    // Dispatch fulfillment only when $transitioned is true.
    $response->getBody()->write('Payment verified.');
    return $response;
});

$app->get('/moolre/redirect', function (Request $request, Response $response) use ($orders, $currentUser) {
    $client = new Client(
        $_ENV['MOOLRE_PUBLIC_KEY'],
        $_ENV['MOOLRE_ACCOUNT_NUMBER'],
        null,
        Client::DEFAULT_BASE_URL,
        60,
        false,
        $_ENV['MOOLRE_API_USER']
    );
    $reference = (string) ($request->getQueryParams()['reference'] ?? '');
    $order = $orders->findByMoolreReference($reference);

    if ($order === null || $order->customerId() !== $currentUser->id()) {
        return $response->withStatus(403);
    }

    $client->verifySuccessfulPayment($reference, $order->totalAsDecimal());
    $response->getBody()->write('Payment receipt page.');
    return $response;
});
```

### CodeIgniter 4

```php
<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Moolre\Client;

final class MoolreController extends Controller
{
    public function pay()
    {
        $client = new Client(
            getenv('MOOLRE_PUBLIC_KEY'),
            getenv('MOOLRE_ACCOUNT_NUMBER')
        );

        $order = $this->orders->requirePendingForCustomer(
            (string) $this->request->getPost('order_id'),
            (string) session('customer_id')
        );
        $reference = Client::generateReference('checkout');
        $this->orders->setMoolreReference($order, $reference);

        $payment = $client->initiatePayment([
            'reference' => $reference,
            'email' => $order->billingEmail(),
            'amount' => $order->totalAsDecimal(),
            'currency' => 'GHS',
            'callback' => site_url('moolre/webhook'),
            'redirect' => site_url('moolre/redirect'),
            'expiration_time' => 10,
            'metadata' => [
                'order_id' => (string) $this->request->getPost('order_id'),
            ],
        ]);

        return redirect()->to($payment->authorizationUrl());
    }

    public function webhook()
    {
        $client = new Client(
            getenv('MOOLRE_PUBLIC_KEY'),
            getenv('MOOLRE_ACCOUNT_NUMBER'),
            null,
            Client::DEFAULT_BASE_URL,
            60,
            false,
            getenv('MOOLRE_API_USER') ?: null
        );
        $reference = (string) $this->request->getPost('reference');
        $order = $this->orders->findPendingByMoolreReference($reference);

        if ($order === null) {
            return $this->response->setStatusCode(404)->setBody('Unknown pending payment.');
        }

        $transaction = $client->verifySuccessfulPayment($reference, $order->totalAsDecimal());
        $transitioned = $this->orders->markPaidIfPending($order, $transaction->data()['transactionid'] ?? null);
        // Dispatch fulfillment only when $transitioned is true.
        return $this->response->setBody('Payment verified.');
    }

    public function redirectResult()
    {
        $client = new Client(
            getenv('MOOLRE_PUBLIC_KEY'),
            getenv('MOOLRE_ACCOUNT_NUMBER'),
            null,
            Client::DEFAULT_BASE_URL,
            60,
            false,
            getenv('MOOLRE_API_USER') ?: null
        );
        $reference = (string) $this->request->getGet('reference');
        $order = $this->orders->findByMoolreReference($reference);

        if ($order === null || $order->customerId() !== (string) session('customer_id')) {
            return $this->response->setStatusCode(403)->setBody('Receipt unavailable.');
        }

        $client->verifySuccessfulPayment($reference, $order->totalAsDecimal());
        return $this->response->setBody('Payment receipt page.');
    }
}
```

## API

### `new Moolre\Client($publicKey, $accountNumber, $transport = null, $baseUrl = Client::DEFAULT_BASE_URL, $timeout = 60, $allowInsecureBaseUrl = false, $apiUser = null, $verificationUrl = null)`

Creates a Moolre client.

By default, the SDK requires the Moolre base URL to use HTTPS. Set `$allowInsecureBaseUrl` to `true` only for a controlled local HTTP endpoint; the SDK always rejects other URL schemes and base URLs containing user credentials.

For sandbox, set `$baseUrl` to `Client::SANDBOX_BASE_URL` (`https://sandbox.moolre.com/embed/src/start`) and pass your Moolre account username as `$apiUser`. The SDK sends it as `X-API-USER`. Public key is optional only for sandbox payment-initiation requests.

`$verificationUrl` defaults to `Client::DEFAULT_VERIFICATION_URL` (`https://api.moolre.com/open/transact/status`) or, when the base URL is the sandbox host, `Client::SANDBOX_VERIFICATION_URL`. Pass an explicit HTTPS URL if Moolre gives your sandbox a different status endpoint.

### `$client->initiatePayment(array|PaymentRequest $payment): PaymentInitiation`

Required payment fields:

- `reference`: Your unique order or payment reference
- `email`: Customer email address
- `amount`: Positive decimal amount, preferably supplied as a string such as `'120.00'`. Scientific notation and values with more than two decimal places are rejected, including floats that would require rounding.
- `currency`: Currency code, currently `GHS`
- `callback`: Fully qualified server callback/webhook URL where Moolre can post payment updates

Optional fields:

- `redirect`: Fully qualified customer return URL after hosted checkout
- `expiration_time`: Payment link expiration time in minutes. Optional; when provided, the minimum is `1` minute.
- `metadata`: Additional merchant metadata to attach to the payment request, such as `customer_id` or `order_id`
- `nonce_value`: Optional value if your app wants to associate a nonce with the payment request
- `tx_source`: Optional transaction source. Defaults to `php-sdk`

Callback and redirect URLs must use HTTPS. Plain HTTP is accepted only for `localhost`, `127.0.0.1`, and `::1` development URLs.

### `$client->verifyPayment(string $reference): TransactionVerification`

Confirms the payment status for a reference. It sends a JSON `POST` to the configured status URL with `type: 1`, `idtype: '1'`, `id: $reference`, and `accountnumber: $accountNumber`; it includes both `X-API-USER` and `X-API-PUBKEY` headers when the public key is configured. `$apiUser` is mandatory for this method. Moolre must return the original `externalref` (or an unambiguous equivalent reference); missing, mismatched, or conflicting reference/status fields are rejected. Successful responses must also contain the configured account number.

This lower-level method can return an unsuccessful transaction for polling. Do not use `isSuccessful()` alone to fulfil an order.

### `$client->verifySuccessfulPayment(string $reference, string $expectedAmount): TransactionVerification`

Use this method before delivering value. It requires a successful transaction and exact matches for the requested reference, expected local amount, and the account number configured on the client. It throws `Moolre\Exceptions\ApiException` for pending, failed, missing, or mismatched payment data.

### `$transaction->matchesAmountAndAccountNumber(string $amount, string $accountNumber): bool`

This response-level compatibility helper requires a successful `txstatus`, exact normalized amount, and account number. Prefer `Client::verifySuccessfulPayment()`, which also binds the response to the exact requested reference and the client's configured account.

### `$transaction->matchesPaymentDetails(string $amount, string $currency, ?string $email = null, ?string $accountNumber = null): bool` — deprecated for Moolre status responses

This strict compatibility helper returns `true` only when a response includes and matches amount, currency, email, and account number. Moolre's transaction-status endpoint does not include the required currency or email fields, so the method correctly returns `false` for that endpoint. Use `Client::verifySuccessfulPayment()` instead.

### `Client::generateReference(string $prefix = 'moolre'): string`

Generates a unique reference with 128 bits of cryptographically secure randomness. The method fails closed if the operating system cannot provide secure randomness. Prefixes are sanitized and limited to 32 characters. They should be non-sensitive labels such as `checkout`; do not embed sequential order IDs, email addresses, or customer IDs.

## Error Handling

All SDK exceptions extend `Moolre\Exceptions\MoolreException`.

```php
use Moolre\Exceptions\MoolreException;

try {
    $payment = $client->initiatePayment($payload);
} catch (MoolreException $exception) {
    // Log the error and show a safe message to your user.
}
```

## Security Notes

- Keep your Moolre public key and account number outside source control.
- Always verify payment server-side before marking an order as paid.
- Match the exact opaque reference to a local pending order; never derive an order ID from a callback reference.
- Call `verifySuccessfulPayment()` with the amount stored on that local order before delivering value.
- The status response does not confirm currency or email. Keep those values server-controlled when creating the order.
- Use an atomic, idempotent pending-to-paid transition and never reopen cancelled or refunded orders from callback replays.
- Protect customer-facing receipts with the customer's authenticated session or a separate signed state token. A payment reference alone is not authorization to view order data.
- Treat `callback` as the server notification/webhook URL and `redirect` as the customer return page.
- Use HTTPS callback and redirect URLs; HTTP is accepted only for loopback development.

## Troubleshooting

### SSL certificate problem on local PHP

If local PHP reports `SSL certificate problem: unable to get local issuer certificate`, PHP/cURL cannot find a trusted CA bundle. Configure `curl.cainfo` and `openssl.cafile` in `php.ini`, or set `MOOLRE_CA_FILE`/`SSL_CERT_FILE` to a valid `cacert.pem` path.

This is a local PHP trust-store issue. It can happen even when a WooCommerce integration works, because WordPress has its own HTTP layer and CA handling.

## Testing

```bash
cd moolre-php
composer smoke
composer test
composer analyse
```

`composer smoke` runs without dev dependencies. `composer test` requires the PHPUnit dev dependency from `composer install`.

The client accepts a custom `TransportInterface`, so applications can test payment flows without hitting the live Moolre API.

## Publishing Checklist

Before tagging a public release:

- Confirm the Moolre API request and response schema with the API/backend owner.
- Run `composer validate --strict --no-check-publish`.
- Run `composer install` and `composer test`.
- Run `composer analyse` and `composer prepublish-check` from a clean, committed working tree with an `origin` remote.
- Run `composer audit` from a machine with working Composer TLS certificates.
- Update `CHANGELOG.md` with the release version and date.
- Tag the release in Git, for example `v1.0.0`.
- Submit or refresh the package on Packagist.

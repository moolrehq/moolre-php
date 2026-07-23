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

Until the package is published to Packagist, install it into an app as a local path repository:

```bash
composer config repositories.moolre path /absolute/path/to/moolre-php
composer require moolre/moolre-php:@dev
```

For development inside this package:

```bash
cd moolre-php
composer install
```

## Example Project

A complete raw PHP demo app is included at [`examples/raw-php-app`](examples/raw-php-app). It shows a cart checkout, payment initiation, webhook callback handling, customer redirect verification, and a small local JSON order store.

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
    'reference' => Client::generateReference('order_1001'),
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
$transaction = $client->verifyPayment($_GET['reference'] ?? '');

// Load the pending order from your database by reference first.
$order = [
    'amount' => '120.00',
    'account_number' => 'your_account_number',
];

if ($transaction->matchesAmountAndAccountNumber(
    $order['amount'],
    $order['account_number']
)) {
    // Mark the order, invoice, wallet top-up, or subscription as paid.
}
```

## Integration Examples

The SDK is framework-agnostic. Each framework only needs to provide configuration, routing, redirects, and your own order update logic.

The status endpoint provides `txstatus`, `externalref`, `amount`, and `accountnumber`; it does not provide currency or customer email. In every webhook and redirect handler, load the local pending order by its reference and require `matchesAmountAndAccountNumber($orderAmount, $accountNumber)` before fulfillment. The abbreviated route examples below show only the transport and routing setup.

```php
$localOrder = $orders->findPendingByReference($reference); // Your database lookup.

if ($localOrder === null || !$transaction->matchesAmountAndAccountNumber(
    (string) $localOrder['amount'],
    $moolreAccountNumber
)) {
    throw new RuntimeException('Payment is not verified for this local order.');
}

// It is now safe to fulfil this local order.
```

### Laravel

```php
use Moolre\Client;

Route::post('/pay', function () {
    $client = new Client(config('services.moolre.public_key'), config('services.moolre.account_number'));

    $payment = $client->initiatePayment([
        'reference' => Client::generateReference('order_' . auth()->id()),
        'email' => auth()->user()->email,
        'amount' => request('amount'),
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
    $transaction = $client->verifyPayment(request('reference'));

    abort_unless($transaction->isSuccessful(), 402);

    // Load the pending local order, then require matchesAmountAndAccountNumber()
    // before updating fulfillment state.
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
    $transaction = $client->verifyPayment(request('reference'));

    abort_unless($transaction->isSuccessful(), 402);

    // Load the pending local order, then require matchesAmountAndAccountNumber()
    // before showing a paid receipt.
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

        $payment = $client->initiatePayment([
            'reference' => Client::generateReference('order_' . $request->request->get('order_id')),
            'email' => $request->request->get('email'),
            'amount' => $request->request->get('amount'),
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
        $transaction = $client->verifyPayment((string) ($payload['reference'] ?? ''));

        if (!$transaction->isSuccessful()) {
            return new Response('Payment not successful.', 402);
        }

        // Load the pending local order, then require matchesAmountAndAccountNumber()
        // before updating fulfillment state.
        return new Response('Payment verified.');
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
        $transaction = $client->verifyPayment((string) $request->query->get('reference'));

        if (!$transaction->isSuccessful()) {
            return new Response('Payment not successful.', 402);
        }

        // Require matchesAmountAndAccountNumber() against the pending local order
        // before rendering a paid receipt.
        return new Response('Payment receipt page.');
    }
}
```

### Slim

```php
use Moolre\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->post('/pay', function (Request $request, Response $response) {
    $data = (array) $request->getParsedBody();
    $client = new Client($_ENV['MOOLRE_PUBLIC_KEY'], $_ENV['MOOLRE_ACCOUNT_NUMBER']);

    $payment = $client->initiatePayment([
        'reference' => Client::generateReference('order_' . ($data['order_id'] ?? '')),
        'email' => $data['email'] ?? '',
        'amount' => $data['amount'] ?? '',
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

$app->post('/moolre/webhook', function (Request $request, Response $response) {
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
    $transaction = $client->verifyPayment((string) ($payload['reference'] ?? ''));

    if (!$transaction->isSuccessful()) {
        $response->getBody()->write('Payment not successful.');
        return $response->withStatus(402);
    }

    // Load the pending local order, then require matchesAmountAndAccountNumber()
    // before updating fulfillment state.
    $response->getBody()->write('Payment verified.');
    return $response;
});

$app->get('/moolre/redirect', function (Request $request, Response $response) {
    $client = new Client(
        $_ENV['MOOLRE_PUBLIC_KEY'],
        $_ENV['MOOLRE_ACCOUNT_NUMBER'],
        null,
        Client::DEFAULT_BASE_URL,
        60,
        false,
        $_ENV['MOOLRE_API_USER']
    );
    $transaction = $client->verifyPayment((string) ($request->getQueryParams()['reference'] ?? ''));

    if (!$transaction->isSuccessful()) {
        $response->getBody()->write('Payment not successful.');
        return $response->withStatus(402);
    }

    // Require matchesAmountAndAccountNumber() against the pending local order
    // before rendering a paid receipt.
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

        $payment = $client->initiatePayment([
            'reference' => Client::generateReference('order_' . $this->request->getPost('order_id')),
            'email' => $this->request->getPost('email'),
            'amount' => $this->request->getPost('amount'),
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
        $transaction = $client->verifyPayment((string) $this->request->getPost('reference'));

        if (!$transaction->isSuccessful()) {
            return $this->response->setStatusCode(402)->setBody('Payment not successful.');
        }

        // Load the pending local order, then require matchesAmountAndAccountNumber()
        // before updating fulfillment state.
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
        $transaction = $client->verifyPayment((string) $this->request->getGet('reference'));

        if (!$transaction->isSuccessful()) {
            return $this->response->setStatusCode(402)->setBody('Payment not successful.');
        }

        // Require matchesAmountAndAccountNumber() against the pending local order
        // before rendering a paid receipt.
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
- `amount`: Positive decimal amount, preferably supplied as a string such as `'120.00'`. Values are normalized to two decimal places; scientific notation and values with more than two decimal places are rejected.
- `currency`: Currency code, currently `GHS`
- `callback`: Fully qualified server callback/webhook URL where Moolre can post payment updates

Optional fields:

- `redirect`: Fully qualified customer return URL after hosted checkout
- `expiration_time`: Payment link expiration time in minutes. Optional; when provided, the minimum is `1` minute.
- `metadata`: Additional merchant metadata to attach to the payment request, such as `customer_id` or `order_id`
- `nonce_value`: Optional value if your app wants to associate a nonce with the payment request
- `tx_source`: Optional transaction source. Defaults to `php-sdk`

Callback and redirect URLs must use `http` or `https`. Use `https` in production.

### `$client->verifyPayment(string $reference): TransactionVerification`

Confirms the payment status for a reference. It sends a JSON `POST` to the configured status URL with `type: 1`, `idtype: '1'`, `id: $reference`, and `accountnumber: $accountNumber`; it includes both `X-API-USER` and `X-API-PUBKEY` headers when the public key is configured. `$apiUser` is mandatory for this method. Moolre must return the original `externalref` (or equivalent reference); if it is missing or does not match the requested reference, the SDK throws `Moolre\Exceptions\ApiException`.

### `$transaction->matchesAmountAndAccountNumber(string $amount, string $accountNumber): bool`

Use this method with Moolre's transaction-status endpoint before delivering value. That endpoint returns `data.txstatus`, `data.externalref`, `data.amount`, and `data.accountnumber`, but does not return currency or customer email. This method requires a successful `txstatus`, the exact normalized amount, and the account number; the client requires `externalref` to match the requested local reference.

### `$transaction->matchesPaymentDetails(string $amount, string $currency, ?string $email = null, ?string $accountNumber = null): bool` — deprecated for Moolre status responses

This strict compatibility helper returns `true` only when a response includes and matches amount, currency, email, and account number. Moolre's transaction-status endpoint does not include the required currency or email fields, so the method correctly returns `false` for that endpoint. Use `matchesAmountAndAccountNumber()` instead.

### `Client::generateReference(string $prefix = 'moolre'): string`

Generates a unique reference that is safe to send to Moolre.

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
- Match the verified reference to a local pending order.
- Validate the final amount and currency against your own database.
- Load a local pending order by the returned reference, then require `matchesAmountAndAccountNumber()` to succeed before delivering value; do not treat a callback or redirect as proof of payment.
- Treat `callback` as the server notification/webhook URL and `redirect` as the customer return page.
- Use HTTPS callback and redirect URLs in production.

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

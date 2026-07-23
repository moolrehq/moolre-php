<?php

declare(strict_types=1);

$composerAutoload = __DIR__ . '/../vendor/autoload.php';

if (file_exists($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Moolre\\';

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        require __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    });
}

use Moolre\Client;
use Moolre\Exceptions\ApiException;
use Moolre\Exceptions\TransportException;
use Moolre\Exceptions\ValidationException;
use Moolre\Http\HttpResponse;
use Moolre\Http\NativeTransport;
use Moolre\Http\TransportInterface;

require __DIR__ . '/../examples/raw-php-app/app/helpers.php';

if (example_strip_inline_comment('true # use false for sandbox') !== 'true') {
    fwrite(STDERR, "Example environment inline comment smoke test failed.\n");
    exit(1);
}

$transport = new class implements TransportInterface {
    /** @var array<int, array<string, mixed>> */
    public $requests = [];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 60): HttpResponse
    {
        $this->requests[] = $payload;

        if (($payload['state'] ?? null) === 'starter') {
            return new HttpResponse(200, '{"status":1,"data":{"authorization_url":"https://checkout.example/pay","reference":"ref_123"}}');
        }

        return new HttpResponse(200, '{"status":1,"data":{"status":1,"reference":"ref_123"}}');
    }
};

$client = new Client('pub_key', 'acct_123', $transport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username');

$payment = $client->initiatePayment([
    'reference' => 'ref_123',
    'email' => 'customer@example.com',
    'amount' => '10.00',
    'currency' => 'GHS',
    'callback' => 'https://example.com/callback',
    'redirect' => 'https://example.com/return',
    'expiration_time' => 5,
    'metadata' => [
        'customer_id' => '1141',
    ],
]);

$verification = $client->verifyPayment('ref_123');

if ($payment->authorizationUrl() !== 'https://checkout.example/pay') {
    fwrite(STDERR, "Payment initiation smoke test failed.\n");
    exit(1);
}

if (!$verification->isSuccessful()) {
    fwrite(STDERR, "Payment verification smoke test failed.\n");
    exit(1);
}

$statusVerification = \Moolre\Responses\TransactionVerification::fromArray([
    'status' => 1,
    'code' => 'SS01',
    'message' => 'Transaction Successful',
    'data' => [
        'txstatus' => 1,
        'txtype' => 1,
        'externalref' => 'ref_123',
        'thirdpartyref' => '85850036850',
        'amount' => '10.00',
        'value' => '10.00',
        'fee' => '0.50',
        'networkfee' => '0.01',
        'accountnumber' => 'acct_123',
        'transactionid' => 50143673,
        'ts' => '2026-07-22 04:09:31',
    ],
]);

if (!$statusVerification->matchesAmountAndAccountNumber('10.00', 'acct_123')) {
    fwrite(STDERR, 'Transaction-status response smoke test failed.' . PHP_EOL);
    exit(1);
}

if ($statusVerification->matchesPaymentDetails('10.00', 'GHS', 'customer@example.com', 'acct_123')) {
    fwrite(STDERR, 'Strict status-response helper smoke test failed.' . PHP_EOL);
    exit(1);
}

if (($transport->requests[1]['type'] ?? null) !== 1
    || ($transport->requests[1]['idtype'] ?? null) !== '1'
    || ($transport->requests[1]['id'] ?? null) !== 'ref_123'
    || ($transport->requests[1]['accountnumber'] ?? null) !== 'acct_123') {
    fwrite(STDERR, 'Verification payload smoke test failed.' . PHP_EOL);
    exit(1);
}

if (($transport->requests[0]['redirect'] ?? '') !== 'https://example.com/return') {
    fwrite(STDERR, "Redirect payload smoke test failed.\n");
    exit(1);
}

if (($transport->requests[0]['expiration_time'] ?? 0) !== 5) {
    fwrite(STDERR, "Expiration payload smoke test failed.\n");
    exit(1);
}

if (($transport->requests[0]['metadata']['customer_id'] ?? '') !== '1141') {
    fwrite(STDERR, "Metadata payload smoke test failed.\n");
    exit(1);
}

$sandboxTransport = new class implements TransportInterface {
    /** @var array<int, array<string, mixed>> */
    public $requests = [];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 60): HttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload,
        ];

        return new HttpResponse(200, '{"status":1,"data":{"authorization_url":"https://sandbox.moolre.com/pay","reference":"ref_123"}}');
    }
};

(new Client('', 'acct_123', $sandboxTransport, Client::SANDBOX_BASE_URL, 60, false, 'merchant_username'))->initiatePayment([
    'reference' => 'ref_123',
    'email' => 'customer@example.com',
    'amount' => '10.00',
    'currency' => 'GHS',
    'callback' => 'https://example.com/callback',
]);

if (($sandboxTransport->requests[0]['headers']['X-API-USER'] ?? '') !== 'merchant_username') {
    fwrite(STDERR, "Sandbox API user header smoke test failed.\n");
    exit(1);
}

if (isset($sandboxTransport->requests[0]['headers']['X-API-PUBKEY'])) {
    fwrite(STDERR, "Sandbox optional public key smoke test failed.\n");
    exit(1);
}

$liveTransport = new class implements TransportInterface {
    /** @var array<int, array<string, mixed>> */
    public $requests = [];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 60): HttpResponse
    {
        $this->requests[] = [
            'headers' => $headers,
        ];

        return new HttpResponse(200, '{"status":1,"data":{"authorization_url":"https://checkout.example/pay","reference":"ref_123"}}');
    }
};

(new Client('pub_key', 'acct_123', $liveTransport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username'))->initiatePayment([
    'reference' => 'ref_123',
    'email' => 'customer@example.com',
    'amount' => '10.00',
    'currency' => 'GHS',
    'callback' => 'https://example.com/callback',
]);

if (isset($liveTransport->requests[0]['headers']['X-API-USER'])) {
    fwrite(STDERR, "Live request sandbox API user header smoke test failed.\n");
    exit(1);
}

try {
    new Client('', 'acct_123', $transport, Client::SANDBOX_BASE_URL);
    fwrite(STDERR, "Sandbox API user validation smoke test failed.\n");
    exit(1);
} catch (ValidationException $exception) {
}

try {
    new Client("pub_key\r\nInjected: value", 'acct_123', $transport);
    fwrite(STDERR, "Header validation smoke test failed.\n");
    exit(1);
} catch (ValidationException $exception) {
}

try {
    new Client('pub_key', 'acct_123', $transport, 'http://localhost/moolre');
    fwrite(STDERR, "HTTPS base URL validation smoke test failed.\n");
    exit(1);
} catch (ValidationException $exception) {
}

try {
    new Client('pub_key', 'acct_123', $transport, 'ftp://example.com/moolre', 60, true);
    fwrite(STDERR, 'Base URL scheme validation smoke test failed.' . PHP_EOL);
    exit(1);
} catch (ValidationException $exception) {
}

try {
    (new NativeTransport(null, true))->postJson('ftp://example.com/moolre', [], [], 1);
    fwrite(STDERR, 'Native transport URL validation smoke test failed.' . PHP_EOL);
    exit(1);
} catch (TransportException $exception) {
}

try {
    $client->initiatePayment([
        'reference' => 'ref_123',
        'email' => 'customer@example.com',
        'amount' => '1e3',
        'currency' => 'GHS',
        'callback' => 'https://example.com/callback',
    ]);
    fwrite(STDERR, 'Amount validation smoke test failed.' . PHP_EOL);
    exit(1);
} catch (ValidationException $exception) {
}

try {
    $client->initiatePayment([
        'reference' => 'ref_123',
        'email' => 'customer@example.com',
        'amount' => '10.00',
        'currency' => 'GHS',
        'callback' => 'ftp://example.com/callback',
    ]);
    fwrite(STDERR, "Callback URL validation smoke test failed.\n");
    exit(1);
} catch (ValidationException $exception) {
}

$mismatchTransport = new class implements TransportInterface {
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 60): HttpResponse
    {
        return new HttpResponse(200, '{"status":1,"data":{"status":1,"reference":"different_ref"}}');
    }
};

try {
    (new Client('pub_key', 'acct_123', $mismatchTransport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username'))->verifyPayment('ref_123');
    fwrite(STDERR, "Verification reference mismatch smoke test failed.\n");
    exit(1);
} catch (ApiException $exception) {
}

$detailTransport = new class implements TransportInterface {
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 60): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'status' => 1,
            'data' => [
                'status' => 1,
                'reference' => 'ref_123',
                'amount' => '10.00',
                'currency' => 'GHS',
                'email' => 'customer@example.com',
                'accountnumber' => 'acct_123',
            ],
        ]));
    }
};

$detailVerification = (new Client('pub_key', 'acct_123', $detailTransport, Client::DEFAULT_BASE_URL, 60, false, 'merchant_username'))->verifyPayment('ref_123');

if (!$detailVerification->matchesPaymentDetails('10.00', 'GHS', 'customer@example.com', 'acct_123')) {
    fwrite(STDERR, 'Verified payment detail matching smoke test failed.' . PHP_EOL);
    exit(1);
}

if ($detailVerification->matchesPaymentDetails('9.00', 'GHS', 'customer@example.com', 'acct_123')) {
    fwrite(STDERR, 'Verified payment mismatch smoke test failed.' . PHP_EOL);
    exit(1);
}

echo "Moolre PHP SDK smoke test passed.\n";

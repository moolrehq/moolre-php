<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Moolre\Client;
use Moolre\Exceptions\MoolreException;

$isProduction = filter_var(getenv('MOOLRE_PRODUCTION') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$baseUrl = getenv('MOOLRE_BASE_URL') ?: ($isProduction ? Client::DEFAULT_BASE_URL : Client::SANDBOX_BASE_URL);

$client = new Client(
    (string) getenv('MOOLRE_PUBLIC_KEY'),
    (string) getenv('MOOLRE_ACCOUNT_NUMBER'),
    null,
    $baseUrl,
    60,
    false,
    getenv('MOOLRE_API_USER') ?: null
);

$reference = Client::generateReference('order_1001');

try {
    
    $payment = $client->initiatePayment([
        'reference' => $reference,
        'email' => 'customer@example.com',
        'amount' => 25.50,
        'currency' => 'GHS',
        'callback' => 'https://example.com/moolre/webhook.php',
        'redirect' => 'https://example.com/moolre/receipt.php',
        'expiration_time' => 10,
        'metadata' => [
            'order_id' => '1001',
            'customer_id' => '1141',
        ],
    ]);

    header('Location: ' . $payment->authorizationUrl(), true, 302);
    exit;
} catch (MoolreException $exception) {
    http_response_code(400);
    echo 'Unable to initiate payment: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}

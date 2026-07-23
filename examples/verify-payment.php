<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Moolre\Client;
use Moolre\Exceptions\MoolreException;

$reference = isset($_GET['reference']) ? trim((string) $_GET['reference']) : '';

if ($reference === '') {
    http_response_code(400);
    echo 'Missing payment reference.';
    exit;
}

$isProduction = filter_var(getenv('MOOLRE_PRODUCTION') ?: 'true', FILTER_VALIDATE_BOOLEAN);
$baseUrl = getenv('MOOLRE_BASE_URL') ?: ($isProduction ? Client::DEFAULT_BASE_URL : Client::SANDBOX_BASE_URL);
$verificationUrl = getenv('MOOLRE_VERIFICATION_URL') ?: ($isProduction ? Client::DEFAULT_VERIFICATION_URL : Client::SANDBOX_VERIFICATION_URL);

try {
    $client = new Client(
        (string) getenv('MOOLRE_PUBLIC_KEY'),
        (string) getenv('MOOLRE_ACCOUNT_NUMBER'),
        null,
        $baseUrl,
        60,
        false,
        getenv('MOOLRE_API_USER') ?: null,
        $verificationUrl
    );
    $transaction = $client->verifyPayment($reference);

    if ($transaction->isSuccessful()) {
        // Before fulfillment, load the local pending order and require
        // matchesAmountAndAccountNumber($orderAmount, $accountNumber).
        echo 'Moolre reports a successful payment for reference ' . htmlspecialchars((string) $transaction->reference(), ENT_QUOTES, 'UTF-8');
        exit;
    }

    http_response_code(402);
    echo 'Payment is not successful yet.';
} catch (MoolreException $exception) {
    http_response_code(400);
    echo 'Unable to verify payment: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}

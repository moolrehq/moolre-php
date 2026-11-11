<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Moolre\Client;
use Moolre\Exceptions\MoolreException;

$reference = isset($_GET['reference']) ? trim((string) $_GET['reference']) : '';
$expectedAmount = trim((string) getenv('MOOLRE_EXPECTED_AMOUNT'));

if ($reference === '' || $expectedAmount === '') {
    http_response_code(400);
    echo 'Missing payment reference or expected local order amount.';
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
    // In a real integration, load this amount from the pending local order.
    $transaction = $client->verifySuccessfulPayment($reference, $expectedAmount);
    echo 'Moolre verified the expected payment for reference ' . htmlspecialchars((string) $transaction->reference(), ENT_QUOTES, 'UTF-8');
} catch (MoolreException $exception) {
    error_log('Moolre verification failed: ' . $exception->getMessage());
    http_response_code(402);
    echo 'Unable to verify the expected payment.';
}

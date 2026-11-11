<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use Moolre\Client;
use Moolre\Exceptions\MoolreException;

/**
 * @param array<string, mixed> $body
 */
function webhook_response(int $statusCode, array $body): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_PRETTY_PRINT);
    exit;
}

/**
 * @param array<string, mixed> $payload
 */
function webhook_reference(array $payload): string
{
    $candidates = [
        $payload['reference'] ?? null,
        $payload['transaction_reference'] ?? null,
        $payload['tx_reference'] ?? null,
    ];

    foreach (['data', 'transaction', 'payment'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            $candidates[] = $payload[$key]['reference'] ?? null;
            $candidates[] = $payload[$key]['transaction_reference'] ?? null;
            $candidates[] = $payload[$key]['tx_reference'] ?? null;
        }
    }

    foreach ($candidates as $candidate) {
        if (!is_scalar($candidate)) {
            continue;
        }

        $reference = trim((string) $candidate);

        if ($reference !== '') {
            return $reference;
        }
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhook_response(405, [
        'status' => 'error',
        'message' => 'Callback endpoint accepts POST requests only.',
    ]);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 65536) {
    webhook_response(413, [
        'status' => 'error',
        'message' => 'Callback payload is too large.',
    ]);
}

$rawBody = file_get_contents('php://input', false, null, 0, 65537) ?: '';

if (strlen($rawBody) > 65536) {
    webhook_response(413, [
        'status' => 'error',
        'message' => 'Callback payload is too large.',
    ]);
}

$decoded = json_decode($rawBody, true);
$payload = is_array($decoded) ? $decoded : $_POST;
$reference = webhook_reference($payload);

if ($reference === '') {
    webhook_response(400, [
        'status' => 'error',
        'message' => 'Payment reference is required.',
    ]);
}

$localOrder = $orders->findByReference($reference);

if ($localOrder === null) {
    webhook_response(404, [
        'status' => 'error',
        'message' => 'Reference does not match a local pending order.',
    ]);
}

if (($localOrder['status'] ?? '') === 'paid') {
    webhook_response(200, [
        'status' => 'ok',
        'payment_status' => 'paid',
    ]);
}

if (($localOrder['status'] ?? '') !== 'pending') {
    webhook_response(409, [
        'status' => 'error',
        'message' => 'Order is not awaiting payment.',
    ]);
}

try {
    $client = new Client($config['public_key'], $config['account_number'], null, $config['base_url'], 60, false, $config['api_user'], $config['verification_url']);
    $transaction = $client->verifySuccessfulPayment($reference, (string) ($localOrder['amount'] ?? ''));
    $transition = $orders->markPaidIfPending($reference, [
        'moolre_data' => safe_transaction_data($transaction),
    ]);

    if ($transition['order'] === null) {
        webhook_response(404, [
            'status' => 'error',
            'message' => 'Reference does not match a local order.',
        ]);
    }

    if (($transition['order']['status'] ?? '') !== 'paid') {
        webhook_response(409, [
            'status' => 'error',
            'message' => 'Order is no longer awaiting payment.',
        ]);
    }

    webhook_response(200, [
        'status' => 'ok',
        'payment_status' => 'paid',
    ]);
} catch (MoolreException $exception) {
    error_log('Moolre callback verification failed: ' . $exception->getMessage());

    webhook_response(503, [
        'status' => 'error',
        'message' => 'Payment verification is temporarily unavailable.',
    ]);
}

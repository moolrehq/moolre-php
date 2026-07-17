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

$rawBody = file_get_contents('php://input') ?: '';
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

try {
    $client = new Client($config['public_key'], $config['account_number'], null, $config['base_url'], 60, false, $config['api_user'], $config['verification_url']);
    $transaction = $client->verifyPayment($reference);
    $status = verified_payment_matches_order($localOrder, $config, $transaction) ? 'paid' : 'pending';

    $orders->updateByReference($reference, [
        'status' => $status,
        'moolre_data' => $transaction->data(),
        'callback_payload' => $payload,
    ]);

    webhook_response(200, [
        'status' => 'ok',
        'payment_status' => $status,
        'reference' => $transaction->reference() ?: $reference,
    ]);
} catch (MoolreException $exception) {
    $orders->updateByReference($reference, [
        'status' => 'pending',
        'error' => $exception->getMessage(),
        'callback_payload' => $payload,
    ]);

    webhook_response(503, [
        'status' => 'error',
        'message' => $exception->getMessage(),
    ]);
}

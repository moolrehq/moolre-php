<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use Moolre\Client;
use Moolre\Exceptions\MoolreException;

require_post();
require_valid_csrf();

if (missing_credentials($config)) {
    render_page('Missing credentials', static function (): void {
        ?>
        <section class="result result-warning">
            <h1>Moolre credentials are missing.</h1>
            <p>Set <code>MOOLRE_ACCOUNT_NUMBER</code> and <code>MOOLRE_API_USER</code>; live mode also requires <code>MOOLRE_PUBLIC_KEY</code>.</p>
            <a class="button-link" href="/">Back to checkout</a>
        </section>
        <?php
    });
    exit;
}

$orderId = trim((string) ($_POST['order_id'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$cartLines = demo_cart_from_payload((string) ($_POST['cart'] ?? ''));
$amount = demo_cart_total($cartLines);

if ($orderId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $cartLines === [] || $amount <= 0) {
    render_page('Invalid payment details', static function (): void {
        ?>
        <section class="result result-warning">
            <h1>Check the payment details.</h1>
            <p>Order ID, valid customer email, and at least one valid cart item are required.</p>
            <a class="button-link" href="/">Back to checkout</a>
        </section>
        <?php
    });
    exit;
}

$reference = Client::generateReference('checkout');
$orders->create([
    'order_id' => $orderId,
    'reference' => $reference,
    'email' => $email,
    'amount' => number_format($amount, 2, '.', ''),
    'currency' => $config['currency'],
    'items' => $cartLines,
    'status' => 'pending',
]);
remember_checkout_reference($reference);

try {
    $client = new Client($config['public_key'], $config['account_number'], null, $config['base_url'], 60, false, $config['api_user'], $config['verification_url']);
    $payment = $client->initiatePayment([
        'reference' => $reference,
        'email' => $email,
        'amount' => number_format($amount, 2, '.', ''),
        'currency' => $config['currency'],
        'callback' => app_url($config, 'callback.php'),
        'redirect' => app_url($config, 'redirect.php'),
        'expiration_time' => $config['expiration_time'],
        'metadata' => [
            'order_id' => $orderId,
            'customer_id' => 'demo-customer-1001',
            'cart_items' => count($cartLines),
        ],
        'tx_source' => 'raw-php-example',
    ]);

    $orders->updateByReference($reference, [
        'authorization_url' => $payment->authorizationUrl(),
    ]);

    redirect_to($payment->authorizationUrl());
} catch (MoolreException $exception) {
    error_log('Moolre payment initiation failed: ' . $exception->getMessage());
    $orders->updateByReference($reference, [
        'status' => 'failed',
        'error' => 'Payment initiation failed.',
    ]);

    render_page('Payment could not start', static function (): void {
        ?>
        <section class="result result-danger">
            <h1>Payment could not start.</h1>
            <p>Please try again or contact the merchant if the problem continues.</p>
            <a class="button-link" href="/">Back to checkout</a>
        </section>
        <?php
    });
}

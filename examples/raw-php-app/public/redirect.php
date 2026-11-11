<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use Moolre\Client;
use Moolre\Exceptions\MoolreException;

$reference = trim((string) ($_GET['reference'] ?? ''));

if ($reference === '') {
    render_page('Missing reference', static function (): void {
        ?>
        <section class="result result-warning">
            <h1>Missing payment reference.</h1>
            <p>Moolre should return the customer with a <code>reference</code> query parameter.</p>
            <a class="button-link" href="/">Back to checkout</a>
        </section>
        <?php
    });
    exit;
}

if (!owns_checkout_reference($reference)) {
    render_page('Unable to show receipt', static function (): void {
        ?>
        <section class="result result-warning">
            <h1>Receipt unavailable.</h1>
            <p>This payment was not started from the current browser session.</p>
            <a class="button-link" href="/">Back to checkout</a>
        </section>
        <?php
    });
    exit;
}

$localOrder = $orders->findByReference($reference);

if ($localOrder === null) {
    render_page('Unknown reference', static function () use ($reference): void {
        ?>
        <section class="result result-warning">
            <h1>Unknown payment reference.</h1>
            <p>Reference <code><?= e($reference) ?></code> does not match a local pending demo order.</p>
            <a class="button-link" href="/">Back to checkout</a>
        </section>
        <?php
    });
    exit;
}

try {
    $order = $localOrder;
    $status = (string) ($order['status'] ?? 'pending');

    if ($status === 'pending') {
        $client = new Client($config['public_key'], $config['account_number'], null, $config['base_url'], 60, false, $config['api_user'], $config['verification_url']);
        $transaction = $client->verifySuccessfulPayment($reference, (string) ($localOrder['amount'] ?? ''));
        $transition = $orders->markPaidIfPending($reference, [
            'moolre_data' => safe_transaction_data($transaction),
        ]);
        $order = $transition['order'] ?: $localOrder;
        $status = (string) ($order['status'] ?? 'pending');
    }

    render_page('Payment result', static function () use ($order, $reference, $status): void {
        $isPaid = $status === 'paid';
        $verifiedReference = $reference;
        ?>
        <section class="result-shell">
            <article class="result-card <?= $isPaid ? 'result-success' : 'result-danger' ?>">
                <div class="result-top">
                    <span class="result-mark" aria-hidden="true"><?= $isPaid ? '&#10003;' : '!' ?></span>
                    <div>
                        <?php render_status_badge($status); ?>
                        <h1><?= $isPaid ? 'Payment completed' : 'Payment not completed' ?></h1>
                        <p>
                            Reference <code><?= e($verifiedReference) ?></code>
                            was verified directly with Moolre before the local order was updated.
                        </p>
                    </div>
                </div>

                <?php if ($order !== null): ?>
                    <dl class="receipt">
                        <div class="receipt-row">
                            <dt>Order ID</dt>
                            <dd><?= e((string) ($order['order_id'] ?? '')) ?></dd>
                        </div>
                        <div class="receipt-row">
                            <dt>Amount</dt>
                            <dd><?= e(format_money($order['amount'] ?? 0, (string) ($order['currency'] ?? 'GHS'))) ?></dd>
                        </div>
                        <div class="receipt-row">
                            <dt>Customer</dt>
                            <dd><?= e(mask_email((string) ($order['email'] ?? ''))) ?></dd>
                        </div>
                        <div class="receipt-row">
                            <dt>Reference</dt>
                            <dd><code><?= e($verifiedReference) ?></code></dd>
                        </div>
                    </dl>

                    <?php if (isset($order['items']) && is_array($order['items']) && $order['items'] !== []): ?>
                        <section class="receipt-items" aria-label="Paid items">
                            <h2>Items paid</h2>
                            <?php foreach ($order['items'] as $item): ?>
                                <div class="receipt-item">
                                    <span>
                                        <?= e((string) ($item['name'] ?? 'Item')) ?>
                                        <small>x<?= e((string) ($item['quantity'] ?? 1)) ?></small>
                                    </span>
                                    <strong><?= e(format_money($item['total'] ?? 0, (string) ($order['currency'] ?? 'GHS'))) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </section>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="actions">
                    <a class="button-link" href="/">New payment</a>
                    <?php if (can_view_demo_orders()): ?>
                        <a class="text-link" href="/orders.php">View local demo orders</a>
                    <?php endif; ?>
                </div>
            </article>
        </section>
        <?php
    });
} catch (MoolreException $exception) {
    error_log('Moolre redirect verification failed: ' . $exception->getMessage());

    render_page('Verification failed', static function () use ($reference): void {
        ?>
        <section class="result result-danger">
            <h1>Verification failed.</h1>
            <p>Reference <code><?= e($reference) ?></code> could not be verified.</p>
            <p>Please refresh shortly. If the payment completed, the server callback can still update the order safely.</p>
            <a class="button-link" href="/">Back to checkout</a>
        </section>
        <?php
    });
}

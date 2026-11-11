<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

if (!can_view_demo_orders()) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$allOrders = $orders->recent(50);

render_page('Orders', static function () use ($allOrders): void {
    ?>
    <section class="page-heading">
        <div>
            <p class="kicker">Local demo store</p>
            <h1>Orders</h1>
        </div>
        <a class="button-link" href="/">New payment</a>
    </section>

    <?php if ($allOrders === []): ?>
        <section class="panel">
            <p class="empty">No demo orders have been created yet.</p>
        </section>
    <?php else: ?>
        <section class="panel table-panel">
            <table>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allOrders as $order): ?>
                        <tr>
                            <td><?= e((string) ($order['order_id'] ?? '')) ?></td>
                            <td><code><?= e(mask_reference((string) ($order['reference'] ?? ''))) ?></code></td>
                            <td><?= e(mask_email((string) ($order['email'] ?? ''))) ?></td>
                            <td><?= e(format_money($order['amount'] ?? 0, (string) ($order['currency'] ?? 'GHS'))) ?></td>
                            <td><?php render_status_badge((string) ($order['status'] ?? 'pending')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
    <?php
});

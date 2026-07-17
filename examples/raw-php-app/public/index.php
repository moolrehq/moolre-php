<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$hasMissingCredentials = missing_credentials($config);
$products = demo_products();
$defaultCart = demo_default_cart();
$cartLines = demo_cart_lines($defaultCart);
$cartTotal = demo_cart_total($cartLines);

render_page('Checkout', function () use ($config, $hasMissingCredentials, $products, $defaultCart, $cartLines, $cartTotal): void {
    $cartPayload = json_encode($defaultCart, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG);
    $productPayload = json_encode($products, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG);
    ?>
    <section class="hero checkout-hero">
        <div>
        <h1>Framework-agnostic Moolre checkout, demonstrated in plain PHP.</h1>
            <p class="lede">
                A complete cart flow that calculates the order total, creates a local
                pending order, redirects to Moolre, and verifies the returned reference.
            </p>
        </div>
    </section>

    <?php if ($hasMissingCredentials): ?>
        <div class="notice" role="status">
            Set <code>MOOLRE_ACCOUNT_NUMBER</code> and <code>MOOLRE_API_USER</code>;
            live mode also requires <code>MOOLRE_PUBLIC_KEY</code>.
        </div>
    <?php endif; ?>

    <section class="checkout-layout">
        <form
            class="panel checkout-form checkout-card"
            method="post"
            action="/pay.php"
            data-cart-form
            data-currency="<?= e($config['currency']) ?>"
        >
            <input type="hidden" name="cart" value="<?= e($cartPayload) ?>" data-cart-payload>
            <input type="hidden" name="amount" value="<?= e(number_format($cartTotal, 2, '.', '')) ?>" data-cart-amount>

            <div class="section-heading">
                <span class="section-icon" aria-hidden="true">+</span>
                <div>
                    <h2>Cart checkout</h2>
                    <p>Add or remove products and the Moolre payment amount updates automatically.</p>
                </div>
            </div>

            <section class="cart-section" aria-label="Cart items">
                <div class="cart-list" data-cart-list>
                    <?php foreach ($cartLines as $line): ?>
                        <article class="cart-item" data-cart-item="<?= e($line['id']) ?>">
                            <img src="<?= e($line['image']) ?>" alt="<?= e($line['name']) ?>">
                            <div class="cart-copy">
                                <strong><?= e($line['name']) ?></strong>
                                <span><?= e($line['description']) ?></span>
                            </div>
                            <div class="cart-price">
                                <strong><?= e(format_money($line['total'], $config['currency'])) ?></strong>
                                <span><?= e(format_money($line['unit_price'], $config['currency'])) ?> each</span>
                            </div>
                            <div class="quantity-control" aria-label="<?= e($line['name']) ?> quantity">
                                <button type="button" class="icon-button" data-cart-decrease aria-label="Decrease <?= e($line['name']) ?>">-</button>
                                <span data-cart-quantity><?= e((string) $line['quantity']) ?></span>
                                <button type="button" class="icon-button" data-cart-increase aria-label="Increase <?= e($line['name']) ?>">+</button>
                            </div>
                            <button type="button" class="remove-button" data-cart-remove>Remove</button>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="add-item-row">
                    <label for="product-to-add">Add product</label>
                    <div class="input-row">
                        <select id="product-to-add" data-cart-add-select>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= e($product['id']) ?>">
                                    <?= e($product['name']) ?> - <?= e(format_money($product['price'], $config['currency'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="secondary-button" data-cart-add>Add item</button>
                    </div>
                </div>
            </section>

            <section class="customer-section" aria-label="Customer details">
                <div class="customer-grid">
                    <label>
                        Order ID
                        <input name="order_id" value="1001" required>
                    </label>

                    <label>
                        Customer email
                        <input type="email" name="email" value="customer@example.com" required>
                    </label>
                </div>
            </section>

            <div class="checkout-summary">
                <div>
                    <span>Total due</span>
                    <strong data-cart-total><?= e(format_money($cartTotal, $config['currency'])) ?></strong>
                </div>
                <button type="submit" data-cart-submit <?= $hasMissingCredentials ? 'disabled' : '' ?>>
                    Pay <?= e(format_money($cartTotal, $config['currency'])) ?> <span aria-hidden="true">-></span>
                </button>
            </div>
        </form>

        <aside class="panel flow-panel" aria-label="Payment flow">
            <div class="section-heading">
                <span class="section-icon section-icon-muted" aria-hidden="true">✓</span>
                <div>
                    <h2>Protected payment flow</h2>
                    <p>The customer pays through Moolre while your app keeps control of the order record.</p>
                </div>
            </div>

            <div class="trust-list" aria-label="Checkout safeguards">
                <span>Hosted Moolre checkout</span>
                <span>Server-side total check</span>
                <span>Webhook and redirect URLs</span>
            </div>

            <ol class="flow-list">
                <li>
                    <span class="flow-number">1</span>
                    <div>
                        <strong>Create a pending order</strong>
                        <p>The app stores the local order before requesting a hosted checkout link.</p>
                    </div>
                </li>
                <li>
                    <span class="flow-number">2</span>
                    <div>
                        <strong>Redirect to Moolre</strong>
                        <p>The customer completes payment on the Moolre checkout experience.</p>
                    </div>
                </li>
                <li>
                    <span class="flow-number">3</span>
                    <div>
                        <strong>Verify before fulfillment</strong>
                        <p>The webhook and return page verify the reference before marking the order paid.</p>
                    </div>
                </li>
            </ol>
        </aside>
    </section>
    <script type="application/json" id="checkout-products"><?= $productPayload ?></script>
    <script src="/assets/checkout.js" defer></script>
    <?php
});

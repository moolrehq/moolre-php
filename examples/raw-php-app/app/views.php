<?php

declare(strict_types=1);

/**
 * @param callable(): void $content
 */
function render_page(string $title, callable $content): void
{
    $currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $isCheckout = $currentPath === '/' || $currentPath === '/index.php';
    $isOrders = $currentPath === '/orders.php';

    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - Moolre Raw PHP Example</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="/">
            <span class="brand-mark">M</span>
            <span>
                <strong>Moolre PHP</strong>
                <small>Raw PHP example</small>
            </span>
        </a>
        <nav aria-label="Example navigation">
            <a href="/" class="<?= $isCheckout ? 'is-active' : '' ?>">Checkout</a>
            <a href="/orders.php" class="<?= $isOrders ? 'is-active' : '' ?>">Orders</a>
        </nav>
    </header>

    <main class="shell">
        <?php $content(); ?>
    </main>
</body>
</html>
<?php
}

function render_status_badge(string $status): void
{
    $class = 'badge';

    if ($status === 'paid') {
        $class .= ' badge-success';
    } elseif ($status === 'failed') {
        $class .= ' badge-danger';
    }

    echo '<span class="' . e($class) . '">' . e(ucfirst($status)) . '</span>';
}

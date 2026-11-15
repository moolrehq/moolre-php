<?php

declare(strict_types=1);

// Builds a single-file bundle of the server SDK (everything except the legacy hosted-checkout Client):
//   php scripts/bundle.php [output]     default: dist/moolre-sdk.php
// Usage in an app without Composer:  require 'moolre-sdk.php';  $moolre = new Moolre\Moolre([...]);

$root = dirname(__DIR__) . '/src';
$out = $argv[1] ?? dirname(__DIR__) . '/dist/moolre-sdk.php';

// Order matters: parents before children.
$files = [
    'Exceptions/MoolreException.php',
    'Exceptions/ConfigurationError.php',
    'Exceptions/NetworkError.php',
    'Exceptions/InvalidResponseError.php',
    'Exceptions/WebhookSignatureError.php',
    'Result.php',
    'Exceptions/ApiError.php',
    'Exceptions/AuthenticationError.php',
    'Exceptions/NotFoundError.php',
    'Exceptions/ValidationError.php',
    'Exceptions/IdempotencyError.php',
    'Exceptions/RateLimitError.php',
    'Exceptions/ServerError.php',
    'Transport.php',
    'WebhookEvent.php',
    'Webhook.php',
    'Api/Resource.php',
    'Api/Payments.php',
    'Api/PaymentLinks.php',
    'Api/Transfers.php',
    'Api/Accounts.php',
    'Api/Sales.php',
    'Api/Sms.php',
    'Api/WhatsApp.php',
    'Api/Reference.php',
    'Api/Bulk.php',
    'Api/Invoices.php',
    'Api/Webhooks.php',
    'Moolre.php',
];

$version = null;
$body = '';
foreach ($files as $f) {
    $src = file_get_contents($root . '/' . $f);
    if ($src === false) {
        fwrite(STDERR, "missing $f\n");
        exit(1);
    }
    $src = preg_replace('/^<\?php\s*/', '', $src);
    $src = preg_replace('/^declare\(strict_types=1\);\s*/m', '', $src, 1);
    if (!preg_match('/^namespace\s+([^;]+);\s*/m', $src, $m)) {
        fwrite(STDERR, "no namespace in $f\n");
        exit(1);
    }
    $src = preg_replace('/^namespace\s+[^;]+;\s*/m', '', $src, 1);
    $src = trim($src);
    if ($f === 'Exceptions/MoolreException.php') {
        // Shared with the legacy package: declare only once if both are loaded.
        $src = "if (!class_exists('Moolre\\\\Exceptions\\\\MoolreException', false)) {\n" . $src . "\n}";
    }
    if ($f === 'Transport.php' && preg_match("/VERSION = '([^']+)'/", $src, $v)) {
        $version = $v[1];
    }
    $body .= "namespace {$m[1]} {\n\n" . $src . "\n\n}\n\n";
}

$header = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Moolre PHP SDK " . $version . " - single-file bundle (server SDK only, requires PHP 7.4+, ext-curl, ext-json).\n * https://docs.moolre.com/sdks\n */\n\n";
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}
file_put_contents($out, $header . $body);
echo "wrote $out (" . strlen($header . $body) . " bytes)\n";

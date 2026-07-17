<?php

declare(strict_types=1);

require __DIR__ . '/helpers.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/OrderStore.php';
require __DIR__ . '/views.php';

example_load_env_file(__DIR__ . '/../.env');

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$autoloadLoaded = false;

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    http_response_code(500);
    echo 'Composer autoload file not found. Run "composer install" in examples/raw-php-app or in the SDK root.';
    exit;
}

session_start();

$config = require __DIR__ . '/config.php';
$orders = new OrderStore(__DIR__ . '/../storage/orders.json');

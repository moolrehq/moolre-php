<?php

declare(strict_types=1);

$composerAutoload = __DIR__ . '/../vendor/autoload.php';

if (file_exists($composerAutoload)) {
    require $composerAutoload;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Moolre\\Tests\\' => __DIR__ . '/',
        'Moolre\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (strpos($class, $prefix) !== 0) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $directory . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($path)) {
            require $path;
        }

        return;
    }
});

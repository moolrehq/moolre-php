<?php

declare(strict_types=1);

function example_env(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return trim((string) $value);
}

function example_env_bool(string $key, bool $default = false): bool
{
    $value = getenv($key);

    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function example_load_env_file(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = example_strip_inline_comment($value);
        $value = trim($value, "\"'");

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function example_strip_inline_comment(string $value): string
{
    $quote = null;
    $length = strlen($value);

    for ($index = 0; $index < $length; $index++) {
        $character = $value[$index];

        if ($quote !== null) {
            if ($character === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($character === '"' || $character === "'") {
            $quote = $character;
            continue;
        }

        if ($character === '#' && ($index === 0 || $value[$index - 1] === ' ' || $value[$index - 1] === "\t")) {
            return rtrim(substr($value, 0, $index));
        }
    }

    return trim($value);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string, mixed> $config
 */
function app_url(array $config, string $path = ''): string
{
    return rtrim($config['app_url'], '/') . '/' . ltrim($path, '/');
}

function format_money($amount, string $currency): string
{
    return $currency . ' ' . number_format((float) $amount, 2);
}

function redirect_to(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed.';
        exit;
    }
}

/**
 * @param array<string, mixed> $config
 */
function missing_credentials(array $config): bool
{
    if (($config['account_number'] ?? '') === '') {
        return true;
    }

    if (($config['api_user'] ?? '') === '') {
        return true;
    }

    return !is_sandbox_config($config) && ($config['public_key'] ?? '') === '';
}

/**
 * @param array<string, mixed> $config
 */
function is_sandbox_config(array $config): bool
{
    $host = strtolower((string) parse_url((string) ($config['base_url'] ?? ''), PHP_URL_HOST));

    return $host === 'sandbox.moolre.com';
}

/**
 * @param array<string, mixed> $order
 * @param array<string, mixed> $config
 */
function verified_payment_matches_order(array $order, array $config, \Moolre\Responses\TransactionVerification $transaction): bool
{
    return $transaction->matchesAmountAndAccountNumber(
        (string) ($order['amount'] ?? ''),
        (string) ($config['account_number'] ?? '')
    );
}

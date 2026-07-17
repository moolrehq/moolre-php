<?php

declare(strict_types=1);

$isProduction = example_env_bool('MOOLRE_PRODUCTION', false);
$defaultBaseUrl = $isProduction ? \Moolre\Client::DEFAULT_BASE_URL : \Moolre\Client::SANDBOX_BASE_URL;
$defaultVerificationUrl = $isProduction ? \Moolre\Client::DEFAULT_VERIFICATION_URL : \Moolre\Client::SANDBOX_VERIFICATION_URL;

return [
    'production' => $isProduction,
    'public_key' => example_env('MOOLRE_PUBLIC_KEY'),
    'account_number' => example_env('MOOLRE_ACCOUNT_NUMBER'),
    'api_user' => example_env('MOOLRE_API_USER'),
    'base_url' => example_env('MOOLRE_BASE_URL', $defaultBaseUrl),
    'verification_url' => example_env('MOOLRE_VERIFICATION_URL', $defaultVerificationUrl),
    'currency' => example_env('MOOLRE_CURRENCY', 'GHS'),
    'app_url' => rtrim(example_env('APP_URL', 'http://localhost:8080'), '/'),
    'expiration_time' => (int) example_env('MOOLRE_EXPIRATION_TIME', '10'),
];

<?php

declare(strict_types=1);

/**
 * @return array{0: int, 1: string}
 */
function run_git(string $arguments): array
{
    $output = [];
    $status = 0;

    exec('git ' . $arguments . ' 2>&1', $output, $status);

    return [$status, trim(implode(PHP_EOL, $output))];
}

$errors = [];

[$status, $output] = run_git('rev-parse --verify HEAD');

if ($status !== 0) {
    $errors[] = 'Create the first Git commit before publishing.';
}

[$status, $output] = run_git('remote get-url origin');

if ($status !== 0 || $output === '') {
    $errors[] = 'Configure the origin remote for the public package repository.';
}

[$status, $output] = run_git('status --porcelain');

if ($status !== 0) {
    $errors[] = 'Git status could not be read: ' . $output;
} elseif ($output !== '') {
    $errors[] = 'Commit or remove all working-tree changes before creating a release tag.';
}

if (file_exists(__DIR__ . '/../examples/raw-php-app/.env')) {
    [$status, $output] = run_git('check-ignore --quiet -- examples/raw-php-app/.env');

    if ($status === 1) {
        $errors[] = 'examples/raw-php-app/.env must remain ignored.';
    } elseif ($status !== 0) {
        $errors[] = 'Git ignore rules could not be read: ' . $output;
    }
}

if ($errors !== []) {
    fwrite(STDERR, 'Pre-publish checks failed:' . PHP_EOL);

    foreach ($errors as $error) {
        fwrite(STDERR, '- ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'Pre-publish checks passed.' . PHP_EOL;

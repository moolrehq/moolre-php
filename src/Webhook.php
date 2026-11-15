<?php

declare(strict_types=1);

namespace Moolre;

use Moolre\Exceptions\WebhookSignatureError;

/**
 * Webhook signature helpers (Contract W).
 *
 *   X-Moolre-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 *
 * Always pass the RAW request body (file_get_contents('php://input')), never re-encoded JSON.
 */
final class Webhook
{
    /** Returns null when valid, otherwise 'malformed' | 'mismatch' | 'timestamp'. */
    public static function check(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSec = 300, ?int $now = null): ?string
    {
        if ($signatureHeader === null || $signatureHeader === '' || $secret === '') {
            return 'malformed';
        }

        $t = null;
        $sigs = [];

        foreach (explode(',', $signatureHeader) as $part) {
            $i = strpos($part, '=');
            if ($i === false) {
                continue;
            }
            $k = trim(substr($part, 0, $i));
            $v = trim(substr($part, $i + 1));
            if ($k === 't' && $t === null) {
                $t = $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }

        if ($t === null || preg_match('/^[0-9]{1,15}$/D', $t) !== 1) {
            return 'malformed';
        }

        $valid = [];
        foreach ($sigs as $s) {
            if (preg_match('/^[0-9a-fA-F]{64}$/D', $s) === 1) {
                $valid[] = $s;
            }
        }
        if ($valid === []) {
            return 'malformed';
        }

        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret, true);
        $match = false;
        foreach ($valid as $s) {
            if (hash_equals($expected, (string) hex2bin($s))) {
                $match = true;
            }
        }
        if (!$match) {
            return 'mismatch';
        }

        $current = $now ?? time();
        if ($toleranceSec > 0 && abs($current - (int) $t) > $toleranceSec) {
            return 'timestamp';
        }

        return null;
    }

    public static function verify(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSec = 300, ?int $now = null): bool
    {
        return self::check($rawBody, $signatureHeader, $secret, $toleranceSec, $now) === null;
    }

    /** Parse a webhook body. Does not verify anything. */
    public static function parse(string $rawBody): WebhookEvent
    {
        $raw = json_decode($rawBody, true);
        if (!is_array($raw) || ($raw !== [] && array_keys($raw) === range(0, count($raw) - 1))) {
            throw new WebhookSignatureError('malformed', 'Webhook body is not a JSON object');
        }

        return new WebhookEvent($raw);
    }

    /** Verify then parse; throws WebhookSignatureError (reason: malformed | mismatch | timestamp). */
    public static function construct(string $rawBody, ?string $signatureHeader, string $secret, int $toleranceSec = 300, ?int $now = null): WebhookEvent
    {
        $failure = self::check($rawBody, $signatureHeader, $secret, $toleranceSec, $now);
        if ($failure !== null) {
            throw new WebhookSignatureError($failure, 'Invalid Moolre webhook signature (' . $failure . ')');
        }

        return self::parse($rawBody);
    }

    /** Test helper: build a header the way Moolre signs. */
    public static function sign(string $rawBody, string $secret, ?int $now = null): string
    {
        $t = $now ?? time();

        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $rawBody, $secret);
    }
}

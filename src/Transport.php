<?php

declare(strict_types=1);

namespace Moolre;

use Moolre\Exceptions\ApiError;
use Moolre\Exceptions\ConfigurationError;
use Moolre\Exceptions\InvalidResponseError;
use Moolre\Exceptions\NetworkError;

/**
 * HTTP layer of the server SDK (curl + json only).
 *
 * @internal Use Moolre\Moolre.
 */
final class Transport
{
    public const VERSION = '1.1.2';
    public const LIVE_URL = 'https://api.moolre.com';
    public const SANDBOX_URL = 'https://sandbox.moolre.com';

    /** @var array<string, mixed> */
    public $opts;
    /** @var string */
    public $baseUrl;
    /** @var array<string, bool> */
    private $seenNotices = [];

    /** @param array<string, mixed> $opts */
    public function __construct(array $opts)
    {
        $env = $opts['environment'] ?? 'live';
        if ($env !== 'live' && $env !== 'sandbox') {
            throw new ConfigurationError('environment must be "live" or "sandbox"');
        }
        if (!function_exists('curl_init')) {
            throw new ConfigurationError('The curl extension is required.');
        }
        $this->opts = $opts;
        $this->baseUrl = rtrim((string) ($opts['baseUrl'] ?? ($env === 'live' ? self::LIVE_URL : self::SANDBOX_URL)), '/');
    }

    /**
     * @param array<string, mixed> $body
     * @param array{method?: string, query?: array, retry?: string, idempotencyKey?: ?string, needs?: string[]} $o
     */
    public function call(string $path, array $body = [], array $o = []): Result
    {
        $opts = $this->opts;
        if (empty($opts['user'])) {
            throw new ConfigurationError('`user` (X-API-USER) is required');
        }
        $needs = $o['needs'] ?? [];
        if ($needs !== []) {
            $has = false;
            foreach ($needs as $n) {
                if (!empty($opts[$n])) {
                    $has = true;
                }
            }
            if (!$has) {
                throw new ConfigurationError('This call needs one of: ' . implode(', ', $needs));
            }
        }

        $method = $o['method'] ?? 'POST';
        $key = $o['idempotencyKey'] ?? null;
        if ($key !== null && preg_match('/^[\x20-\x7e]{1,128}$/D', $key) !== 1) {
            throw new ConfigurationError('idempotencyKey must be 1-128 printable ASCII characters');
        }

        $url = $this->baseUrl . $path;
        $query = self::clean($o['query'] ?? []);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = ['Accept: application/json', 'User-Agent: moolre-php/' . self::VERSION, 'X-API-USER: ' . $opts['user']];
        foreach (['apiKey' => 'X-API-KEY', 'pubKey' => 'X-API-PUBKEY', 'vasKey' => 'X-API-VASKEY'] as $k => $h) {
            if (!empty($opts[$k])) {
                $headers[] = $h . ': ' . $opts[$k];
            }
        }
        if (!empty($opts['strictHttpStatus'])) {
            $headers[] = 'X-Moolre-Http-Status: strict';
        }
        if ($key !== null) {
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        $payload = null;
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $clean = self::clean($body);
            $payload = $clean === [] ? '{}' : json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw new ConfigurationError('Request body could not be encoded as JSON: ' . json_last_error_msg());
            }
        }

        $retry = $o['retry'] ?? 'never';
        $retryable = $retry === 'read' || ($retry === 'idempotent' && $key !== null);
        $maxRetries = $retryable ? (int) ($opts['maxRetries'] ?? 2) : 0;

        for ($attempt = 0;; $attempt++) {
            [$status, $text, $reqId, $err] = $this->send($url, $method, $headers, $payload);

            if ($err !== null) {
                if ($attempt < $maxRetries) {
                    $this->sleep($attempt);
                    continue;
                }
                throw $err;
            }
            if ($status >= 500 && $attempt < $maxRetries) {
                $this->sleep($attempt);
                continue;
            }

            $parsed = json_decode($text, true);
            if (!is_array($parsed) || ($parsed !== [] && array_keys($parsed) === range(0, count($parsed) - 1))) {
                throw new InvalidResponseError(sprintf('Moolre returned a non-JSON response (HTTP %d): %s', $status, substr($text, 0, 200)), $status);
            }

            $result = new Result($parsed, $status, $reqId);
            $this->logNotices($result);
            if (!$result->ok && ($opts['throwOnError'] ?? true)) {
                throw ApiError::fromResult($result);
            }

            return $result;
        }
    }

    /** @return array{0:int,1:string,2:?string,3:?NetworkError} */
    private function send(string $url, string $method, array $headers, ?string $payload): array
    {
        $ch = curl_init($url);
        $reqId = null;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int) ceil((float) ($this->opts['timeoutSec'] ?? 60)),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$reqId): int {
                if (stripos($line, 'x-request-id:') === 0) {
                    $reqId = trim(substr($line, 13));
                }

                return strlen($line);
            },
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        if (!empty($this->opts['caFile'])) {
            $opts[CURLOPT_CAINFO] = $this->opts['caFile'];
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            $msg = $errno === 28 ? 'timed out' : 'failed';

            return [0, '', null, new NetworkError(sprintf('Request to %s %s: %s', parse_url($url, PHP_URL_PATH), $msg, $error))];
        }

        return [$status, (string) $body, $reqId, null];
    }

    private function sleep(int $attempt): void
    {
        $base = (int) ($this->opts['retryDelayMs'] ?? 500);
        usleep((int) ($base * (2 ** $attempt) * (0.75 + mt_rand(0, 500) / 1000) * 1000));
    }

    private function logNotices(Result $result): void
    {
        $logger = array_key_exists('logger', $this->opts) ? $this->opts['logger'] : 'default';
        if ($logger === null || $logger === false) {
            return;
        }
        foreach ($result->notices as $n) {
            $code = is_array($n) ? (string) ($n['code'] ?? '') : '';
            if (isset($this->seenNotices[$code])) {
                continue;
            }
            $this->seenNotices[$code] = true;
            $docs = is_array($n) && !empty($n['docs']) ? ' (' . $n['docs'] . ')' : '';
            $msg = '[moolre] ' . $code . ': ' . (is_array($n) ? (string) ($n['message'] ?? '') : '') . $docs;
            if (is_callable($logger)) {
                $logger($msg, ['requestId' => $result->requestId, 'notice' => $n]);
            } else {
                error_log($msg);
            }
        }
    }

    /**
     * Drop null values (top level).
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    public static function clean(array $a): array
    {
        return array_filter($a, static function ($v) {
            return $v !== null;
        });
    }

    /** @param int|float|string $a */
    public static function amount($a): string
    {
        return (is_int($a) || is_float($a)) ? number_format((float) $a, 2, '.', '') : (string) $a;
    }

    /** @param int|string $c */
    public static function channel($c): string
    {
        $s = trim((string) $c);
        $u = strtoupper($s);

        return in_array($u, ['MTN', 'TELECEL', 'AT', 'BANK'], true) ? $u : $s;
    }
}

<?php

declare(strict_types=1);

namespace Moolre\Http;

use Composer\CaBundle\CaBundle;
use Moolre\Exceptions\TransportException;

final class NativeTransport implements TransportInterface
{
    /** @var string|null */
    private $caFile;

    /** @var bool */
    private $forceStreams;

    public function __construct(?string $caFile = null, bool $forceStreams = false)
    {
        $this->caFile = $caFile ?: $this->caFileFromEnvironment();
        $this->forceStreams = $forceStreams || getenv('MOOLRE_FORCE_STREAMS') === '1';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 60): HttpResponse
    {
        $this->validateUrl($url);

        $body = json_encode($payload);

        if ($body === false) {
            throw new TransportException('Unable to encode Moolre request payload as JSON.');
        }

        if (!$this->forceStreams && function_exists('curl_init')) {
            return $this->postWithCurl($url, $body, $headers, $timeout);
        }

        return $this->postWithStreams($url, $body, $headers, $timeout);
    }

    /**
     * @param array<string, string> $headers
     */
    private function postWithCurl(string $url, string $body, array $headers, int $timeout): HttpResponse
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new TransportException('Unable to initialize cURL.');
        }

        $responseHeaders = [];
        $headerLines = $this->formatHeaders($headers);
        $caPathOrFile = $this->caPathOrFile();

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        if ($caPathOrFile !== null) {
            if (is_dir($caPathOrFile)) {
                curl_setopt($handle, CURLOPT_CAPATH, $caPathOrFile);
            } else {
                curl_setopt($handle, CURLOPT_CAINFO, $caPathOrFile);
            }
        }

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $error = curl_error($handle);
            $errorNumber = curl_errno($handle);
            curl_close($handle);
            throw new TransportException($this->formatCurlError($error, $errorNumber));
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return new HttpResponse($statusCode, (string) $responseBody, $responseHeaders);
    }

    /**
     * @param array<string, string> $headers
     */
    private function postWithStreams(string $url, string $body, array $headers, int $timeout): HttpResponse
    {
        $sslOptions = [];
        $caPathOrFile = $this->caPathOrFile();

        if ($caPathOrFile !== null) {
            if (is_dir($caPathOrFile)) {
                $sslOptions['capath'] = $caPathOrFile;
            } else {
                $sslOptions['cafile'] = $caPathOrFile;
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $this->formatHeaders($headers)),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => $sslOptions,
        ]);

        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            $error = error_get_last();
            throw new TransportException('Moolre API request failed: ' . ($error['message'] ?? 'Unknown transport error.'));
        }

        $statusCode = $this->extractStatusCode($http_response_header ?? []);
        $responseHeaders = $this->extractHeaders($http_response_header ?? []);

        return new HttpResponse($statusCode, $responseBody, $responseHeaders);
    }

    /**
     * @param array<string, string> $headers
     * @return string[]
     */
    private function formatHeaders(array $headers): array
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $headers);

        $formatted = [];

        foreach ($headers as $name => $value) {
            if (strpbrk((string) $name, "\r\n:") !== false || strpbrk((string) $value, "\r\n") !== false) {
                throw new TransportException('HTTP header names and values cannot contain line breaks.');
            }

            $formatted[] = sprintf('%s: %s', $name, $value);
        }

        return $formatted;
    }

    /**
     * @param string[] $headers
     */
    private function extractStatusCode(array $headers): int
    {
        $statusCode = 0;

        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s(\d{3})\s?/', $header, $matches)) {
                $statusCode = (int) $matches[1];
            }
        }

        return $statusCode;
    }

    /**
     * @param string[] $headers
     * @return array<string, string>
     */
    private function extractHeaders(array $headers): array
    {
        $parsed = [];

        foreach ($headers as $line) {
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $parsed[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $parsed;
    }

    private function caFileFromEnvironment(): ?string
    {
        foreach (['MOOLRE_CA_FILE', 'SSL_CERT_FILE'] as $key) {
            $value = getenv($key);

            if ($value !== false && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function caPathOrFile(): ?string
    {
        if ($this->caFile !== null) {
            if (!is_file($this->caFile) && !is_dir($this->caFile)) {
                throw new TransportException('Configured CA bundle path does not exist: ' . $this->caFile);
            }

            return $this->caFile;
        }

        if (class_exists(CaBundle::class)) {
            return CaBundle::getSystemCaRootBundlePath();
        }

        return null;
    }

    private function formatCurlError(string $error, int $errorNumber): string
    {
        if ($errorNumber === 60) {
            return 'Moolre API request failed: SSL certificate problem. PHP could not find a trusted CA bundle. Configure curl.cainfo/openssl.cafile in php.ini or set MOOLRE_CA_FILE to a valid cacert.pem path.';
        }

        return 'Moolre API request failed: ' . $error;
    }

    private function validateUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new TransportException('Moolre API URL must be valid.');
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new TransportException('Moolre API URL must include a host and cannot include user credentials.');
        }

        if (!in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new TransportException('Moolre API URL must use HTTP or HTTPS.');
        }
    }
}

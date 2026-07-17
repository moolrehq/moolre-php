<?php

declare(strict_types=1);

namespace Moolre;

use Moolre\Exceptions\ApiException;
use Moolre\Exceptions\ValidationException;
use Moolre\Http\HttpResponse;
use Moolre\Http\NativeTransport;
use Moolre\Http\TransportInterface;
use Moolre\Requests\PaymentRequest;
use Moolre\Responses\PaymentInitiation;
use Moolre\Responses\TransactionVerification;

final class Client
{
    public const DEFAULT_BASE_URL = 'https://api.moolre.com/embed/src/start';
    public const SANDBOX_BASE_URL = 'https://sandbox.moolre.com/embed/src/start';
    public const DEFAULT_VERIFICATION_URL = 'https://api.moolre.com/open/transact/status';
    public const SANDBOX_VERIFICATION_URL = 'https://sandbox.moolre.com/open/transact/status';

    /** @var string */
    private $publicKey;

    /** @var string */
    private $accountNumber;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $verificationUrl;

    /** @var int */
    private $timeout;

    /** @var TransportInterface */
    private $transport;

    /** @var bool */
    private $allowInsecureBaseUrl;

    /** @var string|null */
    private $apiUser;

    public function __construct(
        string $publicKey,
        string $accountNumber,
        ?TransportInterface $transport = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = 60,
        bool $allowInsecureBaseUrl = false,
        ?string $apiUser = null,
        ?string $verificationUrl = null
    ) {
        $this->publicKey = trim($publicKey);
        $this->accountNumber = trim($accountNumber);
        $this->baseUrl = trim($baseUrl);
        $this->timeout = $timeout;
        $this->transport = $transport ?: new NativeTransport();
        $this->allowInsecureBaseUrl = $allowInsecureBaseUrl;
        $this->apiUser = $apiUser !== null ? trim($apiUser) : null;

        if ($this->publicKey === '' && !$this->isSandboxBaseUrl()) {
            throw ValidationException::forField('publicKey', 'Public key is required.');
        }

        if ($this->publicKey !== '' && $this->containsLineBreak($this->publicKey)) {
            throw ValidationException::forField('publicKey', 'Public key cannot contain line breaks.');
        }

        if ($this->apiUser !== null && $this->containsLineBreak($this->apiUser)) {
            throw ValidationException::forField('apiUser', 'API user cannot contain line breaks.');
        }

        if ($this->accountNumber === '') {
            throw ValidationException::forField('accountNumber', 'Account number is required.');
        }

        if ($this->baseUrl === '') {
            throw ValidationException::forField('baseUrl', 'Base URL is required.');
        }

        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            throw ValidationException::forField('baseUrl', 'Base URL must be a valid URL.');
        }

        $urlParts = parse_url($this->baseUrl);

        if (!is_array($urlParts)) {
            throw ValidationException::forField('baseUrl', 'Base URL must be a valid URL.');
        }

        $scheme = strtolower((string) ($urlParts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::forField('baseUrl', 'Base URL must use HTTP or HTTPS.');
        }

        if (!isset($urlParts['host']) || $urlParts['host'] === '' || isset($urlParts['user']) || isset($urlParts['pass'])) {
            throw ValidationException::forField('baseUrl', 'Base URL must include a host and cannot include user credentials.');
        }

        if ($scheme === 'http' && !$this->allowInsecureBaseUrl) {
            throw ValidationException::forField('baseUrl', 'Base URL must use HTTPS.');
        }

        $this->verificationUrl = trim($verificationUrl ?? $this->defaultVerificationUrl());
        $this->validateEndpointUrl('verificationUrl', $this->verificationUrl);

        if ($this->timeout < 1) {
            throw ValidationException::forField('timeout', 'Timeout must be at least 1 second.');
        }

        if ($this->isSandboxBaseUrl() && ($this->apiUser === null || $this->apiUser === '')) {
            throw ValidationException::forField('apiUser', 'Sandbox requests require the Moolre account username for the X-API-USER header.');
        }
    }

    /**
     * Create a Moolre checkout session and return the hosted payment URL.
     *
     * @param array<string, mixed>|PaymentRequest $payment
     */
    public function initiatePayment($payment): PaymentInitiation
    {
        if (!$payment instanceof PaymentRequest && !is_array($payment)) {
            throw ValidationException::forField('payment', 'Payment must be an array or PaymentRequest instance.');
        }

        $request = $payment instanceof PaymentRequest ? $payment : PaymentRequest::fromArray($payment);

        $payload = array_merge(
            [
                'state' => 'starter',
                'accountnumber' => $this->accountNumber,
                'tx_source' => 'php-sdk',
            ],
            $request->toArray()
        );

        $responseBody = $this->post($this->baseUrl, $payload, $this->headers())->json();

        if (!is_array($responseBody)) {
            throw new ApiException('Moolre response was not a JSON object.', 200, $responseBody);
        }

        return PaymentInitiation::fromArray($responseBody);
    }

    /**
     * Confirm the current payment status for a Moolre reference.
     */
    public function verifyPayment(string $reference): TransactionVerification
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw ValidationException::forField('reference', 'Reference is required.');
        }

        if ($this->apiUser === null || $this->apiUser === '') {
            throw ValidationException::forField('apiUser', 'Verification requests require the Moolre account username for the X-API-USER header.');
        }

        $payload = [
            'type' => 1,
            'idtype' => '1',
            'id' => $reference,
            'accountnumber' => $this->accountNumber,
        ];

        $responseBody = $this->post($this->verificationUrl, $payload, $this->headers(true))->json();

        if (!is_array($responseBody)) {
            throw new ApiException('Moolre response was not a JSON object.', 200, $responseBody);
        }

        $verification = TransactionVerification::fromArray($responseBody);

        if ($verification->reference() !== null && $verification->reference() !== $reference) {
            throw new ApiException('Moolre verification response reference does not match the requested reference.', 200, $responseBody);
        }

        return $verification;
    }

    /**
     * Generate a collision-resistant reference with an optional app prefix.
     */
    public static function generateReference(string $prefix = 'moolre'): string
    {
        $prefix = trim($prefix);
        $prefix = $prefix === '' ? 'moolre' : preg_replace('/[^A-Za-z0-9_-]/', '-', $prefix);

        try {
            $random = bin2hex(random_bytes(8));
        } catch (\Exception $exception) {
            $random = str_replace('.', '', uniqid('', true));
        }

        return sprintf('%s_%s_%s', $prefix, time(), $random);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $url, array $payload, array $headers): HttpResponse
    {
        $response = $this->transport->postJson(
            $url,
            $payload,
            $headers,
            $this->timeout
        );

        if (!$response->isSuccessful()) {
            $body = $response->json(false);
            $message = is_array($body) && isset($body['message'])
                ? ApiException::stringifyMessage($body['message'])
                : sprintf('Moolre API request failed with HTTP status %d.', $response->statusCode());

            throw new ApiException($message, $response->statusCode(), $body);
        }

        return $response;
    }

    private function containsLineBreak(string $value): bool
    {
        return strpbrk($value, "\r\n") !== false;
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $requiresApiUser = false): array
    {
        $headers = [];

        if ($this->publicKey !== '') {
            $headers['X-API-PUBKEY'] = $this->publicKey;
        }

        if (($requiresApiUser || $this->isSandboxBaseUrl()) && $this->apiUser !== null && $this->apiUser !== '') {
            $headers['X-API-USER'] = $this->apiUser;
        }

        return $headers;
    }

    private function isSandboxBaseUrl(): bool
    {
        $host = strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));

        return $host === 'sandbox.moolre.com';
    }

    private function defaultVerificationUrl(): string
    {
        return $this->isSandboxBaseUrl() ? self::SANDBOX_VERIFICATION_URL : self::DEFAULT_VERIFICATION_URL;
    }

    private function validateEndpointUrl(string $field, string $url): void
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::forField($field, 'Verification URL must be a valid URL.');
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::forField($field, 'Verification URL must include a host and cannot include user credentials.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::forField($field, 'Verification URL must use HTTP or HTTPS.');
        }

        if ($scheme === 'http' && !$this->allowInsecureBaseUrl) {
            throw ValidationException::forField($field, 'Verification URL must use HTTPS.');
        }
    }
}

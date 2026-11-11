<?php

declare(strict_types=1);

namespace Moolre\Requests;

use Moolre\Exceptions\ValidationException;
use Moolre\Support\Amount;

final class PaymentRequest
{
    /** @var string */
    private $reference;

    /** @var string */
    private $email;

    /** @var string */
    private $amount;

    /** @var string */
    private $currency;

    /** @var string */
    private $callbackUrl;

    /** @var string|null */
    private $redirectUrl;

    /** @var int|null */
    private $expirationTime;

    /** @var array<string, mixed>|null */
    private $metadata;

    /** @var string|null */
    private $nonceValue;

    /** @var string|null */
    private $transactionSource;

    /**
     * @param float|int|string $amount
     */
    public function __construct(
        string $reference,
        string $email,
        $amount,
        string $currency,
        string $callbackUrl,
        ?string $nonceValue = null,
        ?string $transactionSource = null,
        ?string $redirectUrl = null,
        ?int $expirationTime = null,
        ?array $metadata = null
    ) {
        $this->reference = trim($reference);
        $this->email = trim($email);
        $this->amount = Amount::normalize($amount) ?? '';
        $this->currency = strtoupper(trim($currency));
        $this->callbackUrl = trim($callbackUrl);
        $this->redirectUrl = $redirectUrl !== null ? trim($redirectUrl) : null;
        $this->expirationTime = $expirationTime;
        $this->metadata = $metadata;
        $this->nonceValue = $nonceValue !== null ? trim($nonceValue) : null;
        $this->transactionSource = $transactionSource !== null ? trim($transactionSource) : null;

        $this->validate();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (array_key_exists('metadata', $data) && $data['metadata'] !== null && !is_array($data['metadata'])) {
            throw ValidationException::forField('metadata', 'Metadata must be an array.');
        }

        $expirationTime = $data['expiration_time'] ?? $data['expirationTime'] ?? null;

        return new self(
            (string) ($data['reference'] ?? ''),
            (string) ($data['email'] ?? ''),
            $data['amount'] ?? null,
            (string) ($data['currency'] ?? 'GHS'),
            (string) ($data['callback'] ?? $data['callback_url'] ?? ''),
            isset($data['nonce_value']) ? (string) $data['nonce_value'] : null,
            isset($data['tx_source']) ? (string) $data['tx_source'] : null,
            isset($data['redirect']) ? (string) $data['redirect'] : (isset($data['redirect_url']) ? (string) $data['redirect_url'] : null),
            $expirationTime !== null ? (int) $expirationTime : null,
            isset($data['metadata']) ? $data['metadata'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'reference' => $this->reference,
            'email' => $this->email,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'callback' => $this->callbackUrl,
        ];

        if ($this->redirectUrl !== null && $this->redirectUrl !== '') {
            $payload['redirect'] = $this->redirectUrl;
        }

        if ($this->expirationTime !== null) {
            $payload['expiration_time'] = $this->expirationTime;
        }

        if ($this->metadata !== null && $this->metadata !== []) {
            $payload['metadata'] = $this->metadata;
        }

        if ($this->nonceValue !== null && $this->nonceValue !== '') {
            $payload['nonce_value'] = $this->nonceValue;
        }

        if ($this->transactionSource !== null && $this->transactionSource !== '') {
            $payload['tx_source'] = $this->transactionSource;
        }

        return $payload;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    private function validate(): void
    {
        if ($this->reference === '') {
            throw ValidationException::forField('reference', 'Reference is required.');
        }

        if (strlen($this->reference) > 255 || preg_match('/[\x00-\x1F\x7F]/', $this->reference)) {
            throw ValidationException::forField('reference', 'Reference must be at most 255 characters and cannot contain control characters.');
        }

        if ($this->email === '' || !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::forField('email', 'A valid email address is required.');
        }

        if (!Amount::isPositive($this->amount)) {
            throw ValidationException::forField('amount', 'Amount must be a positive decimal value with no more than two decimal places.');
        }

        if ($this->currency === '') {
            throw ValidationException::forField('currency', 'Currency is required.');
        }

        if (!preg_match('/^[A-Z]{3}$/', $this->currency)) {
            throw ValidationException::forField('currency', 'Currency must be a three-letter ISO currency code.');
        }

        $this->validateUrl('callback', $this->callbackUrl, true);

        if ($this->redirectUrl !== null && $this->redirectUrl !== '') {
            $this->validateUrl('redirect', $this->redirectUrl, false);
        }

        if ($this->expirationTime !== null && $this->expirationTime < 1) {
            throw ValidationException::forField('expiration_time', 'Expiration time must be at least 1 minute.');
        }
    }

    private function validateUrl(string $field, string $url, bool $required): void
    {
        if ($url === '') {
            if ($required) {
                throw ValidationException::forField($field, sprintf('A valid %s URL is required.', $field));
            }

            return;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::forField($field, sprintf('A valid %s URL is required.', $field));
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::forField($field, sprintf('%s URL must include a host and cannot include user credentials.', ucfirst($field)));
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::forField($field, sprintf('%s URL must use HTTP or HTTPS.', ucfirst($field)));
        }

        if ($scheme === 'http' && !self::isLoopbackHost((string) $parts['host'])) {
            throw ValidationException::forField($field, sprintf('%s URL must use HTTPS except for local development.', ucfirst($field)));
        }

        if (isset($parts['fragment'])) {
            throw ValidationException::forField($field, sprintf('%s URL cannot contain a fragment.', ucfirst($field)));
        }
    }

    private static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }
}

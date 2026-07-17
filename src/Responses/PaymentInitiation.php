<?php

declare(strict_types=1);

namespace Moolre\Responses;

use Moolre\Exceptions\ApiException;

final class PaymentInitiation
{
    /** @var array<string, mixed> */
    private $payload;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if (!self::hasSuccessfulStatus($payload['status'] ?? null)) {
            throw new ApiException(ApiException::stringifyMessage($payload['message'] ?? 'Moolre payment initiation failed.'), 200, $payload);
        }

        if (!isset($payload['data']) || !is_array($payload['data']) || !isset($payload['data']['authorization_url']) || !is_string($payload['data']['authorization_url']) || !self::isSecureUrl($payload['data']['authorization_url'])) {
            throw new ApiException('Moolre response did not include an authorization URL.', 200, $payload);
        }

        return new self($payload);
    }

    public function authorizationUrl(): string
    {
        return (string) $this->payload['data']['authorization_url'];
    }

    public function reference(): ?string
    {
        return isset($this->payload['data']['reference']) ? (string) $this->payload['data']['reference'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->payload;
    }

    /**
     * @param mixed $status
     */
    private static function hasSuccessfulStatus($status): bool
    {
        return $status === 1 || $status === '1' || $status === true;
    }

    private static function isSecureUrl(string $url): bool
    {
        if (trim($url) !== $url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}

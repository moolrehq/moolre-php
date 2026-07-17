<?php

declare(strict_types=1);

namespace Moolre\Http;

use Moolre\Exceptions\TransportException;

final class HttpResponse
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $body;

    /** @var array<string, string> */
    private $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * @return mixed
     */
    public function json(bool $throw = true)
    {
        $decoded = json_decode($this->body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (!$throw) {
                return null;
            }

            throw new TransportException('Moolre returned invalid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }
}

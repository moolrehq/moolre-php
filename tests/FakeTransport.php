<?php

declare(strict_types=1);

namespace Moolre\Tests;

use Moolre\Http\HttpResponse;
use Moolre\Http\TransportInterface;

final class FakeTransport implements TransportInterface
{
    /** @var array<int, HttpResponse> */
    private $responses;

    /** @var array<int, array<string, mixed>> */
    public $requests = [];

    /**
     * @param array<int, HttpResponse> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 60): HttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'payload' => $payload,
            'headers' => $headers,
            'timeout' => $timeout,
        ];

        return array_shift($this->responses) ?: new HttpResponse(500, '{"status":0,"message":"No fake response configured"}');
    }
}

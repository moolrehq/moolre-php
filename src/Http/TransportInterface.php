<?php

declare(strict_types=1);

namespace Moolre\Http;

interface TransportInterface
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 60): HttpResponse;
}

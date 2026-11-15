<?php

declare(strict_types=1);

namespace Moolre\Api;

use Moolre\Exceptions\ConfigurationError;
use Moolre\Transport;

/** @internal */
abstract class Resource
{
    /** @var Transport */
    protected $t;

    public function __construct(Transport $t)
    {
        $this->t = $t;
    }

    /** @param array<string, mixed> $p */
    protected function account(array $p): string
    {
        $a = $p['accountNumber'] ?? ($this->t->opts['accountNumber'] ?? null);
        if ($a === null || $a === '') {
            throw new ConfigurationError('accountNumber is required (pass it to the call or to the client)');
        }

        return (string) $a;
    }

    /**
     * @param array<string, mixed> $p
     * @param string[] $keys
     */
    protected function need(array $p, array $keys): void
    {
        foreach ($keys as $k) {
            if (!isset($p[$k]) || $p[$k] === '') {
                throw new ConfigurationError(sprintf('Missing required parameter "%s"', $k));
            }
        }
    }

    /**
     * @param array<string, mixed> $p
     * @return array{idtype:int,id:string}
     */
    protected function lookup(array $p): array
    {
        if (isset($p['externalRef'])) {
            return ['idtype' => 1, 'id' => (string) $p['externalRef']];
        }
        if (isset($p['transactionId'])) {
            return ['idtype' => 2, 'id' => (string) $p['transactionId']];
        }
        throw new ConfigurationError('Pass externalRef or transactionId');
    }

    /** @param array<string, mixed> $o */
    protected function key(array $o): ?string
    {
        return isset($o['idempotencyKey']) ? (string) $o['idempotencyKey'] : null;
    }
}

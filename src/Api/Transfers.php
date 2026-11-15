<?php

declare(strict_types=1);

namespace Moolre\Api;

use Moolre\Result;
use Moolre\Transport;

/** Payouts, name validation and internal transfers. */
final class Transfers extends Resource
{
    /**
     * Pay out to a mobile money wallet or bank account (channel MTN | TELECEL | AT | BANK).
     * Pass ['idempotencyKey' => ...] to make retries safe.
     *
     * @param array{channel:string,currency:string,amount:mixed,receiver:string,externalRef:string,subListId?:string,reference?:string,senderName?:string,accountNumber?:string} $p
     * @param array{idempotencyKey?:string} $o
     */
    public function create(array $p, array $o = []): Result
    {
        $this->need($p, ['channel', 'currency', 'amount', 'receiver', 'externalRef']);

        return $this->t->call('/open/transact/transfer', [
            'type' => 1, 'channel' => Transport::channel($p['channel']), 'currency' => $p['currency'],
            'amount' => Transport::amount($p['amount']), 'receiver' => $p['receiver'],
            'sublistid' => $p['subListId'] ?? null, 'externalref' => $p['externalRef'],
            'reference' => $p['reference'] ?? null, 'sendername' => $p['senderName'] ?? null,
            'accountnumber' => $this->account($p),
        ], ['needs' => ['apiKey'], 'retry' => 'idempotent', 'idempotencyKey' => $this->key($o)]);
    }

    public function status(array $p): Result
    {
        return $this->t->call('/open/transact/status', ['type' => 1, 'accountnumber' => $this->account($p)] + $this->lookup($p),
            ['needs' => ['apiKey', 'pubKey'], 'retry' => 'read']);
    }

    /** Confirm the account holder's name before paying out. */
    public function validateName(array $p): Result
    {
        $this->need($p, ['channel', 'currency', 'receiver']);
        $name = Transport::channel($p['channel']);
        // /open/transact/validate currently reads the numeric channel ids only.
        $legacy = ['MTN' => '1', 'TELECEL' => '6', 'AT' => '7', 'BANK' => '2'];

        return $this->t->call('/open/transact/validate', [
            'type' => 1, 'receiver' => $p['receiver'], 'channel' => $legacy[$name] ?? $name,
            'sublistid' => $p['subListId'] ?? null, 'currency' => $p['currency'], 'accountnumber' => $this->account($p),
        ], ['needs' => ['apiKey', 'pubKey'], 'retry' => 'read']);
    }

    /** Move money between two Moolre accounts (receiver is an account number, not a phone number). */
    public function internal(array $p): Result
    {
        $this->need($p, ['currency', 'amount', 'receiver', 'externalRef']);

        return $this->t->call('/open/transact/internal', [
            'type' => 1, 'currency' => $p['currency'], 'amount' => Transport::amount($p['amount']),
            'receiver' => $p['receiver'], 'externalref' => $p['externalRef'],
            'reference' => $p['reference'] ?? null, 'accountnumber' => $this->account($p),
        ], ['needs' => ['apiKey']]);
    }
}

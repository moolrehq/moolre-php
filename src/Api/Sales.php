<?php

declare(strict_types=1);

namespace Moolre\Api;

use Moolre\Result;
use Moolre\Transport;

/** Airtime and data bundle sales (wallet must be linked to Sell on Our Platform). */
final class Sales extends Resource
{
    /** @param array{channel:string,receiver:string,amount:mixed,externalRef:string,reference?:string,accountNumber?:string} $p */
    public function airtime(array $p): Result
    {
        $this->need($p, ['channel', 'receiver', 'amount', 'externalRef']);

        return $this->t->call('/open/sales/airtime', [
            'type' => 1, 'channel' => Transport::channel($p['channel']), 'receiver' => $p['receiver'],
            'amount' => Transport::amount($p['amount']), 'currency' => 'GHS', 'accountnumber' => $this->account($p),
            'externalref' => $p['externalRef'], 'reference' => $p['reference'] ?? null,
        ], ['needs' => ['apiKey']]);
    }

    /** @param array{channel:string,receiver:string,bundle:string,externalRef:string,reference?:string,accountNumber?:string} $p */
    public function data(array $p): Result
    {
        $this->need($p, ['channel', 'receiver', 'bundle', 'externalRef']);

        return $this->t->call('/open/sales/data', [
            'type' => 1, 'channel' => Transport::channel($p['channel']), 'receiver' => $p['receiver'],
            'bundle' => $p['bundle'], 'currency' => 'GHS', 'accountnumber' => $this->account($p),
            'externalref' => $p['externalRef'], 'reference' => $p['reference'] ?? null,
        ], ['needs' => ['apiKey']]);
    }

    /** @param array{network?:string,accountNumber?:string} $p */
    public function bundles(array $p = []): Result
    {
        return $this->t->call('/open/sales/bundles', [
            'accountnumber' => $this->account($p),
            'network' => isset($p['network']) ? Transport::channel($p['network']) : null,
        ], ['needs' => ['apiKey'], 'retry' => 'read']);
    }

    /** Look up by ['externalRef'], ['transactionId'] or ['reference']. */
    public function status(array $p): Result
    {
        $look = isset($p['reference']) ? ['idtype' => 3, 'id' => (string) $p['reference']] : $this->lookup($p);

        return $this->t->call('/open/sales/status', ['type' => 1, 'accountnumber' => $this->account($p)] + $look,
            ['needs' => ['apiKey'], 'retry' => 'read']);
    }
}

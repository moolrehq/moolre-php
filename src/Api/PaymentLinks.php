<?php

declare(strict_types=1);

namespace Moolre\Api;

use Moolre\Result;
use Moolre\Transport;

final class PaymentLinks extends Resource
{
    /**
     * @param array{amount:mixed,email:string,externalRef:string,currency:string,callback?:string,redirect?:string,reusable?:bool,expirationTime?:int,metadata?:array,accountNumber?:string} $p
     */
    public function create(array $p): Result
    {
        $this->need($p, ['amount', 'email', 'externalRef', 'currency']);

        return $this->t->call('/embed/link', [
            'type' => 1, 'amount' => Transport::amount($p['amount']), 'email' => $p['email'],
            'externalref' => $p['externalRef'], 'callback' => $p['callback'] ?? null,
            'redirect' => $p['redirect'] ?? null, 'reusable' => !empty($p['reusable']) ? '1' : '0',
            'expiration_time' => $p['expirationTime'] ?? null, 'currency' => $p['currency'],
            'accountnumber' => $this->account($p), 'metadata' => $p['metadata'] ?? null,
        ], ['needs' => ['pubKey']]);
    }
}

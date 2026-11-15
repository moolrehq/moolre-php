<?php

declare(strict_types=1);

namespace Moolre\Api;

use Moolre\Result;
use Moolre\Transport;

/** Mobile money collections, Payment IDs and bank collection accounts. */
final class Payments extends Resource
{
    /**
     * Request a mobile money payment. When the customer's number needs verifying the API answers TP14
     * ($result->otpRequired()): ask for the SMS code and call verifyOtp(). On TP17 call initiate() again
     * without otpCode.
     *
     * @param array{channel:string,currency:string,payer:string,amount:mixed,externalRef:string,reference?:string,otpCode?:string,sessionId?:string,skipOtp?:bool,accountNumber?:string} $p
     * @param array{idempotencyKey?:string} $o
     */
    public function initiate(array $p, array $o = []): Result
    {
        $this->need($p, ['channel', 'currency', 'payer', 'amount', 'externalRef']);

        return $this->t->call('/open/transact/payment', [
            'type' => 1,
            'channel' => Transport::channel($p['channel']),
            'currency' => $p['currency'],
            'payer' => $p['payer'],
            'amount' => Transport::amount($p['amount']),
            'externalref' => $p['externalRef'],
            'reference' => $p['reference'] ?? null,
            'otpcode' => $p['otpCode'] ?? null,
            'sessionid' => $p['sessionId'] ?? null,
            'accountnumber' => $this->account($p),
            'skipotp' => $p['skipOtp'] ?? null,
        ], ['needs' => ['pubKey'], 'retry' => 'idempotent', 'idempotencyKey' => $this->key($o)]);
    }

    /** Second step of the OTP flow: initiate() with otpCode. */
    public function verifyOtp(array $p, array $o = []): Result
    {
        $this->need($p, ['otpCode']);

        return $this->initiate($p, $o);
    }

    /** Status by ['externalRef' => ...] or ['transactionId' => ...]. */
    public function status(array $p): Result
    {
        return $this->t->call('/open/transact/status', ['type' => 1, 'accountnumber' => $this->account($p)] + $this->lookup($p),
            ['needs' => ['apiKey', 'pubKey'], 'retry' => 'read']);
    }

    public function createPaymentId(array $p): Result
    {
        $this->need($p, ['phone', 'name', 'currency', 'externalRef']);

        return $this->t->call('/open/account/create', [
            'type' => 2, 'phone' => $p['phone'], 'name' => $p['name'], 'currency' => $p['currency'],
            'amount' => isset($p['amount']) ? Transport::amount($p['amount']) : null,
            'externalref' => $p['externalRef'], 'accountnumber' => $this->account($p),
        ], ['needs' => ['pubKey']]);
    }

    public function createBankAccount(array $p): Result
    {
        $this->need($p, ['currency', 'firstName', 'lastName', 'phone', 'email', 'externalRef']);

        return $this->t->call('/open/account/create', [
            'type' => 9, 'currency' => $p['currency'], 'amount' => isset($p['amount']) ? (float) $p['amount'] : null,
            'firstname' => $p['firstName'], 'lastname' => $p['lastName'], 'phone' => $p['phone'],
            'email' => $p['email'], 'externalref' => $p['externalRef'], 'accountnumber' => $this->account($p),
        ], ['needs' => ['pubKey']]);
    }

    public function listBankAccounts(array $p = []): Result
    {
        return $this->t->call('/open/transact/status', ['type' => 3, 'accountnumber' => $this->account($p)],
            ['needs' => ['pubKey'], 'retry' => 'read']);
    }

    /** Payments received on a Payment ID or bank account, and its status. */
    public function accountTransactions(string $externalRef, array $p = []): Result
    {
        return $this->t->call('/open/transact/status', ['type' => 2, 'externalref' => $externalRef, 'accountnumber' => $this->account($p)],
            ['needs' => ['pubKey'], 'retry' => 'read']);
    }
}

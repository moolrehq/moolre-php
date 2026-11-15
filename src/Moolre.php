<?php

declare(strict_types=1);

namespace Moolre;

use Moolre\Api\Accounts;
use Moolre\Api\Bulk;
use Moolre\Api\Invoices;
use Moolre\Api\PaymentLinks;
use Moolre\Api\Payments;
use Moolre\Api\Reference;
use Moolre\Api\Sales;
use Moolre\Api\Sms;
use Moolre\Api\Transfers;
use Moolre\Api\Webhooks;
use Moolre\Api\WhatsApp;

/**
 * Moolre server SDK client (separate from the hosted-checkout Moolre\Client).
 *
 * Options: environment ('live'|'sandbox'), baseUrl, user, apiKey, pubKey, vasKey, accountNumber,
 * strictHttpStatus (bool), throwOnError (bool, default true), maxRetries (default 2), retryDelayMs,
 * timeoutSec (default 60), logger (callable(string $message, array $context) | null), caFile.
 */
final class Moolre
{
    /** @var Payments */
    public $payments;
    /** @var PaymentLinks */
    public $paymentLinks;
    /** @var Transfers */
    public $transfers;
    /** @var Accounts */
    public $accounts;
    /** @var Sales */
    public $sales;
    /** @var Sms */
    public $sms;
    /** @var WhatsApp */
    public $whatsapp;
    /** @var Reference */
    public $reference;
    /** @var Bulk */
    public $bulk;
    /** @var Invoices */
    public $invoices;
    /** @var Webhooks */
    public $webhooks;

    /** @param array<string, mixed> $options */
    public function __construct(array $options)
    {
        $t = new Transport($options);
        $this->payments = new Payments($t);
        $this->paymentLinks = new PaymentLinks($t);
        $this->transfers = new Transfers($t);
        $this->accounts = new Accounts($t);
        $this->sales = new Sales($t);
        $this->sms = new Sms($t);
        $this->whatsapp = new WhatsApp($t);
        $this->reference = new Reference($t);
        $this->bulk = new Bulk($t);
        $this->invoices = new Invoices($t);
        $this->webhooks = new Webhooks($t);
    }

    /** Build from MOOLRE_ENV, MOOLRE_USER, MOOLRE_API_KEY, MOOLRE_PUBKEY, MOOLRE_VASKEY, MOOLRE_ACCOUNT. */
    public static function fromEnv(array $overrides = []): self
    {
        return new self($overrides + [
            'environment' => getenv('MOOLRE_ENV') === 'sandbox' ? 'sandbox' : 'live',
            'user' => getenv('MOOLRE_USER') ?: null,
            'apiKey' => getenv('MOOLRE_API_KEY') ?: null,
            'pubKey' => getenv('MOOLRE_PUBKEY') ?: null,
            'vasKey' => getenv('MOOLRE_VASKEY') ?: null,
            'accountNumber' => getenv('MOOLRE_ACCOUNT') ?: null,
        ]);
    }

    /** A random Idempotency-Key (UUID v4). Store it with your order and reuse it when retrying. */
    public static function newIdempotencyKey(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

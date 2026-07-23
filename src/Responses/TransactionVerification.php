<?php

declare(strict_types=1);

namespace Moolre\Responses;

use Moolre\Exceptions\ApiException;
use Moolre\Support\Amount;

final class TransactionVerification
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
            throw new ApiException(ApiException::stringifyMessage($payload['message'] ?? 'Moolre transaction verification failed.'), 200, $payload);
        }

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            throw new ApiException('Moolre verification response did not include transaction data.', 200, $payload);
        }

        return new self($payload);
    }

    public function isSuccessful(): bool
    {
        return self::hasSuccessfulStatus($this->payload['data']['status'] ?? $this->payload['data']['txstatus'] ?? null);
    }

    public function reference(): ?string
    {
        return $this->stringField('reference')
            ?? $this->stringField('externalref')
            ?? $this->stringField('id')
            ?? $this->stringField('transaction_reference')
            ?? $this->stringField('tx_reference');
    }

    public function amount(): ?string
    {
        return Amount::normalize($this->payload['data']['amount'] ?? null);
    }

    public function currency(): ?string
    {
        $currency = $this->stringField('currency');

        return $currency !== null ? strtoupper($currency) : null;
    }

    public function email(): ?string
    {
        $email = $this->stringField('email');

        return $email !== null ? strtolower($email) : null;
    }

    public function accountNumber(): ?string
    {
        return $this->stringField('accountnumber') ?? $this->stringField('account_number');
    }

    public function matchesAmountAndAccountNumber(string $amount, string $accountNumber): bool
    {
        if (!$this->isSuccessful()) {
            return false;
        }

        $expectedAmount = Amount::normalize($amount);

        return $expectedAmount !== null
            && $this->amount() === $expectedAmount
            && $this->accountNumber() === trim($accountNumber);
    }

    /**
     * @deprecated since 1.0.1 The Moolre transaction-status endpoint does not return currency or email. Use matchesAmountAndAccountNumber().
     */
    public function matchesPaymentDetails(string $amount, string $currency, ?string $email = null, ?string $accountNumber = null): bool
    {
        if (!$this->isSuccessful()) {
            return false;
        }

        $expectedAmount = Amount::normalize($amount);
        $expectedCurrency = strtoupper(trim($currency));

        if ($expectedAmount === null || $this->amount() !== $expectedAmount || $this->currency() !== $expectedCurrency) {
            return false;
        }

        if ($email !== null && $this->email() !== strtolower(trim($email))) {
            return false;
        }

        if ($accountNumber !== null && $this->accountNumber() !== trim($accountNumber)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->payload['data'];
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

    private function stringField(string $field): ?string
    {
        if (!isset($this->payload['data'][$field]) || !is_scalar($this->payload['data'][$field])) {
            return null;
        }

        $value = trim((string) $this->payload['data'][$field]);

        return $value !== '' ? $value : null;
    }
}

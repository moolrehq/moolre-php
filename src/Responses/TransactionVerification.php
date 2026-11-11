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

        self::assertUnambiguousStatus($payload['data'], $payload);
        self::assertUnambiguousFields(
            $payload['data'],
            ['externalref', 'reference', 'id', 'transaction_reference', 'tx_reference'],
            'transaction reference',
            $payload
        );
        self::assertUnambiguousFields(
            $payload['data'],
            ['accountnumber', 'account_number'],
            'account number',
            $payload
        );

        return new self($payload);
    }

    public function isSuccessful(): bool
    {
        return self::hasSuccessfulStatus($this->payload['data']['txstatus'] ?? $this->payload['data']['status'] ?? null);
    }

    public function reference(): ?string
    {
        return $this->stringField('externalref')
            ?? $this->stringField('reference')
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

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $payload
     */
    private static function assertUnambiguousStatus(array $data, array $payload): void
    {
        if (!array_key_exists('txstatus', $data) || !array_key_exists('status', $data)) {
            return;
        }

        $transactionStatus = self::normalizeStatus($data['txstatus']);
        $legacyStatus = self::normalizeStatus($data['status']);

        if ($transactionStatus === null || $legacyStatus === null || $transactionStatus !== $legacyStatus) {
            throw new ApiException('Moolre verification response included conflicting transaction statuses.', 200, $payload);
        }
    }

    /**
     * @param mixed $status
     */
    private static function normalizeStatus($status): ?bool
    {
        if ($status === 1 || $status === '1' || $status === true) {
            return true;
        }

        if ($status === 0 || $status === '0' || $status === false) {
            return false;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $fields
     * @param array<string, mixed> $payload
     */
    private static function assertUnambiguousFields(array $data, array $fields, string $label, array $payload): void
    {
        $values = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            if (!is_scalar($data[$field])) {
                throw new ApiException(sprintf('Moolre verification response included an invalid %s field.', $label), 200, $payload);
            }

            $value = trim((string) $data[$field]);

            if ($value !== '') {
                $values[$value] = true;
            }
        }

        if (count($values) > 1) {
            throw new ApiException(sprintf('Moolre verification response included conflicting %s fields.', $label), 200, $payload);
        }
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

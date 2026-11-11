<?php

declare(strict_types=1);

namespace Moolre\Support;

final class Amount
{
    /**
     * Convert an amount to the canonical decimal representation used by Moolre.
     *
     * @param mixed $amount
     */
    public static function normalize($amount): ?string
    {
        if (is_int($amount)) {
            $value = (string) $amount;
        } elseif (is_float($amount)) {
            if (is_infinite($amount) || is_nan($amount)) {
                return null;
            }

            $scaled = $amount * 100;

            if (abs($scaled - round($scaled)) > 0.0000001) {
                return null;
            }

            $value = number_format($amount, 2, '.', '');
        } elseif (is_string($amount)) {
            if ($amount === '' || trim($amount) !== $amount) {
                return null;
            }

            $value = $amount;
        } else {
            return null;
        }

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value)) {
            return null;
        }

        $parts = explode('.', $value, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';

        return $whole . '.' . $fraction;
    }

    /**
     * @param mixed $amount
     */
    public static function isPositive($amount): bool
    {
        $normalized = self::normalize($amount);

        return $normalized !== null && $normalized !== '0.00';
    }
}

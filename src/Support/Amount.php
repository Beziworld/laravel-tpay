<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Support;

use InvalidArgumentException;

/**
 * Normalises monetary amounts to the two-decimal representation used by Tpay.
 */
final class Amount
{
    /**
     * Normalises "12,5", 12.5 or 12 to the canonical string "12.50".
     *
     * @throws InvalidArgumentException When the amount is not a positive number with at most two decimals.
     */
    public static function normalize(string|int|float $amount): string
    {
        $value = is_float($amount)
            ? number_format($amount, 2, '.', '')
            : str_replace([' ', ','], ['', '.'], trim((string) $amount));

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid amount with at most two decimal places.', (string) $amount));
        }

        $normalized = ltrim($matches[1], '0');
        $normalized = ($normalized === '' ? '0' : $normalized) . '.' . str_pad($matches[2] ?? '', 2, '0');

        if ($normalized === '0.00') {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return $normalized;
    }

    /**
     * Converts a normalised amount to the JSON number sent to the API.
     */
    public static function toFloat(string|int|float $amount): float
    {
        return (float) self::normalize($amount);
    }
}

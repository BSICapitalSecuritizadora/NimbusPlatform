<?php

namespace App\Support\Funds;

use InvalidArgumentException;

/**
 * Exact cent-level monetary arithmetic for the allocation domain.
 *
 * All approval math runs on integer cents — binary floating point never
 * touches a validated total. Inputs accept up to 6 decimal places and are
 * rounded half-up to cents explicitly and deterministically; anything else
 * (including non-numeric strings, NaN-style floats and out-of-range values)
 * is rejected instead of coerced.
 */
class FundAllocationMoney
{
    /**
     * Maximum storable amount in cents: decimal(15,2) holds 9999999999999.99.
     */
    public const MAX_CENTS = 999999999999999;

    /**
     * Convert a monetary value to integer cents, rounding half-up.
     */
    public static function toCents(string|int|float $value): int
    {
        $normalized = self::normalize($value);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,6}))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException("Valor monetário inválido: '{$normalized}'.");
        }

        [, $sign, $units, $fraction] = $matches + [3 => ''];
        $fraction = str_pad($fraction, 6, '0');

        // Round half-up at the third decimal of the cent value, all in strings.
        $hundredths = ((int) $units) * 100 + (int) substr($fraction, 0, 2);
        $remainder = (int) substr($fraction, 2, 4);

        if ($remainder >= 5000) {
            $hundredths++;
        }

        $cents = $sign === '-' ? -$hundredths : $hundredths;

        if (abs($cents) > self::MAX_CENTS) {
            throw new InvalidArgumentException("Valor monetário fora do intervalo suportado: '{$normalized}'.");
        }

        return $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /**
     * @param  array<int>  $cents
     */
    public static function sum(array $cents): int
    {
        $total = 0;

        foreach ($cents as $value) {
            $total += $value;
        }

        return $total;
    }

    private static function normalize(string|int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Valor monetário inválido.');
            }

            // Fixed 6-decimal rendering keeps the conversion deterministic;
            // callers should still prefer strings for exactness.
            return sprintf('%.6F', $value);
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Valor monetário inválido.');
        }

        return $trimmed;
    }
}

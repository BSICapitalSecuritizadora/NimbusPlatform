<?php

namespace App\Concerns;

/**
 * Utility class for Brazilian monetary value normalization and display formatting.
 * Extracted from ProposalProject to be used across DTOs, models, and components.
 */
final class MoneyFormatter
{
    public static function normalizeDecimalValue(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return 0.0;
        }

        $value = str_replace(['R$', ' '], '', $value);

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, '.')) {
            $parts = explode('.', $value);

            if ((count($parts) > 2) || (strlen((string) end($parts)) === 3)) {
                $value = str_replace('.', '', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } else {
            $value = str_replace(',', '', $value);
        }

        if (! is_numeric($value)) {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    public static function normalizeIntegerValue(mixed $value): int
    {
        return (int) round(self::normalizeDecimalValue($value));
    }

    public static function formatCurrencyForDisplay(mixed $value): string
    {
        return number_format(self::normalizeDecimalValue($value), 2, ',', '.');
    }
}

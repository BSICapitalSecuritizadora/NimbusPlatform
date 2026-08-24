<?php

namespace App\Support\Reconciliation;

use App\Concerns\MoneyFormatter;
use Carbon\Carbon;
use DateTimeInterface;

/**
 * Compares stored values against spreadsheet values on the domain's terms, not
 * on the string the reader happened to produce.
 *
 * This is what keeps a monthly reconciliation from inventing differences:
 * "R$ 1.000,00" and "1000.00" are one amount, "10/08/2026" and a Carbon date for
 * 2026-08-10 are one day, and a Laravel `decimal:2` cast hands back a string
 * that must never be compared with `===` against a float.
 */
final class ValueComparator
{
    /**
     * Money in integer cents. Anything else eventually loses a centavo to
     * floating point and reports a change nobody made.
     */
    public static function moneyEquals(mixed $stored, mixed $incoming): bool
    {
        return self::cents($stored) === self::cents($incoming);
    }

    public static function cents(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return (int) round(MoneyFormatter::normalizeDecimalValue($value) * 100);
    }

    /**
     * Both sides reduced to a plain Y-m-d string, so a Carbon instance, a
     * DateTime from the reader and a stored date all compare as the same day.
     */
    public static function dateEquals(mixed $stored, mixed $incoming): bool
    {
        return self::date($stored) === self::date($incoming);
    }

    public static function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    public static function formatMoney(mixed $value): ?string
    {
        return blank($value) ? null : 'R$ '.MoneyFormatter::formatCurrencyForDisplay($value);
    }

    public static function formatDate(mixed $value): ?string
    {
        $date = self::date($value);

        return $date === null ? null : Carbon::parse($date)->format('d/m/Y');
    }
}

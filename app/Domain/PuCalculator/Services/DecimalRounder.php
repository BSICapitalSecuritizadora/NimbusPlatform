<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\ValueObjects\Decimal;

class DecimalRounder
{
    public const INTERNAL_SCALE = 16;

    public const CALCULATION_SCALE = 24;

    public const FACTOR_SCALE = 16;

    public const RATE_SCALE = 8;

    public const UNIT_SCALE = 16;

    public const QUANTITY_SCALE = 4;

    public const TOTAL_SCALE = 16;

    public const VALIDATION_SCALE = 6;

    public const LEGACY_UNIT_SCALE = 6;

    public const LEGACY_MONEY_SCALE = 2;

    public function round(string|int|float $value, int $scale): string
    {
        $normalizedValue = Decimal::of($value)->value();
        $offset = '0.'.str_repeat('0', max(0, $scale)).'5';

        if (bccomp($normalizedValue, '0', self::INTERNAL_SCALE) < 0) {
            return bcsub($normalizedValue, $offset, $scale);
        }

        return bcadd($normalizedValue, $offset, $scale);
    }

    public function normalize(string|int|float|null $value, int $scale = self::INTERNAL_SCALE): string
    {
        if ($value === null) {
            return $this->round('0', $scale);
        }

        return $this->round(Decimal::of($value)->value(), $scale);
    }

    public function truncate(string|int|float $value, int $scale): string
    {
        $normalizedValue = Decimal::of($value)->value();
        $sign = '';

        if (str_starts_with($normalizedValue, '-')) {
            $sign = '-';
            $normalizedValue = substr($normalizedValue, 1);
        }

        [$integerPart, $decimalPart] = array_pad(explode('.', $normalizedValue, 2), 2, '');

        return sprintf(
            '%s%s.%s',
            $sign,
            $integerPart,
            substr($decimalPart.str_repeat('0', $scale), 0, $scale),
        );
    }

    public function absoluteDifference(string|int|float|null $left, string|int|float|null $right, int $scale = self::INTERNAL_SCALE): string
    {
        $leftValue = $this->normalize($left, $scale + 4);
        $rightValue = $this->normalize($right, $scale + 4);
        $difference = bcsub($leftValue, $rightValue, $scale + 4);

        if (bccomp($difference, '0', $scale + 4) < 0) {
            $difference = bcmul($difference, '-1', $scale + 4);
        }

        return $this->round($difference, $scale);
    }
}

<?php

namespace App\Domain\PuCalculator\Calculators;

use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\ValueObjects\Decimal;
use InvalidArgumentException;

class DailyFactorCalculator
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly DecimalRounder $rounder,
    ) {}

    public function factorDiForDay(?string $annualRate, bool $isBusinessDay, int $businessDayBasis): string
    {
        if (! $isBusinessDay || $annualRate === null) {
            return '1.0000000000000000';
        }

        $base = Decimal::one()
            ->add(Decimal::of($annualRate)->divide(Decimal::of(100), DecimalRounder::INTERNAL_SCALE), DecimalRounder::INTERNAL_SCALE)
            ->value();

        return $this->powRatio($base, 1, $businessDayBasis, DecimalRounder::FACTOR_SCALE);
    }

    public function factorSpreadForBusinessDays(string $spreadRate, int $businessDays, int $businessDayBasis): string
    {
        if ($businessDays <= 0) {
            return '1.0000000000000000';
        }

        $base = Decimal::one()
            ->add(Decimal::of($spreadRate)->divide(Decimal::of(100), DecimalRounder::INTERNAL_SCALE), DecimalRounder::INTERNAL_SCALE)
            ->value();

        return $this->powRatio($base, $businessDays, $businessDayBasis, DecimalRounder::FACTOR_SCALE);
    }

    public function powRatio(string $base, int $numerator, int $denominator, int $scale = DecimalRounder::FACTOR_SCALE): string
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('The denominator for a rational power must be positive.');
        }

        if ($numerator === 0) {
            return '1.0000000000000000';
        }

        $cacheKey = implode('|', [$base, $numerator, $denominator, $scale]);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $workingScale = $scale + 8;
        $poweredBase = Decimal::of($base)->powerInt($numerator, $workingScale)->value();

        if ($denominator === 1) {
            return $this->cache[$cacheKey] = $this->rounder->round($poweredBase, $scale);
        }

        return $this->cache[$cacheKey] = $this->nthRoot($poweredBase, $denominator, $scale, $workingScale);
    }

    private function nthRoot(string $value, int $root, int $scale, int $workingScale): string
    {
        if (bccomp($value, '0', $workingScale) === 0) {
            return $this->rounder->round('0', $scale);
        }

        $currentApproximation = bccomp($value, '1', $workingScale) >= 0 ? $value : '1';
        $rootMinusOne = (string) ($root - 1);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $denominator = Decimal::of($currentApproximation)->powerInt($root - 1, $workingScale)->value();

            if (bccomp($denominator, '0', $workingScale) === 0) {
                $currentApproximation = '1';

                continue;
            }

            $numeratorLeft = bcmul($rootMinusOne, $currentApproximation, $workingScale);
            $numeratorRight = bcdiv($value, $denominator, $workingScale);
            $nextApproximation = bcdiv(
                bcadd($numeratorLeft, $numeratorRight, $workingScale),
                (string) $root,
                $workingScale,
            );

            if (bccomp($nextApproximation, $currentApproximation, $scale) === 0) {
                return $this->rounder->round($nextApproximation, $scale);
            }

            $currentApproximation = $nextApproximation;
        }

        return $this->rounder->round($currentApproximation, $scale);
    }
}

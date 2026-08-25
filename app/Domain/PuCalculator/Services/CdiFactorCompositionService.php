<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\EmissionPuParameter;

final class CdiFactorCompositionService
{
    public function __construct(
        private readonly DailyFactorCalculator $dailyFactorCalculator,
        private readonly DecimalRounder $rounder,
    ) {}

    public function dailyIndexFactor(
        EmissionPuParameter $parameter,
        PuIndexRateRequirement $requirement,
    ): string {
        $factor = $this->dailyFactorCalculator->factorDiForDay(
            $requirement->rate?->value,
            $requirement->shouldApplyRate(),
            (int) $parameter->business_day_basis,
            DecimalRounder::CALCULATION_SCALE,
        );

        if ($parameter->index_rate_lookup_mode_enum !== PuIndexRateLookupMode::BusinessDayLagExact) {
            return $factor;
        }

        return $this->rounder->normalize(
            $this->rounder->round($factor, 8),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    public function accumulateIndexFactor(string $accumulatedFactor, string $dailyFactor): string
    {
        return $this->rounder->round(
            bcmul($accumulatedFactor, $dailyFactor, DecimalRounder::CALCULATION_SCALE + 4),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    public function spreadFactor(EmissionPuParameter $parameter, int $businessDays): string
    {
        $factor = $this->dailyFactorCalculator->factorSpreadForBusinessDays(
            (string) $parameter->spread_rate,
            $businessDays,
            (int) $parameter->business_day_basis,
            DecimalRounder::CALCULATION_SCALE,
        );

        if ($parameter->index_rate_lookup_mode_enum !== PuIndexRateLookupMode::BusinessDayLagExact) {
            return $factor;
        }

        return $this->rounder->normalize(
            $this->rounder->round($factor, 9),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    public function indexFactorForCombination(EmissionPuParameter $parameter, string $indexFactor): string
    {
        if ($parameter->index_rate_lookup_mode_enum !== PuIndexRateLookupMode::BusinessDayLagExact) {
            return $indexFactor;
        }

        return $this->rounder->normalize(
            $this->rounder->round($indexFactor, 8),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    public function combinedFactor(
        EmissionPuParameter $parameter,
        string $indexFactor,
        string $spreadFactor,
    ): string {
        return $this->rounder->round(
            bcmul(
                $this->indexFactorForCombination($parameter, $indexFactor),
                $spreadFactor,
                DecimalRounder::CALCULATION_SCALE + 4,
            ),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    public function factorForInterest(EmissionPuParameter $parameter, string $combinedFactor): string
    {
        if (! in_array($parameter->index_rate_lookup_mode_enum, [
            PuIndexRateLookupMode::BusinessDayLagExact,
            PuIndexRateLookupMode::PreviousCalendarDayExact,
        ], true)) {
            return $combinedFactor;
        }

        return $this->rounder->normalize(
            $this->rounder->round($combinedFactor, 9),
            DecimalRounder::CALCULATION_SCALE,
        );
    }
}

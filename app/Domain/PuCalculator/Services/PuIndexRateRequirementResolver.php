<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PuIndexRateRequirementResolver
{
    public function __construct(
        private readonly BusinessDayCalendar $businessDayCalendar,
        private readonly IndexRateProvider $indexRateProvider,
    ) {}

    public function resolve(
        EmissionPuParameter $parameter,
        CarbonImmutable $curveDate,
    ): PuIndexRateRequirement {
        $lookupMode = $parameter->index_rate_lookup_mode_enum;
        $calendarCode = (string) $parameter->calendar_code;
        $businessDayLag = (int) $parameter->index_rate_lag_business_days;
        $isBusinessDay = $this->businessDayCalendar->isBusinessDay($curveDate, $calendarCode);

        $lookupDate = match ($lookupMode) {
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => $isBusinessDay ? $curveDate : null,
            PuIndexRateLookupMode::PreviousCalendarDayExact => $curveDate->subDay(),
            PuIndexRateLookupMode::BusinessDayLagExact => $this->businessDayCalendar->shiftBusinessDays(
                $curveDate,
                $businessDayLag,
                $calendarCode,
            ),
        };

        $rate = match ($lookupMode) {
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => $lookupDate !== null
                ? $this->indexRateProvider->rateForDate($parameter->indexer_enum, $lookupDate)
                : null,
            PuIndexRateLookupMode::PreviousCalendarDayExact,
            PuIndexRateLookupMode::BusinessDayLagExact => $this->indexRateProvider->exactRateForDate(
                $parameter->indexer_enum,
                $lookupDate,
            ),
        };

        return new PuIndexRateRequirement(
            curveDate: $curveDate,
            lookupMode: $lookupMode,
            calendarCode: $calendarCode,
            businessDayLag: $businessDayLag,
            isBusinessDay: $isBusinessDay,
            lookupDate: $lookupDate,
            rate: $rate,
        );
    }

    /** @return list<CarbonImmutable> */
    public function firstCouponPreIntegralizationAccrualDates(EmissionPuParameter $parameter): array
    {
        if (! $parameter->hasFirstCouponPreIntegralizationPremium()) {
            return [];
        }

        $businessDays = (int) $parameter->first_coupon_pre_integralization_business_days;

        if ($businessDays <= 0) {
            throw new InvalidArgumentException(
                'O prêmio pré-integralização do primeiro cupom exige quantidade de Dias Úteis maior que zero.',
            );
        }

        if ($parameter->curve_start_date === null) {
            throw new InvalidArgumentException(
                'O prêmio pré-integralização do primeiro cupom exige a data inicial/integralização da curva.',
            );
        }

        $startDate = CarbonImmutable::instance($parameter->curve_start_date);
        $calendarCode = (string) $parameter->calendar_code;
        $dates = [];

        for ($offset = $businessDays; $offset >= 1; $offset--) {
            $dates[] = $this->businessDayCalendar->shiftBusinessDays(
                $startDate,
                -$offset,
                $calendarCode,
            );
        }

        return $dates;
    }

    /** @return list<PuIndexRateRequirement> */
    public function firstCouponPreIntegralizationRateRequirements(EmissionPuParameter $parameter): array
    {
        if (! $parameter->hasFirstCouponPreIntegralizationPremium()
            || ! (bool) $parameter->first_coupon_pre_integralization_apply_index_factor) {
            return [];
        }

        return array_map(
            fn (CarbonImmutable $accrualDate): PuIndexRateRequirement => $this->resolve($parameter, $accrualDate),
            $this->firstCouponPreIntegralizationAccrualDates($parameter),
        );
    }

    public function firstCouponPreIntegralizationFinancialCalendarStartDate(
        EmissionPuParameter $parameter,
    ): ?CarbonImmutable {
        $dates = $this->firstCouponPreIntegralizationAccrualDates($parameter);

        foreach ($this->firstCouponPreIntegralizationRateRequirements($parameter) as $requirement) {
            if ($requirement->lookupDate !== null) {
                $dates[] = $requirement->lookupDate;
            }
        }

        if ($dates === []) {
            return null;
        }

        usort(
            $dates,
            fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp(),
        );

        return $dates[0];
    }

    public function isAwaitingPublication(
        EmissionPuParameter $parameter,
        PuIndexRateRequirement $requirement,
        bool $hasPreviouslyResolvedRate,
    ): bool {
        $requiredRateDate = $requirement->requiredRateDate();

        if (
            ! $hasPreviouslyResolvedRate
            || $requirement->lookupMode !== PuIndexRateLookupMode::BusinessDayLagExact
            || $requiredRateDate === null
        ) {
            return false;
        }

        $lastAvailableRateDate = IndexRate::query()
            ->forIndexer($parameter->indexer_enum)
            ->max('rate_date');

        return $lastAvailableRateDate === null
            || $requiredRateDate->gt(CarbonImmutable::parse((string) $lastAvailableRateDate));
    }
}

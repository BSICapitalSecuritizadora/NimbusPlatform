<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuIndexCoverageReport;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;

class PuIndexCoverageService
{
    private const PROJECTED_SOURCE_REFERENCE = 'forward_projection';

    public function __construct(
        private readonly BusinessDayCalendarService $businessDayCalendar,
        private readonly IndexRateService $indexRateService,
        private readonly BusinessCalendarCoverageService $calendarCoverage,
    ) {}

    public function report(Emission $emission): PuIndexCoverageReport
    {
        $emission->loadMissing('puParameter');
        $parameter = $emission->puParameter;

        if ($parameter === null) {
            return new PuIndexCoverageReport(
                hasParameter: false,
                indexer: null,
                startDate: null,
                endDate: null,
                missingCalendarDates: [],
                missingIndexDates: [],
                projectedIndexDates: [],
                lastAvailableIndexDate: $this->lastAvailableIndexDate(null),
            );
        }

        $startDate = $parameter->curve_start_date !== null
            ? CarbonImmutable::instance($parameter->curve_start_date)
            : null;
        $endDate = $parameter->curve_end_date !== null
            ? CarbonImmutable::instance($parameter->curve_end_date)
            : null;

        $indexer = (string) $parameter->indexer;
        $lastAvailable = $this->lastAvailableIndexDate($indexer);

        if ($startDate === null || $endDate === null || $endDate->lt($startDate)) {
            return new PuIndexCoverageReport(
                hasParameter: true,
                indexer: $indexer,
                startDate: $startDate?->toDateString(),
                endDate: $endDate?->toDateString(),
                missingCalendarDates: [],
                missingIndexDates: [],
                projectedIndexDates: [],
                lastAvailableIndexDate: $lastAvailable,
            );
        }

        $missingCalendarDates = $this->missingCalendarDates($startDate, $endDate, (string) $parameter->calendar_code);
        [$missingIndexDates, $projectedIndexDates] = $this->indexCoverage($parameter, $startDate, $endDate);

        return new PuIndexCoverageReport(
            hasParameter: true,
            indexer: $indexer,
            startDate: $startDate->toDateString(),
            endDate: $endDate->toDateString(),
            missingCalendarDates: $missingCalendarDates,
            missingIndexDates: $missingIndexDates,
            projectedIndexDates: $projectedIndexDates,
            lastAvailableIndexDate: $lastAvailable,
        );
    }

    /**
     * @return list<string>
     */
    private function missingCalendarDates(CarbonImmutable $startDate, CarbonImmutable $endDate, string $calendarCode): array
    {
        // Calendarios auto-completaveis (B3) sao preenchidos automaticamente na geracao, logo nunca
        // representam um bloqueio real de cobertura para o dashboard/comando de dados faltantes.
        if ($calendarCode === '' || $this->calendarCoverage->willAutoComplete($calendarCode)) {
            return [];
        }

        $availableDates = BusinessCalendarDate::query()
            ->where('calendar_code', $calendarCode)
            ->whereDate('calendar_date', '>=', $startDate->toDateString())
            ->whereDate('calendar_date', '<=', $endDate->toDateString())
            ->pluck('calendar_date')
            ->mapWithKeys(fn ($calendarDate): array => [CarbonImmutable::parse((string) $calendarDate)->toDateString() => true])
            ->all();

        $missing = [];
        for ($currentDate = $startDate; $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            if (! isset($availableDates[$currentDate->toDateString()])) {
                $missing[] = $currentDate->toDateString();
            }
        }

        return $missing;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function indexCoverage(EmissionPuParameter $parameter, CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $missing = [];
        $projected = [];

        for ($currentDate = $startDate->addDay(); $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            try {
                $lookupDate = $this->requiredIndexLookupDate($parameter, $currentDate);
            } catch (\Throwable) {
                $missing[] = $currentDate->toDateString();

                continue;
            }

            if ($lookupDate === null) {
                continue;
            }

            $snapshot = match ($parameter->index_rate_lookup_mode_enum) {
                PuIndexRateLookupMode::PreviousAvailableBusinessDay => $this->indexRateService->rateForDate(
                    $parameter->indexer_enum,
                    $currentDate,
                ),
                PuIndexRateLookupMode::PreviousCalendarDayExact,
                PuIndexRateLookupMode::BusinessDayLagExact => $this->indexRateService->exactRateForDate(
                    $parameter->indexer_enum,
                    $lookupDate,
                ),
            };

            if ($snapshot === null) {
                $missing[] = $currentDate->toDateString();

                continue;
            }

            if ($snapshot->isProjected) {
                $projected[] = $currentDate->toDateString();
            }
        }

        return [$missing, $projected];
    }

    private function requiredIndexLookupDate(EmissionPuParameter $parameter, CarbonImmutable $currentDate): ?CarbonImmutable
    {
        $calendarCode = (string) $parameter->calendar_code;
        $isBusinessDay = $this->businessDayCalendar->isBusinessDay($currentDate, $calendarCode);

        return match ($parameter->index_rate_lookup_mode_enum) {
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => $isBusinessDay ? $currentDate : null,
            PuIndexRateLookupMode::PreviousCalendarDayExact => $this->businessDayCalendar->isBusinessDay($currentDate->subDay(), $calendarCode)
                ? $currentDate->subDay()
                : null,
            PuIndexRateLookupMode::BusinessDayLagExact => $isBusinessDay
                ? $this->businessDayCalendar->shiftBusinessDays(
                    $currentDate,
                    -((int) $parameter->index_rate_lag_business_days),
                    $calendarCode,
                )
                : null,
        };
    }

    private function lastAvailableIndexDate(?string $indexer): ?string
    {
        $query = IndexRate::query()
            ->where('source_reference', '!=', self::PROJECTED_SOURCE_REFERENCE);

        if ($indexer !== null) {
            $query->forIndexer($indexer);
        }

        $date = $query->max('rate_date');

        return $date !== null ? CarbonImmutable::parse((string) $date)->toDateString() : null;
    }
}

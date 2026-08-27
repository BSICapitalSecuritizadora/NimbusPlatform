<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarOverride;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;

final class NationalLegalCalendarReviewService
{
    public function __construct(
        private readonly BusinessCalendarYearService $calendarYears,
        private readonly NationalLegalHolidayDefinitionService $definitions,
    ) {}

    /** @return array{calendar_code:string,technical_coverage_satisfied:bool,administratively_confirmed:bool,years:array<int, array<string,mixed>>} */
    public function reviewRange(int $fromYear, int $toYear): array
    {
        $years = [];

        for ($year = $fromYear; $year <= $toYear; $year++) {
            $years[$year] = $this->reviewYear($year);
        }

        return [
            'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
            // Intervalo vazio não é cobertura: `collect([])->every()` devolveria
            // true e declararia pronto um período que ninguém revisou.
            'technical_coverage_satisfied' => $years !== [] && collect($years)->every(
                fn (array $review): bool => $review['technical_criteria_satisfied'],
            ),
            'administratively_confirmed' => $years !== [] && collect($years)->every(
                fn (array $review): bool => $review['governance_status'] === BusinessCalendarYear::STATUS_CONFIRMED,
            ),
            'years' => $years,
        ];
    }

    /** @return array<string, mixed> */
    public function reviewYear(int $year): array
    {
        $calendarCode = BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS;
        $coverage = $this->calendarYears->coverage($calendarCode, $year);
        $expectedDefinitions = $this->definitions->fixedHolidayDefinitionsForYear($year);
        $expectedDates = collect($expectedDefinitions)
            ->map(fn (array $definition): string => sprintf(
                '%04d-%02d-%02d',
                $year,
                $definition['month'],
                $definition['day'],
            ))
            ->sort()
            ->values();
        $holidays = BusinessHoliday::query()
            ->with('legalRule')
            ->where('calendar_code', $calendarCode)
            ->whereYear('holiday_date', $year)
            ->orderBy('holiday_date')
            ->get();
        $actualDates = $holidays
            ->map(fn (BusinessHoliday $holiday): string => $holiday->holiday_date->toDateString())
            ->toBase()
            ->sort()
            ->values();
        $latestRun = BusinessCalendarImportRun::query()
            ->where('calendar_code', $calendarCode)
            ->where('year', $year)
            ->where('dry_run', false)
            ->latest('id')
            ->first();
        $overrideCount = BusinessCalendarOverride::query()
            ->where('calendar_code', $calendarCode)
            ->whereYear('calendar_date', $year)
            ->count();
        $calendar = BusinessCalendar::query()->where('code', $calendarCode)->first();
        $exactLegalSet = $expectedDates->all() === $actualDates->all();
        $sourcesAndValidityDocumented = $holidays->count() === 9
            && $holidays->every(fn (BusinessHoliday $holiday): bool => $holiday->source_is_official
                && filled($holiday->source_document)
                && $holiday->legalRule !== null
                && filled($holiday->legalRule->effective_from)
                && filled($holiday->legalRule->norm_identification)
                && filled($holiday->legalRule->article_reference)
                && filled($holiday->legalRule->source_url));
        $checksumReproducible = filled($coverage['checksum'])
            && filled($latestRun?->checksum)
            && hash_equals((string) $coverage['checksum'], (string) $latestRun?->checksum);
        $zeroConflicts = (int) ($latestRun?->conflicts_detected ?? 0) === 0
            && (int) ($latestRun?->removals_detected ?? 0) === 0
            && ($latestRun?->errors ?? []) === [];
        $weekendTreatmentSatisfied = $calendar?->materialization_policy
            === BusinessCalendar::MATERIALIZATION_POLICY_WEEKDAY_WITH_OFFICIAL_EXCEPTIONS;
        $technicalCriteriaSatisfied = $coverage['coverage_status'] === 'complete'
            && $exactLegalSet
            && $sourcesAndValidityDocumented
            && $checksumReproducible
            && $zeroConflicts
            && $overrideCount === 0
            && $weekendTreatmentSatisfied;

        return [
            'year' => $year,
            'coverage_status' => $coverage['coverage_status'],
            'governance_status' => $coverage['governance_status'],
            'review_state' => match (true) {
                ! $technicalCriteriaSatisfied => 'technical_review_blocked',
                $coverage['governance_status'] === BusinessCalendarYear::STATUS_CONFIRMED => 'confirmed',
                default => 'ready_for_administrative_review',
            },
            'technical_criteria_satisfied' => $technicalCriteriaSatisfied,
            'holiday_count' => $holidays->count(),
            'expected_holiday_count' => 9,
            'holidays' => $holidays->map(fn (BusinessHoliday $holiday): array => [
                'date' => $holiday->holiday_date->toDateString(),
                'name' => $holiday->name,
                'source' => $holiday->legalRule?->norm_identification,
                'article' => $holiday->legalRule?->article_reference,
                'source_url' => $holiday->legalRule?->source_url,
                'effective_from' => $holiday->legalRule?->effective_from?->toDateString(),
                'effective_until' => $holiday->legalRule?->effective_until?->toDateString(),
                'fingerprint' => $holiday->legalRule?->source_fingerprint,
            ])->all(),
            'missing_legal_dates' => $expectedDates->diff($actualDates)->values()->all(),
            'unexpected_dates' => $actualDates->diff($expectedDates)->values()->all(),
            'excluded_observances' => [
                'carnival' => $exactLegalSet,
                'good_friday' => $exactLegalSet,
                'corpus_christi' => $exactLegalSet,
                'optional_government_holidays' => $exactLegalSet,
            ],
            'weekend_treatment' => $weekendTreatmentSatisfied
                ? 'Sábados e domingos são não úteis pela regra-base; não são materializados como feriados.'
                : 'Política de finais de semana incompatível.',
            'checksum' => $coverage['checksum'],
            'checksum_reproducible' => $checksumReproducible,
            'conflicts' => (int) ($latestRun?->conflicts_detected ?? 0),
            'overrides' => $overrideCount,
            'confirmed_at' => $coverage['confirmed_at'],
        ];
    }
}

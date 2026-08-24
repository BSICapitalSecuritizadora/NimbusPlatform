<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\DTOs\BusinessCalendarDecision;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarOverride;
use App\Models\BusinessCalendarYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BusinessCalendarService implements BusinessDayCalendar
{
    /** @var array<string, array<int, array<string, bool>>> */
    private array $cache = [];

    /** @var array<string, array<int, int>> */
    private array $loadedYears = [];

    /** @var array<string, bool> */
    private array $warnedRevisions = [];

    /** @var array<string, string> */
    private array $coverageBases = [];

    public function __construct(
        private readonly BusinessCalendarRevisionService $revisionService,
        private readonly BusinessCalendarYearService $yearService,
    ) {}

    public function flushCache(): void
    {
        $this->cache = [];
        $this->loadedYears = [];
        $this->warnedRevisions = [];
        $this->coverageBases = [];
    }

    public function isBusinessDay(CarbonImmutable $date, ?string $calendarCode = null): bool
    {
        $resolvedCalendarCode = BusinessCalendarRegistry::normalize($calendarCode ?? BusinessCalendarRegistry::LEGACY_B3);
        $year = $date->year;
        $dateKey = $date->toDateString();

        $this->loadYearIntoCache($resolvedCalendarCode, $year);

        if (array_key_exists($dateKey, $this->cache[$resolvedCalendarCode][$year] ?? [])) {
            return $this->cache[$resolvedCalendarCode][$year][$dateKey];
        }

        return $this->cache[$resolvedCalendarCode][$year][$dateKey] = ! $date->isWeekend();
    }

    public function explain(CarbonImmutable $date, ?string $calendarCode = null): BusinessCalendarDecision
    {
        $resolvedCalendarCode = BusinessCalendarRegistry::normalize($calendarCode ?? BusinessCalendarRegistry::LEGACY_B3);
        $calendarYear = BusinessCalendarYear::query()
            ->where('calendar_code', $resolvedCalendarCode)
            ->where('year', $date->year)
            ->first();
        $coverage = $this->yearService->coverage($resolvedCalendarCode, $date->year);
        $override = BusinessCalendarOverride::query()
            ->with('createdBy:id,name')
            ->where('calendar_code', $resolvedCalendarCode)
            ->whereDate('calendar_date', $date->toDateString())
            ->latest('applied_at')
            ->latest('id')
            ->first();
        $calendarDate = BusinessCalendarDate::query()
            ->where('calendar_code', $resolvedCalendarCode)
            ->whereDate('calendar_date', $date->toDateString())
            ->first();

        if ($override instanceof BusinessCalendarOverride) {
            return new BusinessCalendarDecision(
                isBusinessDay: (bool) $override->new_is_business_day,
                reason: sprintf('Override manual: %s', $override->reason),
                calendarCode: $resolvedCalendarCode,
                calendarLabel: BusinessCalendarRegistry::label($resolvedCalendarCode),
                source: 'manual_override',
                revision: (int) $override->revision,
                document: $calendarYear?->source_document,
                sourceRevision: $calendarYear?->source_revision,
                override: [
                    'id' => $override->id,
                    'reason' => $override->reason,
                    'previous_is_business_day' => (bool) $override->previous_is_business_day,
                    'new_is_business_day' => (bool) $override->new_is_business_day,
                    'applied_at' => $override->applied_at?->toIso8601String(),
                    'user_id' => $override->created_by,
                    'user_name' => $override->createdBy?->name,
                ],
                inferredWeekend: false,
                coverageState: $coverage['state'],
                yearStatus: $coverage['status'],
            );
        }

        if ($calendarDate instanceof BusinessCalendarDate) {
            $inferredWeekend = $calendarDate->data_origin === 'inferred' && $date->isWeekend();
            $reason = filled($calendarDate->description)
                ? (string) $calendarDate->description
                : ($calendarDate->is_business_day ? 'Dia útil persistido no calendário.' : 'Dia não útil persistido no calendário.');

            return new BusinessCalendarDecision(
                isBusinessDay: (bool) $calendarDate->is_business_day,
                reason: $reason,
                calendarCode: $resolvedCalendarCode,
                calendarLabel: BusinessCalendarRegistry::label($resolvedCalendarCode),
                source: $calendarDate->source ?? $calendarDate->data_origin,
                revision: (int) ($calendarDate->revision ?? $calendarYear?->revision ?? 0),
                document: $calendarDate->source_document ?? $calendarYear?->source_document,
                sourceRevision: $calendarDate->source_revision ?? $calendarYear?->source_revision,
                override: null,
                inferredWeekend: $inferredWeekend,
                coverageState: $coverage['state'],
                yearStatus: $coverage['status'],
            );
        }

        $isWeekend = $date->isWeekend();
        $usesOfficialBaseRule = $this->coverageBasis($resolvedCalendarCode)
            === BusinessCalendar::COVERAGE_BASIS_WEEKDAY_WITH_OFFICIAL_EXCEPTIONS;

        return new BusinessCalendarDecision(
            isBusinessDay: ! $isWeekend,
            reason: match (true) {
                $usesOfficialBaseRule && $isWeekend => 'Dia não útil derivado da regra-base oficial de segunda a sexta, complementada pelas exceções do calendário.',
                $usesOfficialBaseRule => 'Dia útil derivado da regra-base oficial de segunda a sexta; nenhuma exceção oficial incide nesta data.',
                $isWeekend => 'Dia não útil inferido exclusivamente por ser final de semana.',
                default => 'Dia útil inferido pela ausência de exceção persistida (fallback legado).',
            },
            calendarCode: $resolvedCalendarCode,
            calendarLabel: BusinessCalendarRegistry::label($resolvedCalendarCode),
            source: $usesOfficialBaseRule ? 'calendar_base_rule' : 'inferred',
            revision: (int) ($calendarYear?->revision ?? 0),
            document: $calendarYear?->source_document,
            sourceRevision: $calendarYear?->source_revision,
            override: null,
            inferredWeekend: $isWeekend,
            coverageState: $coverage['state'],
            yearStatus: $coverage['status'],
        );
    }

    public function nextBusinessDay(CarbonImmutable $date, ?string $calendarCode = null): CarbonImmutable
    {
        $candidate = $date;

        for ($attempt = 0; $attempt < 370; $attempt++) {
            if ($this->isBusinessDay($candidate, $calendarCode)) {
                return $candidate;
            }

            $candidate = $candidate->addDay();
        }

        throw new RuntimeException('Unable to resolve next business day for the requested date.');
    }

    public function shiftBusinessDays(CarbonImmutable $date, int $offset, ?string $calendarCode = null): CarbonImmutable
    {
        if ($offset === 0) {
            return $date;
        }

        $candidate = $date;
        $remaining = abs($offset);
        $step = $offset > 0 ? 1 : -1;

        for ($attempt = 0; $attempt < 3700; $attempt++) {
            $candidate = $step > 0 ? $candidate->addDay() : $candidate->subDay();

            if (! $this->isBusinessDay($candidate, $calendarCode)) {
                continue;
            }

            $remaining--;

            if ($remaining === 0) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to shift the requested number of business days.');
    }

    private function loadYearIntoCache(string $calendarCode, int $year): void
    {
        $revision = $this->revisionService->current($calendarCode, $year);

        if (($this->loadedYears[$calendarCode][$year] ?? null) === $revision) {
            return;
        }

        $this->cache[$calendarCode][$year] = [];

        $startOfYear = CarbonImmutable::create($year, 1, 1, 0, 0, 0);
        $endOfYear = $startOfYear->endOfYear();

        BusinessCalendarDate::query()
            ->where('calendar_code', $calendarCode)
            ->whereBetween('calendar_date', [$startOfYear->toDateString(), $endOfYear->toDateString()])
            ->get(['calendar_date', 'is_business_day'])
            ->each(function (BusinessCalendarDate $calendarDate) use ($calendarCode): void {
                if ($calendarDate->calendar_date === null) {
                    return;
                }

                $this->cache[$calendarCode][CarbonImmutable::instance($calendarDate->calendar_date)->year][
                    CarbonImmutable::instance($calendarDate->calendar_date)->toDateString()
                ] = (bool) $calendarDate->is_business_day;
            });

        $this->loadedYears[$calendarCode][$year] = $revision;
        $this->warnWhenUnconfirmed($calendarCode, $year, $revision);
    }

    private function warnWhenUnconfirmed(string $calendarCode, int $year, int $revision): void
    {
        $warningKey = sprintf('%s|%d|%d', $calendarCode, $year, $revision);

        if (isset($this->warnedRevisions[$warningKey])) {
            return;
        }

        $coverage = $this->yearService->coverage($calendarCode, $year);

        if (! $coverage['confirmed']) {
            Log::warning('Cálculo utilizando calendário anual não confirmado.', [
                'calendar_code' => $calendarCode,
                'year' => $year,
                'coverage_state' => $coverage['state'],
                'year_status' => $coverage['status'],
                'revision' => $revision,
            ]);
        }

        $this->warnedRevisions[$warningKey] = true;
    }

    private function coverageBasis(string $calendarCode): string
    {
        return $this->coverageBases[$calendarCode] ??= BusinessCalendar::query()
            ->where('code', $calendarCode)
            ->first()
            ?->coverageBasis() ?? BusinessCalendar::COVERAGE_BASIS_EXPLICIT_DATES;
    }
}

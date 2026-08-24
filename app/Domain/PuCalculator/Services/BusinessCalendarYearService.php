<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarOverride;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BusinessCalendarYearService
{
    public function __construct(
        private readonly BusinessCalendarRevisionService $revisionService,
        private readonly BusinessCalendarCatalogService $catalog,
    ) {}

    /**
     * Deve ser chamado dentro de uma transação e sob lock do calendário.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function findOrCreateForUpdate(string $calendarCode, int $year, array $attributes = []): BusinessCalendarYear
    {
        $calendarCode = $this->catalog->findOrFail($calendarCode)->code;

        $calendarYear = BusinessCalendarYear::query()
            ->where('calendar_code', $calendarCode)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($calendarYear instanceof BusinessCalendarYear) {
            return $calendarYear;
        }

        return BusinessCalendarYear::query()->create([
            'calendar_code' => $calendarCode,
            'year' => $year,
            'status' => BusinessCalendarYear::STATUS_PROVISIONAL,
            ...$attributes,
        ]);
    }

    /**
     * @return array{year:int,calendar_year_id:?int,state:string,status:?string,coverage_status:string,governance_status:?string,coverage_basis:string,expected_days:int,covered_days:int,missing_days:int,confirmed:bool,revision:int,checksum:?string,source_revision:?string,source_is_official:bool,source_documented:bool,confirmed_at:?string}
     */
    public function coverage(string $calendarCode, int $year): array
    {
        $calendarCode = BusinessCalendarRegistry::normalize($calendarCode);
        $calendar = BusinessCalendar::query()->where('code', $calendarCode)->first();
        $coverageBasis = $calendar?->coverageBasis()
            ?? BusinessCalendar::COVERAGE_BASIS_EXPLICIT_DATES;
        $expectedDays = CarbonImmutable::create($year, 1, 1)->isLeapYear() ? 366 : 365;
        $coveredDays = BusinessCalendarDate::query()
            ->where('calendar_code', $calendarCode)
            ->whereYear('calendar_date', $year)
            ->count();
        $calendarYear = BusinessCalendarYear::query()
            ->where('calendar_code', $calendarCode)
            ->where('year', $year)
            ->first([
                'id', 'status', 'revision', 'checksum', 'source_revision',
                'source_is_official', 'source_document', 'confirmed_at',
            ]);
        $latestAppliedRun = $coveredDays < $expectedDays
            && $coverageBasis === BusinessCalendar::COVERAGE_BASIS_WEEKDAY_WITH_OFFICIAL_EXCEPTIONS
                ? BusinessCalendarImportRun::query()
                    ->where('calendar_code', $calendarCode)
                    ->where('year', $year)
                    ->where('dry_run', false)
                    ->latest('started_at')
                    ->latest('id')
                    ->first()
                : null;
        $sourceIsOfficial = (bool) ($calendarYear?->source_is_official ?? false);
        $sourceDocumented = filled($calendarYear?->source_document);
        $coverageStatus = $this->resolveCoverageStatus(
            $coverageBasis,
            $coveredDays,
            $expectedDays,
            $calendarYear,
            $latestAppliedRun,
        );
        $state = $this->resolveCoverageState(
            $coverageStatus,
            $calendarYear?->status,
            $sourceIsOfficial,
            $sourceDocumented,
        );

        return [
            'year' => $year,
            'calendar_year_id' => $calendarYear?->id,
            'state' => $state,
            'status' => $calendarYear?->status,
            'coverage_status' => $coverageStatus,
            'governance_status' => $calendarYear?->status,
            'coverage_basis' => $coverageBasis,
            'expected_days' => $expectedDays,
            'covered_days' => $coveredDays,
            'missing_days' => max(0, $expectedDays - $coveredDays),
            'confirmed' => $state === 'confirmed',
            'revision' => (int) ($calendarYear?->revision ?? 0),
            'checksum' => $calendarYear?->checksum,
            'source_revision' => $calendarYear?->source_revision,
            'source_is_official' => $sourceIsOfficial,
            'source_documented' => $sourceDocumented,
            'confirmed_at' => $calendarYear?->confirmed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{year:int,calendar_year_id:?int,state:string,status:?string,coverage_status:string,governance_status:?string,coverage_basis:string,expected_days:int,covered_days:int,missing_days:int,confirmed:bool,revision:int,checksum:?string,source_revision:?string,source_is_official:bool,source_documented:bool,confirmed_at:?string}>
     */
    public function coverageForRange(string $calendarCode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $coverage = [];

        for ($year = $from->year; $year <= $to->year; $year++) {
            $coverage[$year] = $this->coverage($calendarCode, $year);
        }

        return $coverage;
    }

    public function confirm(
        string $calendarCode,
        int $year,
        string $source,
        string $sourceDocument,
        ?string $sourceRevision,
        ?string $checksum,
        int $confirmedByUserId,
        bool $sourceIsOfficial = true,
    ): BusinessCalendarYear {
        $catalogCalendar = $this->catalog->findOrFail($calendarCode);
        $calendarCode = $catalogCalendar->code;

        if ($catalogCalendar->is_official && ! $sourceIsOfficial) {
            throw new InvalidArgumentException(sprintf(
                'O calendário oficial %s só pode ser confirmado com uma fonte marcada como oficial.',
                $calendarCode,
            ));
        }

        $calendarYear = Cache::lock($this->lockKey($calendarCode), 30)
            ->block((int) config('pu_calculator.business_calendar.lock_wait_seconds', 15), function () use (
                $calendarCode,
                $year,
                $source,
                $sourceDocument,
                $sourceRevision,
                $checksum,
                $confirmedByUserId,
                $sourceIsOfficial,
            ): BusinessCalendarYear {
                return DB::transaction(function () use (
                    $calendarCode,
                    $year,
                    $source,
                    $sourceDocument,
                    $sourceRevision,
                    $checksum,
                    $confirmedByUserId,
                    $sourceIsOfficial,
                ): BusinessCalendarYear {
                    $coverage = $this->coverage($calendarCode, $year);

                    if ($coverage['state'] === 'missing' || $coverage['state'] === 'partial') {
                        throw new InvalidArgumentException(sprintf(
                            'O calendário %s/%d está incompleto: %d de %d datas cobertas.',
                            $calendarCode,
                            $year,
                            $coverage['covered_days'],
                            $coverage['expected_days'],
                        ));
                    }

                    $lastRun = BusinessCalendarImportRun::query()
                        ->where('calendar_code', $calendarCode)
                        ->where('year', $year)
                        ->where('dry_run', false)
                        ->latest('started_at')
                        ->first(['conflicts_detected', 'errors']);
                    $lastRunConflicts = (int) ($lastRun?->conflicts_detected ?? 0);

                    if ($lastRunConflicts > 0 || ($lastRun?->errors ?? []) !== []) {
                        throw new InvalidArgumentException(sprintf(
                            'O calendário %s/%d possui %d conflito(s) ou erro(s) na última execução. Resolva-os antes de confirmar.',
                            $calendarCode,
                            $year,
                            $lastRunConflicts,
                        ));
                    }

                    $calendarYear = $this->findOrCreateForUpdate($calendarCode, $year);
                    $calendarYear->fill([
                        'status' => BusinessCalendarYear::STATUS_CONFIRMED,
                        'source' => $source,
                        'source_is_official' => $sourceIsOfficial,
                        'source_document' => $sourceDocument,
                        'source_revision' => $sourceRevision,
                        'checksum' => $checksum,
                        'confirmed_at' => now(),
                        'confirmed_by' => $confirmedByUserId,
                        'revision' => ((int) $calendarYear->revision) + 1,
                    ])->save();

                    return $calendarYear->fresh();
                });
            });

        $this->revisionService->publish($calendarYear);

        return $calendarYear;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function administrativeSummary(): array
    {
        $rows = [];
        $definitions = $this->catalog->definitions();

        foreach (array_keys($definitions) as $calendarCode) {
            $calendar = $this->catalog->findOrFail($calendarCode);
            $calendarYears = BusinessCalendarYear::query()
                ->with('confirmedBy:id,name')
                ->where('calendar_code', $calendarCode)
                ->get()
                ->keyBy('year');
            $dateStatistics = BusinessCalendarDate::query()
                ->where('calendar_code', $calendarCode)
                ->selectRaw('SUBSTR(calendar_date, 1, 4) AS calendar_year')
                ->selectRaw('COUNT(*) AS covered_days')
                ->selectRaw('SUM(CASE WHEN is_business_day = 0 THEN 1 ELSE 0 END) AS non_business_days')
                ->groupByRaw('SUBSTR(calendar_date, 1, 4)')
                ->get()
                ->keyBy('calendar_year');
            $holidayYears = BusinessHoliday::query()
                ->where('calendar_code', $calendarCode)
                ->selectRaw('SUBSTR(holiday_date, 1, 4) AS calendar_year')
                ->distinct()
                ->pluck('calendar_year');
            $latestAttemptIds = BusinessCalendarImportRun::query()
                ->where('calendar_code', $calendarCode)
                ->whereNotNull('year')
                ->selectRaw('MAX(id) AS id')
                ->groupBy('year')
                ->pluck('id');
            $latestAttempts = BusinessCalendarImportRun::query()
                ->whereKey($latestAttemptIds)
                ->get()
                ->keyBy('year');
            $latestAppliedIds = BusinessCalendarImportRun::query()
                ->where('calendar_code', $calendarCode)
                ->whereNotNull('year')
                ->where('dry_run', false)
                ->whereIn('result', [
                    BusinessCalendarImportRun::RESULT_SUCCEEDED,
                    BusinessCalendarImportRun::RESULT_CONFLICTS,
                    BusinessCalendarImportRun::RESULT_COMPLETED_WITH_ERRORS,
                ])
                ->selectRaw('MAX(id) AS id')
                ->groupBy('year')
                ->pluck('id');
            $latestAppliedRuns = BusinessCalendarImportRun::query()
                ->whereKey($latestAppliedIds)
                ->get()
                ->keyBy('year');
            $overrideCounts = BusinessCalendarOverride::query()
                ->where('calendar_code', $calendarCode)
                ->selectRaw('SUBSTR(calendar_date, 1, 4) AS calendar_year')
                ->selectRaw('COUNT(*) AS override_count')
                ->groupByRaw('SUBSTR(calendar_date, 1, 4)')
                ->get()
                ->pluck('override_count', 'calendar_year');
            $years = collect()
                ->merge($calendarYears->keys())
                ->merge($dateStatistics->keys())
                ->merge($holidayYears)
                ->merge($latestAttempts->keys())
                ->merge($latestAppliedRuns->keys())
                ->merge($overrideCounts->keys())
                ->filter()
                ->map(static fn ($year): int => (int) $year)
                ->unique()
                ->sortDesc();

            foreach ($years as $year) {
                $calendarYear = $calendarYears->get($year);
                $statistics = $dateStatistics->get($year);
                $lastAttempt = $latestAttempts->get($year);
                $lastApplied = $latestAppliedRuns->get($year);
                $expectedDays = CarbonImmutable::create((int) $year, 1, 1)->isLeapYear() ? 366 : 365;
                $coveredDays = (int) ($statistics?->covered_days ?? 0);
                $coverageStatus = $this->resolveCoverageStatus(
                    $calendar->coverageBasis(),
                    $coveredDays,
                    $expectedDays,
                    $calendarYear,
                    $lastApplied,
                );
                $state = $this->resolveCoverageState(
                    $coverageStatus,
                    $calendarYear?->status,
                    (bool) ($calendarYear?->source_is_official ?? false),
                    filled($calendarYear?->source_document),
                );

                $rows[] = [
                    'calendar_code' => $calendarCode,
                    'calendar_label' => $definitions[$calendarCode]['label'],
                    'meaning' => $definitions[$calendarCode]['meaning'],
                    'year' => $year,
                    'state' => $state,
                    'status' => $calendarYear?->status,
                    'coverage_status' => $coverageStatus,
                    'governance_status' => $calendarYear?->status,
                    'coverage_basis' => $calendar->coverageBasis(),
                    'expected_days' => $expectedDays,
                    'covered_days' => $coveredDays,
                    'missing_days' => max(0, $expectedDays - $coveredDays),
                    'confirmed' => $state === 'confirmed',
                    'revision' => (int) ($calendarYear?->revision ?? 0),
                    'source' => $calendarYear?->source,
                    'source_is_official' => $calendarYear?->source_is_official,
                    'source_document' => $calendarYear?->source_document,
                    'source_revision' => $calendarYear?->source_revision,
                    'checksum' => $calendarYear?->checksum,
                    'confirmed_at' => $calendarYear?->confirmed_at,
                    'confirmed_by' => $calendarYear?->confirmedBy?->name,
                    'last_attempt_at' => $lastAttempt?->started_at,
                    'last_attempt_result' => $lastAttempt?->result,
                    'last_import_at' => $lastApplied?->finished_at,
                    'non_business_days' => (int) ($statistics?->non_business_days ?? 0),
                    'conflicts' => (int) ($lastAttempt?->conflicts_detected ?? 0),
                    'overrides' => (int) ($overrideCounts->get($year) ?? 0),
                ];
            }
        }

        return $rows;
    }

    public function lockKey(string $calendarCode): string
    {
        return sprintf('business-calendar:mutation:%s', strtoupper($calendarCode));
    }

    private function resolveCoverageStatus(
        string $coverageBasis,
        int $coveredDays,
        int $expectedDays,
        ?BusinessCalendarYear $calendarYear,
        ?BusinessCalendarImportRun $latestAppliedRun,
    ): string {
        if ($coveredDays === $expectedDays) {
            return 'complete';
        }

        $hasCompleteOfficialExceptionSet = $coverageBasis === BusinessCalendar::COVERAGE_BASIS_WEEKDAY_WITH_OFFICIAL_EXCEPTIONS
            && $calendarYear instanceof BusinessCalendarYear
            && $calendarYear->source_is_official
            && filled($calendarYear->source_document)
            && filled($calendarYear->checksum)
            && $latestAppliedRun instanceof BusinessCalendarImportRun
            && $latestAppliedRun->source_is_official
            && $latestAppliedRun->result === BusinessCalendarImportRun::RESULT_SUCCEEDED
            && (int) $latestAppliedRun->conflicts_detected === 0
            && (int) $latestAppliedRun->removals_detected === 0
            && ($latestAppliedRun->errors ?? []) === []
            && hash_equals((string) $calendarYear->checksum, (string) $latestAppliedRun->checksum);

        if ($hasCompleteOfficialExceptionSet) {
            return 'complete';
        }

        $hasAnyCoverageEvidence = $coveredDays > 0
            || $calendarYear instanceof BusinessCalendarYear
            || $latestAppliedRun instanceof BusinessCalendarImportRun;

        return $hasAnyCoverageEvidence ? 'partial' : 'none';
    }

    private function resolveCoverageState(
        string $coverageStatus,
        ?string $yearStatus,
        bool $sourceIsOfficial,
        bool $sourceDocumented,
    ): string {
        return match (true) {
            $coverageStatus === 'none' => 'missing',
            $coverageStatus === 'partial' => 'partial',
            $yearStatus === BusinessCalendarYear::STATUS_STALE => 'stale',
            $yearStatus === BusinessCalendarYear::STATUS_CONFIRMED && $sourceIsOfficial && $sourceDocumented => 'confirmed',
            $yearStatus === BusinessCalendarYear::STATUS_CONFIRMED => 'stale',
            default => 'provisional',
        };
    }
}

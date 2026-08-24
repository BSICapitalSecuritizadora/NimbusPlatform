<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
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
     * @return array{state:string,status:?string,expected_days:int,covered_days:int,missing_days:int,confirmed:bool,revision:int,source_is_official:bool,source_documented:bool}
     */
    public function coverage(string $calendarCode, int $year): array
    {
        $calendarCode = BusinessCalendarRegistry::normalize($calendarCode);
        $expectedDays = CarbonImmutable::create($year, 1, 1)->isLeapYear() ? 366 : 365;
        $coveredDays = BusinessCalendarDate::query()
            ->where('calendar_code', $calendarCode)
            ->whereYear('calendar_date', $year)
            ->count();
        $calendarYear = BusinessCalendarYear::query()
            ->where('calendar_code', $calendarCode)
            ->where('year', $year)
            ->first(['status', 'revision', 'source_is_official', 'source_document']);
        $sourceIsOfficial = (bool) ($calendarYear?->source_is_official ?? false);
        $sourceDocumented = filled($calendarYear?->source_document);
        $state = $this->resolveCoverageState(
            $coveredDays,
            $expectedDays,
            $calendarYear?->status,
            $sourceIsOfficial,
            $sourceDocumented,
        );

        return [
            'state' => $state,
            'status' => $calendarYear?->status,
            'expected_days' => $expectedDays,
            'covered_days' => $coveredDays,
            'missing_days' => max(0, $expectedDays - $coveredDays),
            'confirmed' => $state === 'confirmed',
            'revision' => (int) ($calendarYear?->revision ?? 0),
            'source_is_official' => $sourceIsOfficial,
            'source_documented' => $sourceDocumented,
        ];
    }

    /**
     * @return array<int, array{year:int,state:string,status:?string,expected_days:int,covered_days:int,missing_days:int,confirmed:bool,revision:int,source_is_official:bool,source_documented:bool}>
     */
    public function coverageForRange(string $calendarCode, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $coverage = [];

        for ($year = $from->year; $year <= $to->year; $year++) {
            $coverage[$year] = ['year' => $year, ...$this->coverage($calendarCode, $year)];
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
                $state = $this->resolveCoverageState(
                    $coveredDays,
                    $expectedDays,
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

    private function resolveCoverageState(
        int $coveredDays,
        int $expectedDays,
        ?string $yearStatus,
        bool $sourceIsOfficial,
        bool $sourceDocumented,
    ): string {
        return match (true) {
            $coveredDays === 0 => 'missing',
            $yearStatus === BusinessCalendarYear::STATUS_STALE => 'stale',
            $coveredDays < $expectedDays => 'partial',
            $yearStatus === BusinessCalendarYear::STATUS_CONFIRMED && $sourceIsOfficial && $sourceDocumented => 'confirmed',
            $yearStatus === BusinessCalendarYear::STATUS_CONFIRMED => 'stale',
            default => 'provisional',
        };
    }
}

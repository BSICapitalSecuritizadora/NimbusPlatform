<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendar;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Activitylog\LogBatch;

final class B3ListedCalendarSanitationService
{
    public const EXPECTED_COUNT = 3287;

    public const EXPECTED_FROM = '2021-01-01';

    public const EXPECTED_TO = '2029-12-31';

    public const EXPECTED_CREATED_AT = '2026-08-24 18:25:38';

    public const SANITATION_REVISION = 'phase-2b.5.2-b3-inferred-20260824';

    public function __construct(
        private readonly BusinessCalendarYearService $calendarYears,
        private readonly BusinessCalendarRevisionService $revisions,
    ) {}

    /** @return array<string, mixed> */
    public function audit(): array
    {
        return $this->buildAudit($this->strictCandidateQuery()->get());
    }

    /**
     * @return array{audit:array<string,mixed>,after:array<string,mixed>,removed_dates:int,removed_years:int,batch_uuid:string,import_run_id:int,activity_id:?int}
     */
    public function sanitize(string $expectedChecksum, int $responsibleUserId): array
    {
        $responsible = User::query()->findOrFail($responsibleUserId);
        $changedYears = range(2021, 2029);

        $result = Cache::lock($this->calendarYears->lockKey(BusinessCalendarRegistry::B3_LISTED_TRADING), 120)
            ->block((int) config('pu_calculator.business_calendar.lock_wait_seconds', 15), function () use (
                $expectedChecksum,
                $responsible,
            ): array {
                return app(LogBatch::class)->withinBatch(function (?string $batchUuid) use (
                    $expectedChecksum,
                    $responsible,
                ): array {
                    if (! is_string($batchUuid)) {
                        throw new InvalidArgumentException('Não foi possível criar o batch UUID de auditoria.');
                    }

                    return DB::transaction(function () use ($expectedChecksum, $responsible, $batchUuid): array {
                        $calendar = BusinessCalendar::query()
                            ->where('code', BusinessCalendarRegistry::B3_LISTED_TRADING)
                            ->lockForUpdate()
                            ->firstOrFail();
                        $candidates = $this->strictCandidateQuery()->lockForUpdate()->get();
                        $audit = $this->buildAudit($candidates);

                        if (! $audit['safe_to_execute']) {
                            throw new InvalidArgumentException('Os gates de segurança do saneamento B3 Listed não foram satisfeitos.');
                        }

                        if (! hash_equals((string) $audit['checksum'], strtolower(trim($expectedChecksum)))) {
                            throw new InvalidArgumentException('O checksum informado não corresponde ao conjunto reaudidato.');
                        }

                        $candidateIds = $candidates->modelKeys();
                        $candidateYearIds = $candidates->pluck('business_calendar_year_id')->filter()->unique()->values()->all();
                        $removedDates = $this->strictCandidateQuery()
                            ->whereKey($candidateIds)
                            ->delete();

                        if ($removedDates !== self::EXPECTED_COUNT) {
                            throw new InvalidArgumentException(sprintf(
                                'Saneamento abortado: esperado remover %d linhas, mas %d atenderam ao predicado final.',
                                self::EXPECTED_COUNT,
                                $removedDates,
                            ));
                        }

                        $removedYears = 0;

                        foreach ($candidateYearIds as $calendarYearId) {
                            $calendarYear = BusinessCalendarYear::query()
                                ->whereKey($calendarYearId)
                                ->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)
                                ->where('status', BusinessCalendarYear::STATUS_PROVISIONAL)
                                ->where('source', 'calendar_inference')
                                ->where('source_is_official', false)
                                ->whereNull('source_document')
                                ->whereNull('source_revision')
                                ->whereNull('checksum')
                                ->whereNull('confirmed_at')
                                ->whereNull('confirmed_by')
                                ->lockForUpdate()
                                ->first();

                            if (! $calendarYear instanceof BusinessCalendarYear) {
                                throw new InvalidArgumentException('Metadado anual inferido deixou de atender ao predicado de segurança.');
                            }

                            $hasRelatedFacts = DB::table('business_calendar_dates')->where('business_calendar_year_id', $calendarYear->id)->exists()
                                || DB::table('business_holidays')->where('business_calendar_year_id', $calendarYear->id)->exists()
                                || DB::table('business_calendar_overrides')->where('business_calendar_year_id', $calendarYear->id)->exists()
                                || DB::table('business_calendar_import_runs')->where('business_calendar_year_id', $calendarYear->id)->exists();

                            if ($hasRelatedFacts) {
                                throw new InvalidArgumentException(sprintf(
                                    'Ano %d preservado porque recebeu vínculo posterior ao conjunto inferido.',
                                    $calendarYear->year,
                                ));
                            }

                            $calendarYear->delete();
                            $removedYears++;
                        }

                        $calendar->update([
                            'is_official' => false,
                            'financial_use_allowed' => false,
                            'available_for_new_configurations' => false,
                            'status' => 'awaiting_official_source',
                        ]);

                        $after = $this->calendarSnapshot();
                        $run = BusinessCalendarImportRun::query()->create([
                            'batch_uuid' => $batchUuid,
                            'calendar_code' => BusinessCalendarRegistry::B3_LISTED_TRADING,
                            'source' => 'controlled_sanitation',
                            'source_is_official' => false,
                            'source_document' => 'Auditoria documental Fase 2B.5.1 e saneamento Fase 2B.5.2',
                            'source_revision' => self::SANITATION_REVISION,
                            'checksum' => $audit['checksum'],
                            'started_at' => Date::now(),
                            'finished_at' => Date::now(),
                            'triggered_by' => $responsible->id,
                            'triggered_by_process' => 'pu:business-calendar:sanitize-b3-listed',
                            'records_found' => $audit['candidate_count'],
                            'records_changed' => $removedDates,
                            'removals_detected' => $removedDates,
                            'result' => BusinessCalendarImportRun::RESULT_SUCCEEDED,
                            'dry_run' => false,
                        ]);
                        $activity = activity('business_calendars')
                            ->performedOn($calendar)
                            ->causedBy($responsible)
                            ->withProperties([
                                'batch_uuid' => $batchUuid,
                                'sanitation_revision' => self::SANITATION_REVISION,
                                'strict_predicate' => $audit['strict_predicate'],
                                'safety_gates' => $audit['gates'],
                                'before' => [
                                    'count' => $audit['calendar_count'],
                                    'candidate_count' => $audit['candidate_count'],
                                    'from' => $audit['calendar_from'],
                                    'to' => $audit['calendar_to'],
                                    'checksum' => $audit['checksum'],
                                    'origins' => $audit['origins'],
                                    'governance' => $audit['governance'],
                                ],
                                'after' => $after,
                                'removed_dates' => $removedDates,
                                'removed_years' => $removedYears,
                                'reason' => 'Decisões segunda–sexta materializadas sem fonte oficial de sessões B3.',
                                'import_run_id' => $run->id,
                            ])
                            ->event('sanitized')
                            ->log('business_calendar_b3_listed_inferred_set_removed');

                        return [
                            'audit' => $audit,
                            'after' => $after,
                            'removed_dates' => $removedDates,
                            'removed_years' => $removedYears,
                            'batch_uuid' => $batchUuid,
                            'import_run_id' => $run->id,
                            'activity_id' => $activity?->getKey(),
                        ];
                    });
                });
            });

        foreach ($changedYears as $year) {
            $this->revisions->forget(BusinessCalendarRegistry::B3_LISTED_TRADING, $year);
        }

        return $result;
    }

    /** @return Builder<BusinessCalendarDate> */
    private function strictCandidateQuery(): Builder
    {
        return BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)
            ->whereBetween('calendar_date', [self::EXPECTED_FROM, self::EXPECTED_TO])
            ->where('data_origin', 'inferred')
            ->where('source', 'calendar_inference')
            ->where('source_is_official', false)
            ->whereNull('source_document')
            ->whereNull('source_revision')
            ->whereNull('import_run_id')
            ->where('revision', 2)
            ->where('created_at', self::EXPECTED_CREATED_AT)
            ->where('updated_at', self::EXPECTED_CREATED_AT);
    }

    /**
     * @param  Collection<int, BusinessCalendarDate>  $candidates
     * @return array<string, mixed>
     */
    private function buildAudit(Collection $candidates): array
    {
        $calendarStatistics = BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)
            ->selectRaw('COUNT(*) AS row_count, MIN(calendar_date) AS date_from, MAX(calendar_date) AS date_to')
            ->first();
        $candidateYears = $candidates->pluck('business_calendar_year_id')->filter()->unique()->values();
        $yearRows = BusinessCalendarYear::query()->whereKey($candidateYears)->get();
        $consumerCounts = $this->consumerCounts();
        $allWeekdayPattern = $candidates->every(fn (BusinessCalendarDate $date): bool => $date->is_business_day === ! $date->calendar_date->isWeekend());
        $candidateChecksum = $this->fingerprint($candidates);
        $candidateFrom = $candidates->min(fn (BusinessCalendarDate $date): ?string => $date->calendar_date?->toDateString());
        $candidateTo = $candidates->max(fn (BusinessCalendarDate $date): ?string => $date->calendar_date?->toDateString());
        $candidateYearsAreDisposable = $candidateYears->count() === 9
            && $yearRows->count() === 9
            && $yearRows->every(fn (BusinessCalendarYear $year): bool => $year->status === BusinessCalendarYear::STATUS_PROVISIONAL
                && $year->source === 'calendar_inference'
                && ! $year->source_is_official
                && $year->source_document === null
                && $year->source_revision === null
                && $year->checksum === null
                && $year->confirmed_at === null
                && $year->confirmed_by === null);
        $candidateProvenanceIsExact = $candidates->every(fn (BusinessCalendarDate $date): bool => $date->data_origin === 'inferred'
            && $date->source === 'calendar_inference'
            && ! $date->source_is_official
            && $date->source_document === null
            && $date->source_revision === null
            && $date->import_run_id === null
            && (int) $date->revision === 2);
        $gates = [
            'expected_count' => $candidates->count() === self::EXPECTED_COUNT,
            'expected_period' => $candidateFrom === self::EXPECTED_FROM && $candidateTo === self::EXPECTED_TO,
            'exact_inferred_provenance' => $candidateProvenanceIsExact,
            'weekday_only_pattern' => $allWeekdayPattern,
            'no_financial_consumers' => array_sum($consumerCounts) === 0,
            'candidate_years_provisional_unconfirmed' => $candidateYearsAreDisposable,
            'no_confirmed_years' => BusinessCalendarYear::query()
                ->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)
                ->where('status', BusinessCalendarYear::STATUS_CONFIRMED)
                ->doesntExist(),
            'no_official_link_on_candidates' => $candidates->every(fn (BusinessCalendarDate $date): bool => ! $date->source_is_official
                && $date->source_document === null
                && $date->import_run_id === null),
        ];

        return [
            'calendar_code' => BusinessCalendarRegistry::B3_LISTED_TRADING,
            'calendar_count' => (int) ($calendarStatistics?->row_count ?? 0),
            'calendar_from' => $calendarStatistics?->date_from,
            'calendar_to' => $calendarStatistics?->date_to,
            'candidate_count' => $candidates->count(),
            'candidate_from' => $candidateFrom,
            'candidate_to' => $candidateTo,
            'checksum' => $candidateChecksum,
            'origins' => $candidates->groupBy(fn (BusinessCalendarDate $date): string => (string) $date->data_origin)->map->count()->all(),
            'sources' => $candidates->groupBy(fn (BusinessCalendarDate $date): string => (string) $date->source)->map->count()->all(),
            'revisions' => $candidates->groupBy(fn (BusinessCalendarDate $date): string => (string) $date->revision)->map->count()->all(),
            'created_at' => $candidates->pluck('created_at')->filter()->map->format('Y-m-d H:i:s')->unique()->values()->all(),
            'updated_at' => $candidates->pluck('updated_at')->filter()->map->format('Y-m-d H:i:s')->unique()->values()->all(),
            'batch_uuid' => null,
            'related' => [
                'holidays' => DB::table('business_holidays')->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count(),
                'overrides' => DB::table('business_calendar_overrides')->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count(),
                'import_runs' => DB::table('business_calendar_import_runs')->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count(),
                'staging_batches' => DB::table('business_calendar_staging_batches')->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count(),
            ],
            'consumers' => $consumerCounts,
            'governance' => $yearRows->map(fn (BusinessCalendarYear $year): array => [
                'year' => $year->year,
                'status' => $year->status,
                'source' => $year->source,
                'source_is_official' => $year->source_is_official,
                'checksum' => $year->checksum,
                'confirmed_at' => $year->confirmed_at?->toIso8601String(),
                'confirmed_by' => $year->confirmed_by,
            ])->sortBy('year')->values()->all(),
            'strict_predicate' => [
                'period' => [self::EXPECTED_FROM, self::EXPECTED_TO],
                'data_origin' => 'inferred',
                'source' => 'calendar_inference',
                'source_is_official' => false,
                'source_document' => null,
                'source_revision' => null,
                'import_run_id' => null,
                'revision' => 2,
                'created_at' => self::EXPECTED_CREATED_AT,
                'updated_at' => self::EXPECTED_CREATED_AT,
            ],
            'gates' => $gates,
            'safe_to_execute' => ! in_array(false, $gates, true),
        ];
    }

    /** @return array<string, int> */
    private function consumerCounts(): array
    {
        return [
            'emission_pu_parameters' => DB::table('emission_pu_parameters')->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count(),
            'obligation_series' => DB::table('obligation_series')->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count(),
            'obligation_series_rules' => DB::table('obligation_series_rules')->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count(),
            'selection_evidence' => DB::table('business_calendar_selection_evidence')->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count(),
            'homologations' => Schema::hasTable('pu_calendar_homologations')
                ? DB::table('pu_calendar_homologations')->where('candidate_calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)->count()
                : 0,
        ];
    }

    /** @param Collection<int, BusinessCalendarDate> $rows */
    private function fingerprint(Collection $rows): string
    {
        $context = hash_init('sha256');

        $rows->sortBy(fn (BusinessCalendarDate $row): string => $row->calendar_date?->toDateString() ?? '')
            ->each(function (BusinessCalendarDate $row) use ($context): void {
                $canonical = [
                    'calendar_date' => $row->calendar_date?->toDateString(),
                    'is_business_day' => (bool) $row->is_business_day,
                    'data_origin' => $row->data_origin,
                    'source' => $row->source,
                    'source_is_official' => (bool) $row->source_is_official,
                    'source_document' => $row->source_document,
                    'source_revision' => $row->source_revision,
                    'revision' => (int) $row->revision,
                    'import_run_id' => $row->import_run_id,
                    'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $row->updated_at?->format('Y-m-d H:i:s'),
                ];

                hash_update($context, (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            });

        return hash_final($context);
    }

    /** @return array{count:int,from:?string,to:?string,checksum:string} */
    private function calendarSnapshot(): array
    {
        $rows = BusinessCalendarDate::query()
            ->where('calendar_code', BusinessCalendarRegistry::B3_LISTED_TRADING)
            ->get();

        return [
            'count' => $rows->count(),
            'from' => $rows->min(fn (BusinessCalendarDate $date): ?string => $date->calendar_date?->toDateString()),
            'to' => $rows->max(fn (BusinessCalendarDate $date): ?string => $date->calendar_date?->toDateString()),
            'checksum' => $this->fingerprint($rows),
        ];
    }
}

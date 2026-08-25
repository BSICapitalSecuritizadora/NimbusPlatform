<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarLegalRule;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NationalLegalHolidayMaterializationService
{
    private const SOURCE = 'federal_legislation';

    public function __construct(
        private readonly NationalLegalHolidayDefinitionService $definitions,
        private readonly BusinessCalendarCatalogService $catalog,
        private readonly BusinessCalendarYearService $calendarYears,
        private readonly BusinessCalendarRevisionService $revisions,
    ) {}

    /**
     * @return array{calendar_code:string,from_year:int,to_year:int,batch_uuid:string,rules:int,years:array<int, array{year:int,holidays:int,inserted:int,changed:int,checksum:string,status:string,coverage_status:string}>}
     */
    public function materialize(int $fromYear, int $toYear, int $verifiedByUserId): array
    {
        if ($fromYear < NationalLegalHolidayDefinitionService::MINIMUM_SUPPORTED_YEAR) {
            throw new InvalidArgumentException(sprintf(
                'A projeção fixa suporta anos a partir de %d. A faixa 1985–1990 exige tratar a Lei nº 7.320/1985.',
                NationalLegalHolidayDefinitionService::MINIMUM_SUPPORTED_YEAR,
            ));
        }

        if ($toYear < $fromYear || $toYear > 2100) {
            throw new InvalidArgumentException('Informe uma faixa anual válida, limitada a 2100.');
        }

        $calendar = $this->catalog->findOrFail(BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS);
        $batchUuid = (string) Str::uuid();
        $changedCalendarYears = [];

        $result = Cache::lock($this->calendarYears->lockKey($calendar->code), 120)
            ->block((int) config('pu_calculator.business_calendar.lock_wait_seconds', 15), function () use (
                $calendar,
                $fromYear,
                $toYear,
                $verifiedByUserId,
                $batchUuid,
                &$changedCalendarYears,
            ): array {
                return DB::transaction(function () use (
                    $calendar,
                    $fromYear,
                    $toYear,
                    $verifiedByUserId,
                    $batchUuid,
                    &$changedCalendarYears,
                ): array {
                    $legalRules = $this->synchronizeDefinitions($verifiedByUserId);
                    $yearResults = [];

                    for ($year = $fromYear; $year <= $toYear; $year++) {
                        $fixedDefinitions = $this->definitions->fixedHolidayDefinitionsForYear($year);
                        $manifest = $this->annualManifest($year, $fixedDefinitions);
                        $checksum = hash('sha256', (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        $sourceDocument = (string) json_encode($manifest['official_sources'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $calendarYear = $this->calendarYears->findOrCreateForUpdate($calendar->code, $year);
                        $metadataChanged = ! hash_equals((string) ($calendarYear->checksum ?? ''), $checksum)
                            || $calendarYear->source !== self::SOURCE
                            || ! $calendarYear->source_is_official
                            || $calendarYear->source_document !== $sourceDocument
                            || $calendarYear->source_revision !== NationalLegalHolidayDefinitionService::SOURCE_REVISION;

                        if ($metadataChanged) {
                            $calendarYear->fill([
                                'source' => self::SOURCE,
                                'source_is_official' => true,
                                'source_document' => $sourceDocument,
                                'source_revision' => NationalLegalHolidayDefinitionService::SOURCE_REVISION,
                                'checksum' => $checksum,
                                'revision' => ((int) $calendarYear->revision) + 1,
                            ]);

                            if ($calendarYear->status === BusinessCalendarYear::STATUS_CONFIRMED) {
                                $calendarYear->status = BusinessCalendarYear::STATUS_STALE;
                            }

                            $calendarYear->save();
                            $changedCalendarYears[$year] = $calendarYear->fresh();
                        }

                        $run = BusinessCalendarImportRun::query()->create([
                            'batch_uuid' => $batchUuid,
                            'business_calendar_year_id' => $calendarYear->id,
                            'calendar_code' => $calendar->code,
                            'year' => $year,
                            'source' => self::SOURCE,
                            'source_is_official' => true,
                            'source_url' => 'https://www.planalto.gov.br/legislacao/',
                            'source_document' => $sourceDocument,
                            'source_revision' => NationalLegalHolidayDefinitionService::SOURCE_REVISION,
                            'checksum' => $checksum,
                            'started_at' => Date::now(),
                            'triggered_by' => $verifiedByUserId,
                            'triggered_by_process' => 'pu:business-calendar:materialize-national-holidays',
                            'records_found' => count($fixedDefinitions),
                            'result' => BusinessCalendarImportRun::RESULT_SUCCEEDED,
                            'dry_run' => false,
                        ]);

                        $inserted = 0;
                        $changed = 0;

                        foreach ($fixedDefinitions as $definition) {
                            $date = CarbonImmutable::create($year, (int) $definition['month'], (int) $definition['day']);
                            $rule = $legalRules->get((string) $definition['rule_key']);

                            if (! $rule instanceof BusinessCalendarLegalRule) {
                                throw new InvalidArgumentException(sprintf('Regra jurídica %s não sincronizada.', $definition['rule_key']));
                            }

                            $existingDate = BusinessCalendarDate::query()
                                ->where('calendar_code', $calendar->code)
                                ->whereDate('calendar_date', $date->toDateString())
                                ->lockForUpdate()
                                ->first();

                            if ($existingDate instanceof BusinessCalendarDate
                                && ($existingDate->is_business_day || $existingDate->source !== self::SOURCE)) {
                                throw new InvalidArgumentException(sprintf(
                                    'Conflito em %s: já existe decisão incompatível no calendário nacional.',
                                    $date->toDateString(),
                                ));
                            }

                            $holiday = BusinessHoliday::query()
                                ->where('calendar_code', $calendar->code)
                                ->whereDate('holiday_date', $date->toDateString())
                                ->where('source', self::SOURCE)
                                ->lockForUpdate()
                                ->first();
                            $holidayExisted = $holiday instanceof BusinessHoliday;
                            $dateExisted = $existingDate instanceof BusinessCalendarDate;

                            if (! $holiday instanceof BusinessHoliday) {
                                $holiday = BusinessHoliday::query()->create([
                                    'calendar_code' => $calendar->code,
                                    'business_calendar_year_id' => $calendarYear->id,
                                    'business_calendar_legal_rule_id' => $rule->id,
                                    'holiday_date' => $date->toDateString(),
                                    'name' => $rule->name,
                                    'source' => self::SOURCE,
                                    'data_origin' => 'legal_rule_projection',
                                    'source_is_official' => true,
                                    'source_document' => $rule->source_url,
                                    'source_revision' => $rule->norm_identification,
                                    'checksum' => $rule->source_fingerprint,
                                    'import_run_id' => $run->id,
                                    'last_seen_import_run_id' => $run->id,
                                    'imported_at' => Date::now(),
                                    'imported_by' => $verifiedByUserId,
                                    'notes' => sprintf('%s, %s.', $rule->norm_identification, $rule->article_reference),
                                ]);
                            } else {
                                $holiday->update(['last_seen_import_run_id' => $run->id]);
                            }

                            if (! $existingDate instanceof BusinessCalendarDate) {
                                BusinessCalendarDate::query()->create([
                                    'calendar_code' => $calendar->code,
                                    'business_calendar_year_id' => $calendarYear->id,
                                    'calendar_date' => $date->toDateString(),
                                    'is_business_day' => false,
                                    'description' => $rule->name,
                                    'data_origin' => 'legal_rule_projection',
                                    'source' => self::SOURCE,
                                    'source_is_official' => true,
                                    'source_document' => $rule->source_url,
                                    'source_revision' => $rule->norm_identification,
                                    'revision' => $calendarYear->revision,
                                    'import_run_id' => $run->id,
                                ]);
                            }

                            if (! $holidayExisted && ! $dateExisted) {
                                $inserted++;
                            } elseif (! $holidayExisted || ! $dateExisted) {
                                $changed++;
                            }
                        }

                        $run->update([
                            'finished_at' => Date::now(),
                            'records_inserted' => $inserted,
                            'records_changed' => $changed,
                        ]);

                        $coverage = $this->calendarYears->coverage($calendar->code, $year);
                        $yearResults[$year] = [
                            'year' => $year,
                            'holidays' => count($fixedDefinitions),
                            'inserted' => $inserted,
                            'changed' => $changed,
                            'checksum' => $checksum,
                            'status' => (string) $calendarYear->fresh()->status,
                            'coverage_status' => $coverage['coverage_status'],
                        ];
                    }

                    return [
                        'calendar_code' => $calendar->code,
                        'from_year' => $fromYear,
                        'to_year' => $toYear,
                        'batch_uuid' => $batchUuid,
                        'rules' => $legalRules->count(),
                        'years' => $yearResults,
                    ];
                });
            });

        foreach ($changedCalendarYears as $calendarYear) {
            $this->revisions->publish($calendarYear);
        }

        return $result;
    }

    /**
     * @return Collection<string, BusinessCalendarLegalRule>
     */
    private function synchronizeDefinitions(int $verifiedByUserId): Collection
    {
        foreach ($this->definitions->definitions() as $definition) {
            $fingerprint = $this->definitions->fingerprint($definition);
            $rule = BusinessCalendarLegalRule::query()
                ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
                ->where('rule_key', $definition['rule_key'])
                ->lockForUpdate()
                ->first();
            $attributes = [
                ...$definition,
                'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
                'source_fingerprint' => $fingerprint,
            ];

            if (! $rule instanceof BusinessCalendarLegalRule) {
                BusinessCalendarLegalRule::query()->create([
                    ...$attributes,
                    'verified_at' => Date::now(),
                    'verified_by' => $verifiedByUserId,
                ]);

                continue;
            }

            if (! hash_equals((string) $rule->source_fingerprint, $fingerprint)) {
                $rule->update([
                    ...$attributes,
                    'verified_at' => Date::now(),
                    'verified_by' => $verifiedByUserId,
                ]);
            }
        }

        return BusinessCalendarLegalRule::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS)
            ->get()
            ->keyBy('rule_key');
    }

    /**
     * @param  list<array<string, mixed>>  $fixedDefinitions
     * @return array<string, mixed>
     */
    private function annualManifest(int $year, array $fixedDefinitions): array
    {
        return [
            'calendar_code' => BusinessCalendarRegistry::BR_NATIONAL_HOLIDAYS,
            'year' => $year,
            'base_rule' => 'monday_to_friday_business_weekends_non_business',
            'source_revision' => NationalLegalHolidayDefinitionService::SOURCE_REVISION,
            'official_sources' => $this->definitions->officialSourceUrls(),
            'legal_framework' => collect($this->definitions->definitions())
                ->map(fn (array $definition): array => [
                    'rule_key' => $definition['rule_key'],
                    'fingerprint' => $this->definitions->fingerprint($definition),
                ])
                ->values()
                ->all(),
            'holiday_occurrences' => collect($fixedDefinitions)
                ->map(fn (array $definition): array => [
                    'date' => CarbonImmutable::create($year, (int) $definition['month'], (int) $definition['day'])->toDateString(),
                    'rule_key' => $definition['rule_key'],
                    'fingerprint' => $this->definitions->fingerprint($definition),
                ])
                ->sortBy('date')
                ->values()
                ->all(),
        ];
    }
}

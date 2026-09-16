<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\FebrabanHolidayImportResult;
use App\Domain\PuCalculator\DTOs\FebrabanHolidayObservation;
use App\Domain\PuCalculator\Enums\CalendarSourceObservationKind;
use App\Domain\PuCalculator\Exceptions\FebrabanHolidayImportException;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Ingere os feriados bancários publicados pela FEBRABAN como EVIDÊNCIA do calendário financeiro
 * consolidado ({@see BusinessCalendarRegistry::BR_FINANCIAL_MARKET}).
 *
 * Duas decisões de projeto governam esta classe:
 *
 * 1. **A FEBRABAN não substitui a ANBIMA.** Ela entra como segunda fonte. As linhas persistidas em
 *    {@see BusinessHoliday} usam `source` próprio, e a unique `(calendar_code, holiday_date, source)`
 *    permite que a mesma data exista uma vez por fonte sem virar dois feriados distintos. Os dados da
 *    ANBIMA não são lidos, movidos nem alterados por este importador.
 *
 * 2. **A importação não materializa calendário.** Nada é gravado em `business_calendar_dates`: o
 *    calendário consolidado nasce sem consumidores e só recebe decisão após reconciliação e
 *    homologação explícitas. Por isso `calendarApplied` é sempre 0.
 *
 * A fonte separa dia não útil de mercado (Resolução CMN 4.880/2020) de expediente especial de agência,
 * e essa separação é preservada em `source`: apenas {@see self::SOURCE_MARKET} é evidência de decisão.
 */
final class FebrabanHolidayImporter
{
    public const DEFAULT_BASE_URL = 'https://feriadosbancarios.febraban.org.br';

    public const MARKET_PATH = '/Home/ObterFeriadosFederais';

    public const SPECIAL_HOURS_PATH = '/Home/ObterFeriadosFederaisF';

    /** Evidência de dia NÃO útil de mercado — a única elegível a virar decisão de calendário. */
    public const SOURCE_MARKET = 'febraban';

    /** Evidência de expediente especial de agência — jamais vira `is_business_day = false`. */
    public const SOURCE_SPECIAL_HOURS = 'febraban_special_hours';

    public const NORM_REFERENCE = 'Resolução CMN 4.880, de 23.12.2020';

    private const USER_AGENT = 'NimbusPlatform/1.0 (+calendario-financeiro; importacao administrativa)';

    private const MAX_ERRORS = 50;

    public function __construct(
        private readonly FebrabanHolidayParser $parser,
    ) {}

    /**
     * @param  list<int>  $years
     */
    public function importFromSource(
        array $years,
        string $baseUrl = self::DEFAULT_BASE_URL,
        bool $dryRun = false,
        bool $force = false,
        ?int $importedByUserId = null,
    ): FebrabanHolidayImportResult {
        $baseUrl = rtrim($baseUrl, '/');
        $result = $this->newResult($baseUrl, $dryRun);

        foreach ($this->normalizeYears($years) as $year) {
            $marketUrl = $baseUrl.self::MARKET_PATH.'?ano='.$year;
            $specialUrl = $baseUrl.self::SPECIAL_HOURS_PATH.'?ano='.$year;
            $marketPayload = $this->fetch($marketUrl, $year);
            $specialPayload = $this->fetch($specialUrl, $year);

            $this->ingestYear(
                $result,
                $year,
                $marketPayload,
                $specialPayload,
                $marketUrl,
                $dryRun,
                $force,
                $importedByUserId,
            );
        }

        return $result;
    }

    /**
     * Ingestão a partir de um arquivo JSON salvo manualmente da fonte oficial.
     *
     * Aceita tanto a lista crua devolvida pelo endpoint (interpretada como a tabela de mercado) quanto
     * um envelope `{"market": [...], "special_hours": [...]}` quando as duas tabelas foram salvas. O ano
     * NUNCA vem no payload da FEBRABAN, então precisa ser informado.
     */
    public function importFromFile(
        string $path,
        int $year,
        bool $dryRun = false,
        bool $force = false,
        ?int $importedByUserId = null,
    ): FebrabanHolidayImportResult {
        if (! is_file($path) || ! is_readable($path)) {
            throw new FebrabanHolidayImportException(sprintf(
                'Arquivo de feriados FEBRABAN não encontrado ou ilegível: %s.',
                $path,
            ));
        }

        $contents = (string) file_get_contents($path);

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FebrabanHolidayImportException(sprintf(
                'O arquivo %s não contém um JSON válido: %s.',
                basename($path),
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (is_array($decoded) && array_is_list($decoded)) {
            $marketPayload = $contents;
            $specialPayload = '[]';
        } elseif (is_array($decoded)) {
            $marketPayload = json_encode($decoded['market'] ?? [], JSON_THROW_ON_ERROR);
            $specialPayload = json_encode($decoded['special_hours'] ?? [], JSON_THROW_ON_ERROR);
        } else {
            throw new FebrabanHolidayImportException(sprintf(
                'O arquivo %s não tem o formato esperado (lista de feriados ou envelope market/special_hours).',
                basename($path),
            ));
        }

        $result = $this->newResult(basename($path), $dryRun);

        $this->ingestYear(
            $result,
            $year,
            $marketPayload,
            $specialPayload,
            basename($path),
            $dryRun,
            $force,
            $importedByUserId,
        );

        return $result;
    }

    private function newResult(string $sourceDocument, bool $dryRun): FebrabanHolidayImportResult
    {
        return new FebrabanHolidayImportResult(
            calendarCode: BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
            source: self::SOURCE_MARKET,
            sourceDocument: $sourceDocument,
            dryRun: $dryRun,
        );
    }

    /**
     * @param  list<int>  $years
     * @return list<int>
     */
    private function normalizeYears(array $years): array
    {
        $normalized = array_values(array_unique(array_map(intval(...), $years)));
        sort($normalized);

        if ($normalized === []) {
            throw new FebrabanHolidayImportException('Nenhum ano informado para importação.');
        }

        foreach ($normalized as $year) {
            $this->parser->assertYearInRange($year);
        }

        return $normalized;
    }

    private function fetch(string $url, int $year): string
    {
        try {
            $response = Http::connectTimeout(10)
                ->timeout(30)
                ->retry(2, 250, throw: false)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new FebrabanHolidayImportException(sprintf(
                'Não foi possível consultar a FEBRABAN (%s): %s. Salve a resposta manualmente e use --file.',
                $url,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (! $response->successful()) {
            throw new FebrabanHolidayImportException(sprintf(
                'A FEBRABAN respondeu HTTP %d ao consultar %d em %s.',
                $response->status(),
                $year,
                $url,
            ));
        }

        $body = $response->body();

        if (trim($body) === '') {
            throw new FebrabanHolidayImportException(sprintf(
                'A FEBRABAN devolveu corpo vazio ao consultar %d em %s.',
                $year,
                $url,
            ));
        }

        return $body;
    }

    private function ingestYear(
        FebrabanHolidayImportResult $result,
        int $year,
        string $marketPayload,
        string $specialPayload,
        string $sourceDocument,
        bool $dryRun,
        bool $force,
        ?int $importedByUserId,
    ): void {
        $this->parser->assertYearInRange($year);
        $startedAt = Date::now();

        [$marketObservations, $marketErrors] = $this->parser->parse(
            $marketPayload,
            $year,
            CalendarSourceObservationKind::FinancialNonBusinessDay,
        );
        [$specialObservations, $specialErrors] = $this->parser->parse(
            $specialPayload,
            $year,
            CalendarSourceObservationKind::SpecialBankingHours,
        );

        $errors = [...$marketErrors, ...$specialErrors];
        $checksum = hash('sha256', $marketPayload.'|'.$specialPayload);

        try {
            $this->parser->assertYearIsPublished($year, $marketObservations);
        } catch (FebrabanHolidayImportException $exception) {
            // A recusa é ela própria um fato auditável: registra a tentativa e só então propaga.
            $this->recordFailedRun($year, $sourceDocument, $checksum, $startedAt, $importedByUserId, $dryRun, [
                ...$errors,
                $exception->getMessage(),
            ]);

            throw $exception;
        }

        $result->years[] = $year;
        $result->total += count($marketObservations) + count($specialObservations);
        $result->marketDays += count($marketObservations);
        $result->specialHoursDays += count($specialObservations);
        $result->invalid += count($errors);
        $result->errors = array_slice([...$result->errors, ...$errors], 0, self::MAX_ERRORS);
        $result->checksum = $checksum;

        $batchUuid = (string) Str::uuid();

        try {
            DB::transaction(function () use (
                $result,
                $year,
                $marketObservations,
                $specialObservations,
                $sourceDocument,
                $checksum,
                $startedAt,
                $batchUuid,
                $dryRun,
                $force,
                $importedByUserId,
                $errors,
            ): void {
                $counters = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'removals' => 0];

                $run = BusinessCalendarImportRun::query()->create([
                    'batch_uuid' => $batchUuid,
                    'business_calendar_year_id' => null,
                    'calendar_code' => BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
                    'year' => $year,
                    'source' => self::SOURCE_MARKET,
                    'source_is_official' => true,
                    'source_url' => str_starts_with($sourceDocument, 'http') ? $sourceDocument : null,
                    'source_file' => str_starts_with($sourceDocument, 'http') ? null : $sourceDocument,
                    'source_document' => $sourceDocument,
                    'source_revision' => self::NORM_REFERENCE,
                    'checksum' => $checksum,
                    'started_at' => $startedAt,
                    'finished_at' => Date::now(),
                    'triggered_by' => $importedByUserId,
                    'triggered_by_process' => $importedByUserId !== null ? 'filament' : 'console',
                    'records_found' => count($marketObservations) + count($specialObservations),
                    'errors' => $errors === [] ? null : $errors,
                    'result' => $errors === []
                        ? BusinessCalendarImportRun::RESULT_SUCCEEDED
                        : BusinessCalendarImportRun::RESULT_COMPLETED_WITH_ERRORS,
                    'dry_run' => $dryRun,
                ]);

                $this->persistObservations(
                    $marketObservations,
                    self::SOURCE_MARKET,
                    $year,
                    $run,
                    $sourceDocument,
                    $checksum,
                    $dryRun,
                    $force,
                    $importedByUserId,
                    $counters,
                );
                $this->persistObservations(
                    $specialObservations,
                    self::SOURCE_SPECIAL_HOURS,
                    $year,
                    $run,
                    $sourceDocument,
                    $checksum,
                    $dryRun,
                    $force,
                    $importedByUserId,
                    $counters,
                );

                $run->update([
                    'records_inserted' => $counters['imported'],
                    'records_changed' => $counters['updated'],
                    'removals_detected' => $counters['removals'],
                ]);

                $result->importRuns++;
                $result->imported += $counters['imported'];
                $result->updated += $counters['updated'];
                $result->skipped += $counters['skipped'];
                $result->removalsDetected += $counters['removals'];
            });
        } catch (Throwable $exception) {
            throw new FebrabanHolidayImportException(sprintf(
                'A importação de %d foi revertida integralmente; nenhuma evidência ficou parcialmente aplicada. %s',
                $year,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    /**
     * @param  list<FebrabanHolidayObservation>  $observations
     * @param  array{imported:int,updated:int,skipped:int,removals:int}  $counters
     */
    private function persistObservations(
        array $observations,
        string $source,
        int $year,
        BusinessCalendarImportRun $run,
        string $sourceDocument,
        string $checksum,
        bool $dryRun,
        bool $force,
        ?int $importedByUserId,
        array &$counters,
    ): void {
        $existing = BusinessHoliday::query()
            ->where('calendar_code', BusinessCalendarRegistry::BR_FINANCIAL_MARKET)
            ->where('source', $source)
            ->whereYear('holiday_date', $year)
            ->get()
            ->keyBy(fn (BusinessHoliday $holiday): string => CarbonImmutable::instance($holiday->holiday_date)->toDateString());

        $timestamp = Date::now();
        $incoming = [];

        foreach ($observations as $observation) {
            $dateKey = $observation->dateKey();
            $incoming[$dateKey] = true;
            $holiday = $existing->get($dateKey);

            if (! $holiday instanceof BusinessHoliday) {
                $counters['imported']++;

                if (! $dryRun) {
                    BusinessHoliday::query()->create([
                        'calendar_code' => BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
                        'holiday_date' => $dateKey,
                        'name' => $observation->name,
                        'source' => $source,
                        'data_origin' => 'imported',
                        'source_is_official' => true,
                        'source_file' => $run->source_file,
                        'source_document' => $sourceDocument,
                        'source_revision' => self::NORM_REFERENCE,
                        'checksum' => $checksum,
                        'import_run_id' => $run->id,
                        'last_seen_import_run_id' => $run->id,
                        'imported_at' => $timestamp,
                        'imported_by' => $importedByUserId,
                        'notes' => $this->notesFor($observation),
                    ]);
                }

                continue;
            }

            $nameChanged = $holiday->name !== $observation->name;

            if ($nameChanged && ! $force) {
                $counters['skipped']++;

                continue;
            }

            $counters[$nameChanged ? 'updated' : 'skipped']++;

            if ($dryRun) {
                continue;
            }

            $holiday->fill([
                'data_origin' => 'imported',
                'source_is_official' => true,
                'source_document' => $sourceDocument,
                'source_revision' => self::NORM_REFERENCE,
                'checksum' => $checksum,
                'last_seen_import_run_id' => $run->id,
                'removed_detected_at' => null,
            ]);

            if ($nameChanged) {
                $holiday->fill([
                    'name' => $observation->name,
                    'notes' => $this->notesFor($observation),
                    'imported_at' => $timestamp,
                    'imported_by' => $importedByUserId,
                ]);
            }

            if ($holiday->isDirty()) {
                $holiday->save();
            }
        }

        // Datas que a carga atual não trouxe são marcadas, nunca apagadas: a perda de histórico de uma
        // fonte oficial é ela própria um fato auditável, e a remoção é decisão humana.
        foreach ($existing as $dateKey => $holiday) {
            if (isset($incoming[$dateKey]) || $holiday->removed_detected_at !== null) {
                continue;
            }

            $counters['removals']++;

            if (! $dryRun) {
                $holiday->update(['removed_detected_at' => $timestamp]);
            }
        }
    }

    /**
     * Auditoria de uma tentativa recusada. Nunca deixa a falha de auditoria mascarar o erro original.
     *
     * @param  list<string>  $errors
     */
    private function recordFailedRun(
        int $year,
        string $sourceDocument,
        string $checksum,
        CarbonImmutable $startedAt,
        ?int $importedByUserId,
        bool $dryRun,
        array $errors,
    ): void {
        try {
            BusinessCalendarImportRun::query()->create([
                'batch_uuid' => (string) Str::uuid(),
                'calendar_code' => BusinessCalendarRegistry::BR_FINANCIAL_MARKET,
                'year' => $year,
                'source' => self::SOURCE_MARKET,
                'source_is_official' => true,
                'source_url' => str_starts_with($sourceDocument, 'http') ? $sourceDocument : null,
                'source_file' => str_starts_with($sourceDocument, 'http') ? null : $sourceDocument,
                'source_document' => $sourceDocument,
                'source_revision' => self::NORM_REFERENCE,
                'checksum' => $checksum,
                'started_at' => $startedAt,
                'finished_at' => Date::now(),
                'triggered_by' => $importedByUserId,
                'triggered_by_process' => $importedByUserId !== null ? 'filament' : 'console',
                'errors' => $errors,
                'result' => BusinessCalendarImportRun::RESULT_FAILED,
                'dry_run' => $dryRun,
            ]);
        } catch (Throwable $auditFailure) {
            report($auditFailure);
        }
    }

    private function notesFor(FebrabanHolidayObservation $observation): string
    {
        return match ($observation->kind) {
            CalendarSourceObservationKind::FinancialNonBusinessDay => sprintf(
                'FEBRABAN — tabela de feriados federais. Nos termos da %s, não é considerado dia útil para fins de '
                .'operações praticadas no mercado financeiro e de prestação de informações ao Banco Central do Brasil.',
                self::NORM_REFERENCE,
            ),
            CalendarSourceObservationKind::SpecialBankingHours => sprintf(
                'FEBRABAN — tabela de atendimento bancário (%s). Trata de expediente ao público, NÃO de dia útil de '
                .'mercado: a fonte admite operações entre instituições financeiras e serviços de compensação. '
                .'Evidência registrada para auditoria; não produz dia não útil no calendário financeiro.',
                $observation->name,
            ),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\AnbimaHolidayImportResult;
use App\Domain\PuCalculator\Exceptions\AnbimaHolidayImportException;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarImportRun;
use App\Models\BusinessCalendarOverride;
use App\Models\BusinessCalendarYear;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use Throwable;

/**
 * Importa o calendário bancário publicado pela ANBIMA a partir do arquivo `feriados_nacionais.xls`
 * (formato BIFF8 do Excel), aceitando download por URL ou upload manual de arquivo.
 *
 * O arquivo persiste em {@see BusinessHoliday} (fonte/auditoria) e, fora do dry-run, os feriados são
 * aplicados ao calendário de dias úteis ({@see BusinessCalendarDate}, is_business_day=false) — que é a
 * estrutura consultada por CDI/Prefixado via {@see BusinessCalendarService}. A operação é transacional
 * e idempotente nos dados efetivos: cada tentativa é auditada, mas reimportar a mesma fonte não duplica
 * feriados nem incrementa a revisão anual. A engine de cálculo jamais baixa o arquivo em tempo de cálculo.
 */
class AnbimaHolidayImporter
{
    public const DEFAULT_URL = 'https://www.anbima.com.br/feriados/arqs/feriados_nacionais.xls';

    private const MIN_YEAR = 1990;

    private const MAX_YEAR = 2200;

    private const MAX_COLUMNS = 12;

    private const MAX_ERRORS = 50;

    /** @var list<string> */
    private const WEEKDAY_TOKENS = [
        'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo', 'feira',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    public function __construct(
        private readonly BusinessDayCalendarService $businessDayCalendar,
        private readonly BusinessCalendarYearService $yearService,
        private readonly BusinessCalendarRevisionService $revisionService,
    ) {}

    public function importFromUrl(
        string $url,
        string $calendarCode = BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        bool $dryRun = false,
        bool $force = false,
        ?int $importedByUserId = null,
    ): AnbimaHolidayImportResult {
        $startedAt = Date::now();

        try {
            $contents = $this->download($url);
        } catch (AnbimaHolidayImportException $exception) {
            $this->recordFailedRuns(
                calendarCode: $calendarCode,
                years: [null],
                sourceUrl: $url,
                sourceFile: basename(parse_url($url, PHP_URL_PATH) ?: $url),
                checksum: null,
                startedAt: $startedAt,
                userId: $importedByUserId,
                process: $this->responsibleProcess($importedByUserId),
                dryRun: $dryRun,
                errors: [$exception->getMessage()],
            );

            throw $exception;
        }

        $temporaryPath = $this->writeTemporaryFile($contents, $url);

        try {
            return $this->importFromFile(
                $temporaryPath,
                $calendarCode,
                $dryRun,
                $force,
                $importedByUserId,
                sourceFileLabel: basename(parse_url($url, PHP_URL_PATH) ?: $url),
                sourceUrl: $url,
                sourceDocument: $url,
                startedAt: $startedAt,
            );
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function importFromFile(
        string $path,
        string $calendarCode = BusinessCalendarRegistry::BR_BANKING_ANBIMA,
        bool $dryRun = false,
        bool $force = false,
        ?int $importedByUserId = null,
        ?string $sourceFileLabel = null,
        ?string $sourceUrl = null,
        ?string $sourceDocument = null,
        ?string $sourceRevision = null,
        ?CarbonImmutable $startedAt = null,
    ): AnbimaHolidayImportResult {
        $startedAt ??= Date::now();
        $batchUuid = (string) Str::uuid();
        $process = $this->responsibleProcess($importedByUserId);

        try {
            $calendarCode = BusinessCalendarRegistry::ensureKnown($calendarCode);

            if (! BusinessCalendarRegistry::acceptsAnbima($calendarCode)) {
                throw new AnbimaHolidayImportException(sprintf(
                    'O calendário %s representa sessões de negociação B3 e não pode receber feriados bancários ANBIMA.',
                    $calendarCode,
                ));
            }
        } catch (\InvalidArgumentException $exception) {
            throw new AnbimaHolidayImportException($exception->getMessage(), previous: $exception);
        }

        if (! is_file($path) || ! is_readable($path)) {
            $exception = new AnbimaHolidayImportException(sprintf(
                'Arquivo de feriados não encontrado ou ilegível: %s. Faça o upload manual do arquivo da ANBIMA.',
                $path,
            ));
            $this->recordFailedRuns(
                $calendarCode,
                [null],
                $sourceUrl,
                $sourceFileLabel ?? basename($path),
                null,
                $startedAt,
                $importedByUserId,
                $process,
                $dryRun,
                [$exception->getMessage()],
                $batchUuid,
            );

            throw $exception;
        }

        $sourceFile = $sourceFileLabel ?? basename($path);
        $sourceDocument ??= $sourceUrl ?? $sourceFile;
        $checksum = hash_file('sha256', $path) ?: null;

        $result = new AnbimaHolidayImportResult(
            calendarCode: $calendarCode,
            source: 'anbima',
            sourceFile: $sourceFile,
            dryRun: $dryRun,
            checksum: $checksum,
        );

        try {
            [$holidays, $invalidErrors, $invalidCount] = $this->parse($path);
        } catch (AnbimaHolidayImportException $exception) {
            $this->recordFailedRuns(
                $calendarCode,
                [null],
                $sourceUrl,
                $sourceFile,
                $checksum,
                $startedAt,
                $importedByUserId,
                $process,
                $dryRun,
                [$exception->getMessage()],
                $batchUuid,
            );

            throw $exception;
        }

        $result->invalid = $invalidCount;
        $result->errors = array_slice($invalidErrors, 0, self::MAX_ERRORS);

        if ($holidays === [] && $invalidCount === 0) {
            $exception = new AnbimaHolidayImportException(
                'Nenhum feriado válido encontrado no arquivo. Verifique se é o arquivo de feriados nacionais da ANBIMA (feriados_nacionais.xls).',
            );
            $this->recordFailedRuns(
                $calendarCode,
                [null],
                $sourceUrl,
                $sourceFile,
                $checksum,
                $startedAt,
                $importedByUserId,
                $process,
                $dryRun,
                [$exception->getMessage()],
                $batchUuid,
            );

            throw $exception;
        }

        $result->total = count($holidays);
        $holidaysByYear = collect($holidays)->groupBy(
            static fn (?string $name, string $date): int => CarbonImmutable::parse($date)->year,
            preserveKeys: true,
        );
        $changedYears = [];

        try {
            Cache::lock($this->yearService->lockKey($calendarCode), 180)
                ->block((int) config('pu_calculator.business_calendar.lock_wait_seconds', 15), function () use (
                    $calendarCode,
                    $holidaysByYear,
                    $sourceUrl,
                    $sourceFile,
                    $sourceDocument,
                    $sourceRevision,
                    $checksum,
                    $startedAt,
                    $importedByUserId,
                    $process,
                    $dryRun,
                    $force,
                    $invalidErrors,
                    $batchUuid,
                    $result,
                    &$changedYears,
                ): void {
                    DB::transaction(function () use (
                        $calendarCode,
                        $holidaysByYear,
                        $sourceUrl,
                        $sourceFile,
                        $sourceDocument,
                        $sourceRevision,
                        $checksum,
                        $startedAt,
                        $importedByUserId,
                        $process,
                        $dryRun,
                        $force,
                        $invalidErrors,
                        $batchUuid,
                        $result,
                        &$changedYears,
                    ): void {
                        foreach ($holidaysByYear as $year => $yearHolidays) {
                            $yearHolidays = $yearHolidays->all();
                            $existing = BusinessHoliday::query()
                                ->where('calendar_code', $calendarCode)
                                ->where('source', 'anbima')
                                ->whereYear('holiday_date', (int) $year)
                                ->lockForUpdate()
                                ->get()
                                ->keyBy(fn (BusinessHoliday $holiday): string => CarbonImmutable::instance($holiday->holiday_date)->toDateString());
                            $incomingDates = array_keys($yearHolidays);
                            $additions = array_values(array_diff($incomingDates, $existing->keys()->all()));
                            $removals = array_values(array_diff($existing->keys()->all(), $incomingDates));
                            $changes = array_values(array_filter(
                                $incomingDates,
                                static fn (string $date): bool => $existing->has($date)
                                    && $existing->get($date)?->name !== $yearHolidays[$date],
                            ));
                            $actualChanges = $force ? $changes : [];
                            $conflicts = count($removals) + ($force ? 0 : count($changes));
                            $calendarYear = $dryRun
                                ? BusinessCalendarYear::query()->where('calendar_code', $calendarCode)->where('year', (int) $year)->first()
                                : $this->yearService->findOrCreateForUpdate($calendarCode, (int) $year, [
                                    'source' => 'anbima',
                                    'source_is_official' => true,
                                ]);
                            $run = BusinessCalendarImportRun::query()->create([
                                'batch_uuid' => $batchUuid,
                                'business_calendar_year_id' => $calendarYear?->id,
                                'calendar_code' => $calendarCode,
                                'year' => (int) $year,
                                'source' => 'anbima',
                                'source_is_official' => true,
                                'source_url' => $sourceUrl,
                                'source_file' => $sourceFile,
                                'source_document' => $sourceDocument,
                                'source_revision' => $sourceRevision,
                                'checksum' => $checksum,
                                'started_at' => $startedAt,
                                'finished_at' => Date::now(),
                                'triggered_by' => $importedByUserId,
                                'triggered_by_process' => $process,
                                'records_found' => count($yearHolidays),
                                'records_inserted' => count($additions),
                                'records_changed' => count($changes),
                                'removals_detected' => count($removals),
                                'conflicts_detected' => $conflicts,
                                'errors' => $invalidErrors === [] ? null : $invalidErrors,
                                'result' => $this->successfulRunResult($conflicts, $invalidErrors),
                                'dry_run' => $dryRun,
                            ]);

                            $result->importRuns++;
                            $result->imported += count($additions);
                            $result->changesDetected += count($changes);
                            $result->updated += count($actualChanges);
                            $result->skipped += count($yearHolidays) - count($additions) - count($actualChanges);
                            $result->removalsDetected += count($removals);

                            if (! $dryRun && $calendarYear instanceof BusinessCalendarYear) {
                                $this->persistHolidayFacts(
                                    $calendarCode,
                                    $yearHolidays,
                                    $existing,
                                    $actualChanges,
                                    $removals,
                                    $calendarYear,
                                    $run,
                                    $sourceFile,
                                    $sourceDocument,
                                    $sourceRevision,
                                    $checksum,
                                    $importedByUserId,
                                );
                                $application = $this->applyToCalendar(
                                    $calendarCode,
                                    $yearHolidays,
                                    $calendarYear,
                                    $run,
                                    $force,
                                );
                                $result->calendarApplied += $application['applied'];
                                $conflicts += $application['conflicts'];
                                $this->updateCalendarYear(
                                    $calendarYear,
                                    $sourceDocument,
                                    $sourceRevision,
                                    $checksum,
                                    count($additions) + count($actualChanges) + $application['applied'],
                                    $conflicts,
                                    count($removals),
                                );
                                $run->update([
                                    'business_calendar_year_id' => $calendarYear->id,
                                    'conflicts_detected' => $conflicts,
                                    'result' => $this->successfulRunResult($conflicts, $invalidErrors),
                                ]);
                                $changedYears[(int) $year] = $calendarYear->fresh();
                            }

                            $result->conflictsDetected += $conflicts;
                        }
                    });
                });
        } catch (Throwable $exception) {
            $years = $holidaysByYear->keys()->map(static fn ($year): int => (int) $year)->all();
            $this->recordFailedRuns(
                $calendarCode,
                $years,
                $sourceUrl,
                $sourceFile,
                $checksum,
                $startedAt,
                $importedByUserId,
                $process,
                $dryRun,
                [$exception->getMessage()],
                $batchUuid,
            );

            throw new AnbimaHolidayImportException(
                'A importação foi revertida integralmente; nenhum feriado ou dia útil ficou parcialmente aplicado. '.$exception->getMessage(),
                previous: $exception,
            );
        }

        foreach ($changedYears as $calendarYear) {
            $this->revisionService->publish($calendarYear);
        }

        if (! $dryRun) {
            $this->businessDayCalendar->flushCache();
        }

        return $result;
    }

    private function download(string $url): string
    {
        try {
            $response = Http::connectTimeout(10)
                ->timeout(30)
                ->retry(2, 250, throw: false)
                ->withHeaders(['Accept' => 'application/vnd.ms-excel,application/octet-stream,*/*'])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new AnbimaHolidayImportException(sprintf(
                'Não foi possível baixar o arquivo de feriados da ANBIMA (%s): %s. Faça o upload manual do arquivo como alternativa.',
                $url,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (! $response->successful()) {
            throw new AnbimaHolidayImportException(sprintf(
                'A ANBIMA respondeu com HTTP %d ao baixar %s. Tente novamente mais tarde ou faça o upload manual do arquivo.',
                $response->status(),
                $url,
            ));
        }

        $body = $response->body();

        if ($body === '') {
            throw new AnbimaHolidayImportException(sprintf(
                'O arquivo baixado da ANBIMA (%s) veio vazio. Faça o upload manual do arquivo como alternativa.',
                $url,
            ));
        }

        return $body;
    }

    private function writeTemporaryFile(string $contents, string $url): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'xls';
        $temporaryPath = tempnam(sys_get_temp_dir(), 'anbima_holidays_');

        if ($temporaryPath === false) {
            throw new AnbimaHolidayImportException('Não foi possível criar um arquivo temporário para o download da ANBIMA.');
        }

        $finalPath = $temporaryPath.'.'.$extension;
        @rename($temporaryPath, $finalPath);
        file_put_contents($finalPath, $contents);

        return $finalPath;
    }

    /**
     * @return array{0: array<string, ?string>, 1: list<string>, 2: int} feriados (data => nome), erros, total inválido
     */
    private function parse(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            $spreadsheet = $reader->load($path);
        } catch (Throwable $exception) {
            throw new AnbimaHolidayImportException(sprintf(
                'Não foi possível ler a planilha de feriados (%s). Confirme que o arquivo é um .xls/.xlsx válido da ANBIMA.',
                $exception->getMessage(),
            ), previous: $exception);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = min(
            Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
            self::MAX_COLUMNS,
        );

        $holidays = [];
        $errors = [];
        $invalid = 0;

        for ($row = 1; $row <= $highestRow; $row++) {
            $date = null;
            $dateColumn = null;
            $textCells = [];

            for ($column = 1; $column <= $highestColumn; $column++) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row);
                $value = $cell->getValue();

                if ($value === null || $value === '') {
                    continue;
                }

                if ($date === null) {
                    $parsed = $this->parseCellDate($cell);

                    if ($parsed !== null) {
                        $date = $parsed;
                        $dateColumn = $column;

                        continue;
                    }
                }

                if (is_string($value)) {
                    $textCells[] = trim($value);
                }
            }

            if ($date === null) {
                if ($dateColumn === null && $this->looksLikeFailedDate($sheet->getCell('A'.$row)->getValue())) {
                    $invalid++;
                    $errors[] = sprintf('Linha %d ignorada: data ilegível ou fora da faixa esperada.', $row);
                }

                continue;
            }

            $holidays[$date->toDateString()] ??= $this->extractName($textCells);
        }

        ksort($holidays);

        return [$holidays, $errors, $invalid];
    }

    private function parseCellDate(Cell $cell): ?CarbonImmutable
    {
        $value = $cell->getValue();

        if (is_numeric($value) && SpreadsheetDate::isDateTime($cell)) {
            try {
                $date = CarbonImmutable::instance(SpreadsheetDate::excelToDateTimeObject((float) $value));

                return $this->withinRange($date) ? $date->startOfDay() : null;
            } catch (Throwable) {
                return null;
            }
        }

        if (is_string($value)) {
            return $this->parseDateString(trim($value));
        }

        return null;
    }

    private function parseDateString(string $value): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            return $this->buildDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $matches) === 1) {
            $first = (int) $matches[1];
            $second = (int) $matches[2];
            $year = (int) $matches[3];

            // dd/mm/yyyy (padrão brasileiro) é o default; só inverte quando os números forçam mm/dd.
            if ($second > 12 && $first <= 12) {
                return $this->buildDate($year, $first, $second);
            }

            return $this->buildDate($year, $second, $first);
        }

        return null;
    }

    private function buildDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = CarbonImmutable::create($year, $month, $day, 0, 0, 0);

        return ($date !== null && $this->withinRange($date)) ? $date : null;
    }

    private function withinRange(CarbonImmutable $date): bool
    {
        $year = (int) $date->format('Y');

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    private function looksLikeFailedDate(mixed $value): bool
    {
        return is_string($value) && preg_match('#\d{1,4}[-/]\d{1,2}[-/]\d{1,4}#', $value) === 1;
    }

    /**
     * @param  list<string>  $textCells
     */
    private function extractName(array $textCells): ?string
    {
        $candidates = array_filter($textCells, function (string $text): bool {
            if ($text === '' || is_numeric($text)) {
                return false;
            }

            return ! $this->isWeekdayToken($text);
        });

        if ($candidates === []) {
            return null;
        }

        return trim((string) end($candidates)) ?: null;
    }

    private function isWeekdayToken(string $text): bool
    {
        $normalized = $this->normalize($text);

        foreach (self::WEEKDAY_TOKENS as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);

        return strtolower($ascii !== false ? $ascii : $text);
    }

    /**
     * @param  array<string, ?string>  $holidays
     * @param  Collection<string, BusinessHoliday>  $existing
     * @param  list<string>  $actualChanges
     * @param  list<string>  $removals
     */
    private function persistHolidayFacts(
        string $calendarCode,
        array $holidays,
        Collection $existing,
        array $actualChanges,
        array $removals,
        BusinessCalendarYear $calendarYear,
        BusinessCalendarImportRun $run,
        string $sourceFile,
        string $sourceDocument,
        ?string $sourceRevision,
        ?string $checksum,
        ?int $userId,
    ): void {
        $timestamp = Date::now();

        foreach ($holidays as $dateString => $name) {
            $holiday = $existing->get($dateString);

            if (! $holiday instanceof BusinessHoliday) {
                BusinessHoliday::query()->create([
                    'business_calendar_year_id' => $calendarYear->id,
                    'calendar_code' => $calendarCode,
                    'holiday_date' => $dateString,
                    'name' => $name,
                    'source' => 'anbima',
                    'data_origin' => 'imported',
                    'source_is_official' => true,
                    'source_file' => $sourceFile,
                    'source_document' => $sourceDocument,
                    'source_revision' => $sourceRevision,
                    'checksum' => $checksum,
                    'import_run_id' => $run->id,
                    'last_seen_import_run_id' => $run->id,
                    'imported_at' => $timestamp,
                    'imported_by' => $userId,
                ]);

                continue;
            }

            $holiday->fill([
                'business_calendar_year_id' => $calendarYear->id,
                'data_origin' => 'imported',
                'source_is_official' => true,
                'source_file' => $sourceFile,
                'source_document' => $sourceDocument,
                'source_revision' => $sourceRevision,
                'checksum' => $checksum,
                'last_seen_import_run_id' => $run->id,
                'removed_detected_at' => null,
            ]);

            if (in_array($dateString, $actualChanges, true)) {
                $holiday->fill([
                    'name' => $name,
                    'imported_at' => $timestamp,
                    'imported_by' => $userId,
                ]);
            }

            if ($holiday->isDirty()) {
                $holiday->save();
            }
        }

        foreach ($removals as $dateString) {
            $holiday = $existing->get($dateString);

            if ($holiday instanceof BusinessHoliday && $holiday->removed_detected_at === null) {
                $holiday->update(['removed_detected_at' => $timestamp]);
            }
        }
    }

    /**
     * @param  array<string, ?string>  $holidays
     * @return array{applied:int,conflicts:int}
     */
    private function applyToCalendar(
        string $calendarCode,
        array $holidays,
        BusinessCalendarYear $calendarYear,
        BusinessCalendarImportRun $run,
        bool $force,
    ): array {
        $applied = 0;
        $conflicts = 0;
        $overrides = BusinessCalendarOverride::query()
            ->where('calendar_code', $calendarCode)
            ->whereIn('calendar_date', array_keys($holidays))
            ->latest('applied_at')
            ->latest('id')
            ->get()
            ->unique(fn (BusinessCalendarOverride $override): string => CarbonImmutable::instance($override->calendar_date)->toDateString())
            ->keyBy(fn (BusinessCalendarOverride $override): string => CarbonImmutable::instance($override->calendar_date)->toDateString());

        foreach ($holidays as $dateString => $name) {
            $override = $overrides->get($dateString);

            if ($override instanceof BusinessCalendarOverride) {
                if ((bool) $override->new_is_business_day) {
                    $conflicts++;
                }

                continue;
            }

            $calendarDate = BusinessCalendarDate::query()
                ->where('calendar_code', $calendarCode)
                ->whereDate('calendar_date', $dateString)
                ->lockForUpdate()
                ->first();

            if ($calendarDate?->data_origin === 'manual_override') {
                if ((bool) $calendarDate->is_business_day) {
                    $conflicts++;
                }

                continue;
            }

            if (
                $calendarDate instanceof BusinessCalendarDate
                && $calendarDate->data_origin === null
                && (bool) $calendarDate->is_business_day
            ) {
                $conflicts++;

                continue;
            }

            $calendarDate ??= new BusinessCalendarDate([
                'calendar_code' => $calendarCode,
                'calendar_date' => $dateString,
            ]);
            $description = $calendarDate->exists && ! $force
                ? ($calendarDate->description ?? $name ?? 'Feriado ANBIMA')
                : ($name ?? 'Feriado ANBIMA');
            $calendarDate->fill([
                'business_calendar_year_id' => $calendarYear->id,
                'is_business_day' => false,
                'description' => $description,
                'data_origin' => 'imported',
                'source' => 'anbima',
                'source_is_official' => true,
                'source_document' => $run->source_document,
                'source_revision' => $run->source_revision,
                'import_run_id' => $calendarDate->import_run_id ?? $run->id,
            ]);

            if (! $calendarDate->exists || $calendarDate->isDirty()) {
                $calendarDate->revision = ((int) $calendarYear->revision) + 1;
                $calendarDate->save();
                $applied++;
            }
        }

        return ['applied' => $applied, 'conflicts' => $conflicts];
    }

    private function updateCalendarYear(
        BusinessCalendarYear $calendarYear,
        string $sourceDocument,
        ?string $sourceRevision,
        ?string $checksum,
        int $effectiveChanges,
        int $conflicts,
        int $removals,
    ): void {
        $metadataChanged = $calendarYear->source_document !== $sourceDocument
            || $calendarYear->source_revision !== $sourceRevision
            || $calendarYear->checksum !== $checksum;
        $statusWillChange = ($conflicts > 0 || $removals > 0)
            && $calendarYear->status !== BusinessCalendarYear::STATUS_STALE;
        $mustRevise = $metadataChanged || $effectiveChanges > 0 || $statusWillChange;
        $calendarYear->fill([
            'source' => 'anbima',
            'source_is_official' => true,
            'source_document' => $sourceDocument,
            'source_revision' => $sourceRevision,
            'checksum' => $checksum,
        ]);

        if ($conflicts > 0 || $removals > 0) {
            $calendarYear->status = BusinessCalendarYear::STATUS_STALE;
        } elseif ($mustRevise && $calendarYear->status === BusinessCalendarYear::STATUS_CONFIRMED) {
            $calendarYear->status = BusinessCalendarYear::STATUS_STALE;
        }

        if ($mustRevise) {
            $calendarYear->revision = ((int) $calendarYear->revision) + 1;
        }

        if ($calendarYear->isDirty()) {
            $calendarYear->save();
        }
    }

    /**
     * @param  list<?int>  $years
     * @param  list<string>  $errors
     */
    private function recordFailedRuns(
        string $calendarCode,
        array $years,
        ?string $sourceUrl,
        string $sourceFile,
        ?string $checksum,
        CarbonImmutable $startedAt,
        ?int $userId,
        string $process,
        bool $dryRun,
        array $errors,
        ?string $batchUuid = null,
    ): void {
        try {
            DB::transaction(function () use (
                $calendarCode,
                $years,
                $sourceUrl,
                $sourceFile,
                $checksum,
                $startedAt,
                $userId,
                $process,
                $dryRun,
                $errors,
                $batchUuid,
            ): void {
                foreach ($years as $year) {
                    $calendarYear = $year === null
                        ? null
                        : BusinessCalendarYear::query()->where('calendar_code', strtoupper($calendarCode))->where('year', $year)->first();
                    BusinessCalendarImportRun::query()->create([
                        'batch_uuid' => $batchUuid ?? (string) Str::uuid(),
                        'business_calendar_year_id' => $calendarYear?->id,
                        'calendar_code' => strtoupper($calendarCode),
                        'year' => $year,
                        'source' => 'anbima',
                        'source_is_official' => true,
                        'source_url' => $sourceUrl,
                        'source_file' => $sourceFile,
                        'source_document' => $sourceUrl ?? $sourceFile,
                        'checksum' => $checksum,
                        'started_at' => $startedAt,
                        'finished_at' => Date::now(),
                        'triggered_by' => $userId,
                        'triggered_by_process' => $process,
                        'errors' => $errors,
                        'result' => BusinessCalendarImportRun::RESULT_FAILED,
                        'dry_run' => $dryRun,
                    ]);
                }
            });
        } catch (Throwable $auditFailure) {
            report($auditFailure);
        }
    }

    private function responsibleProcess(?int $userId): string
    {
        if ($userId !== null) {
            return 'filament';
        }

        return app()->runningInConsole() ? 'artisan' : 'system';
    }

    /** @param  list<string>  $errors */
    private function successfulRunResult(int $conflicts, array $errors): string
    {
        if ($conflicts > 0) {
            return BusinessCalendarImportRun::RESULT_CONFLICTS;
        }

        return $errors === []
            ? BusinessCalendarImportRun::RESULT_SUCCEEDED
            : BusinessCalendarImportRun::RESULT_COMPLETED_WITH_ERRORS;
    }
}

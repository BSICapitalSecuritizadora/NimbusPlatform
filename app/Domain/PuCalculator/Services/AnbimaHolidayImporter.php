<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\AnbimaHolidayImportResult;
use App\Domain\PuCalculator\Exceptions\AnbimaHolidayImportException;
use App\Models\BusinessCalendarDate;
use App\Models\BusinessHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use Throwable;

/**
 * Importa feriados nacionais publicados pela ANBIMA a partir do arquivo `feriados_nacionais.xls`
 * (formato BIFF8 do Excel), aceitando download por URL ou upload manual de arquivo.
 *
 * O arquivo persiste em {@see BusinessHoliday} (fonte/auditoria) e, fora do dry-run, os feriados são
 * aplicados ao calendário de dias úteis ({@see BusinessCalendarDate}, is_business_day=false) — que é a
 * estrutura consultada por CDI/Prefixado via {@see BusinessCalendarService}. A operação é idempotente:
 * reimportar não duplica nem altera dados existentes (a menos de --force) e nunca quebra a geração da
 * curva. A engine de cálculo jamais baixa o arquivo em tempo de cálculo.
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

    public function __construct(private readonly BusinessDayCalendarService $businessDayCalendar) {}

    public function importFromUrl(
        string $url,
        string $calendarCode = 'B3',
        bool $dryRun = false,
        bool $force = false,
        ?int $importedByUserId = null,
    ): AnbimaHolidayImportResult {
        $contents = $this->download($url);

        $temporaryPath = $this->writeTemporaryFile($contents, $url);

        try {
            return $this->importFromFile(
                $temporaryPath,
                $calendarCode,
                $dryRun,
                $force,
                $importedByUserId,
                sourceFileLabel: basename(parse_url($url, PHP_URL_PATH) ?: $url),
            );
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function importFromFile(
        string $path,
        string $calendarCode = 'B3',
        bool $dryRun = false,
        bool $force = false,
        ?int $importedByUserId = null,
        ?string $sourceFileLabel = null,
    ): AnbimaHolidayImportResult {
        if (! is_file($path) || ! is_readable($path)) {
            throw new AnbimaHolidayImportException(sprintf(
                'Arquivo de feriados não encontrado ou ilegível: %s. Faça o upload manual do arquivo da ANBIMA.',
                $path,
            ));
        }

        $sourceFile = $sourceFileLabel ?? basename($path);

        $result = new AnbimaHolidayImportResult(
            calendarCode: $calendarCode,
            source: 'anbima',
            sourceFile: $sourceFile,
            dryRun: $dryRun,
        );

        [$holidays, $invalidErrors, $invalidCount] = $this->parse($path);

        $result->invalid = $invalidCount;
        $result->errors = array_slice($invalidErrors, 0, self::MAX_ERRORS);

        if ($holidays === [] && $invalidCount === 0) {
            throw new AnbimaHolidayImportException(
                'Nenhum feriado válido encontrado no arquivo. Verifique se é o arquivo de feriados nacionais da ANBIMA (feriados_nacionais.xls).',
            );
        }

        $result->total = count($holidays);
        $timestamp = Date::now();

        foreach ($holidays as $dateString => $name) {
            $existing = BusinessHoliday::query()
                ->where('calendar_code', $calendarCode)
                ->where('source', 'anbima')
                ->whereDate('holiday_date', $dateString)
                ->first();

            if ($existing === null) {
                if (! $dryRun) {
                    BusinessHoliday::query()->create([
                        'calendar_code' => $calendarCode,
                        'holiday_date' => $dateString,
                        'name' => $name,
                        'source' => 'anbima',
                        'source_file' => $sourceFile,
                        'imported_at' => $timestamp,
                        'imported_by' => $importedByUserId,
                    ]);
                }

                $result->imported++;

                continue;
            }

            if ($force) {
                $existing->fill([
                    'name' => $name,
                    'source_file' => $sourceFile,
                    'imported_at' => $timestamp,
                    'imported_by' => $importedByUserId,
                ]);

                if ($existing->isDirty(['name', 'source_file'])) {
                    if (! $dryRun) {
                        $existing->save();
                    }

                    $result->updated++;

                    continue;
                }
            }

            $result->skipped++;
        }

        if (! $dryRun) {
            $result->calendarApplied = $this->applyToCalendar($calendarCode, array_keys($holidays), $holidays);
            $this->businessDayCalendar->flushCache();
        }

        return $result;
    }

    private function download(string $url): string
    {
        try {
            $response = Http::timeout(30)
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

    private function parseCellDate(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): ?CarbonImmutable
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
     * Aplica os feriados ao calendário de dias úteis: cada data vira NÃO útil (is_business_day=false),
     * sobrepondo qualquer linha gerada pelo backfill. Idempotente.
     *
     * @param  list<string>  $dateStrings
     * @param  array<string, ?string>  $names
     */
    private function applyToCalendar(string $calendarCode, array $dateStrings, array $names): int
    {
        $applied = 0;

        foreach ($dateStrings as $dateString) {
            $calendarDate = BusinessCalendarDate::query()
                ->where('calendar_code', $calendarCode)
                ->whereDate('calendar_date', $dateString)
                ->first()
                ?? new BusinessCalendarDate([
                    'calendar_code' => $calendarCode,
                    'calendar_date' => $dateString,
                ]);

            $calendarDate->is_business_day = false;
            $calendarDate->description = $names[$dateString] ?? 'Feriado ANBIMA';

            if (! $calendarDate->exists || $calendarDate->isDirty()) {
                $calendarDate->save();
                $applied++;
            }
        }

        return $applied;
    }
}

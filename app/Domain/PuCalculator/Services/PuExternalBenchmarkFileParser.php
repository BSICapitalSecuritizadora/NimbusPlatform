<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkDataset;
use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkRowData;
use App\Domain\PuCalculator\Support\PuExternalBenchmarkCsvValueBinder;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Contrato de precisão por formato:
 *
 * - CSV é texto e chega ao domínio sem passar por float, preservando o
 *   literal decimal exato até a canonicalização em UNIT_SCALE.
 * - XLSX com PU em célula de texto preserva o literal exato; PU em célula
 *   numérica é normalizado a partir do double realmente armazenado no
 *   workbook (limitação IEEE-754 da fonte, nunca precisão inventada).
 */
final class PuExternalBenchmarkFileParser
{
    public const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const DATE_HEADERS = ['data', 'date', 'data de referencia', 'reference date'];

    /** @var list<string> */
    private const UNIT_VALUE_HEADERS = ['pu', 'preco unitario', 'unit value'];

    public function __construct(
        private readonly DecimalRounder $rounder,
        private readonly PuExternalBenchmarkFingerprintService $fingerprints,
    ) {}

    public function parse(string $path): PuExternalBenchmarkDataset
    {
        $this->assertReadableFile($path);
        $extension = Str::lower((string) pathinfo($path, PATHINFO_EXTENSION));
        $expectedReader = match ($extension) {
            'csv' => IOFactory::READER_CSV,
            'xlsx' => IOFactory::READER_XLSX,
            default => throw new InvalidArgumentException('Unsupported benchmark format. Only CSV and XLSX are accepted.'),
        };

        try {
            $identifiedReader = IOFactory::identify($path, [IOFactory::READER_CSV, IOFactory::READER_XLSX]);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The benchmark file is not compatible with a supported parser.', previous: $exception);
        }

        if ($identifiedReader !== $expectedReader) {
            throw new InvalidArgumentException('The benchmark file content does not match its extension.');
        }

        $spreadsheet = null;

        try {
            $reader = IOFactory::createReader($identifiedReader);
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(false);

            if ($reader instanceof Csv) {
                $reader->setValueBinder(new PuExternalBenchmarkCsvValueBinder);
            }

            $spreadsheet = $reader->load($path);
            $rows = $this->rows($spreadsheet->getSheet(0));
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The benchmark file could not be parsed safely.', previous: $exception);
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
        }

        if ($rows === []) {
            throw new InvalidArgumentException('The benchmark file must contain at least one data row.');
        }

        usort($rows, fn (PuExternalBenchmarkRowData $left, PuExternalBenchmarkRowData $right): int => $left->referenceDate <=> $right->referenceDate);
        $datasetSha256 = $this->fingerprints->dataset($rows);

        return new PuExternalBenchmarkDataset(
            inputFileName: basename(str_replace('\\', '/', $path)),
            fileSha256: hash_file('sha256', $path),
            datasetSha256: $datasetSha256,
            rows: $rows,
            fromDate: $rows[0]->referenceDate->toDateString(),
            toDate: $rows[array_key_last($rows)]->referenceDate->toDateString(),
        );
    }

    private function assertReadableFile(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The benchmark file does not exist or is not readable.');
        }

        $size = filesize($path);

        if ($size === false || $size === 0) {
            throw new InvalidArgumentException('The benchmark file is empty.');
        }

        if ($size > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException('The benchmark file exceeds the 10 MiB limit.');
        }
    }

    /** @return list<PuExternalBenchmarkRowData> */
    private function rows(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        if ($highestRow < 2 || $highestColumnIndex < 2) {
            return [];
        }

        $this->assertNoFormulas($sheet, $highestRow, $highestColumnIndex);
        [$dateColumn, $unitValueColumn] = $this->resolveColumns($sheet, $highestColumnIndex);
        $rows = [];
        $seenDates = [];

        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $dateValue = $sheet->getCell([$dateColumn, $rowNumber])->getValue();
            $unitValue = $sheet->getCell([$unitValueColumn, $rowNumber])->getValue();

            if ($this->blank($dateValue) && $this->blank($unitValue)) {
                continue;
            }

            if ($this->blank($dateValue) || $this->blank($unitValue)) {
                throw new InvalidArgumentException("Benchmark row {$rowNumber} is incomplete.");
            }

            $date = $this->date($dateValue, $rowNumber);
            $dateKey = $date->toDateString();

            if (isset($seenDates[$dateKey])) {
                throw new InvalidArgumentException(sprintf(
                    'Benchmark date %s is duplicated on rows %d and %d.',
                    $dateKey,
                    $seenDates[$dateKey],
                    $rowNumber,
                ));
            }

            $seenDates[$dateKey] = $rowNumber;
            $rows[] = new PuExternalBenchmarkRowData(
                referenceDate: $date,
                unitValue: $this->unitValue($unitValue, $rowNumber),
            );
        }

        return $rows;
    }

    private function assertNoFormulas(Worksheet $sheet, int $highestRow, int $highestColumn): void
    {
        for ($row = 1; $row <= $highestRow; $row++) {
            for ($column = 1; $column <= $highestColumn; $column++) {
                if ($sheet->getCell([$column, $row])->getDataType() === DataType::TYPE_FORMULA) {
                    throw new InvalidArgumentException(sprintf(
                        'Formula cells are not accepted; materialize the value at row %d, column %d.',
                        $row,
                        $column,
                    ));
                }
            }
        }
    }

    /** @return array{int,int} */
    private function resolveColumns(Worksheet $sheet, int $highestColumn): array
    {
        $dateColumns = [];
        $unitValueColumns = [];

        for ($column = 1; $column <= $highestColumn; $column++) {
            $header = $this->normalizeHeader($sheet->getCell([$column, 1])->getValue());

            if (in_array($header, self::DATE_HEADERS, true)) {
                $dateColumns[] = $column;
            }

            if (in_array($header, self::UNIT_VALUE_HEADERS, true)) {
                $unitValueColumns[] = $column;
            }
        }

        if (count($dateColumns) !== 1 || count($unitValueColumns) !== 1) {
            throw new InvalidArgumentException(
                'The benchmark header must contain exactly one date column and one PU column.',
            );
        }

        return [$dateColumns[0], $unitValueColumns[0]];
    }

    private function normalizeHeader(mixed $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replace(['_', '-'], ' ')
            ->squish()
            ->toString();
    }

    private function date(mixed $value, int $rowNumber): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        // Seriais Excel chegavam como int/float pelo binder padrão; com o
        // transporte textual do CSV, o literal numérico recebe o mesmo
        // tratamento. Seriais são resolução de dia, sem risco financeiro.
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            return CarbonImmutable::instance(
                ExcelDate::excelToDateTimeObject((float) trim((string) $value), new DateTimeZone('UTC')),
            )->startOfDay();
        }

        $dateValue = trim((string) $value);

        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $dateValue);
            } catch (Throwable) {
                $date = null;
            }

            if ($date instanceof CarbonImmutable
                && in_array($date->format($format === '!Y-m-d' ? 'Y-m-d' : 'd/m/Y'), [$dateValue], true)) {
                return $date->startOfDay();
            }
        }

        throw new InvalidArgumentException("Benchmark row {$rowNumber} contains an invalid date.");
    }

    private function unitValue(mixed $value, int $rowNumber): string
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw new InvalidArgumentException("Benchmark row {$rowNumber} contains an invalid PU.");
        }

        // CSV chega como texto exato. Células numéricas XLSX chegam como o
        // double armazenado no workbook; aqui ele é apenas materializado em
        // string para a validação sintática e a canonicalização seguintes.
        $value = (string) $value;

        $normalized = Str::of($value)
            ->trim()
            ->replace(["\u{00A0}", ' '], '')
            ->replace(',', '.')
            ->toString();

        if (preg_match('/^\d{1,14}(?:\.\d{1,16})?$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Benchmark row {$rowNumber} contains an invalid PU decimal.");
        }

        $decimal = $this->rounder->normalize($normalized, DecimalRounder::UNIT_SCALE);

        if (bccomp($decimal, '0', DecimalRounder::UNIT_SCALE) !== 1) {
            throw new InvalidArgumentException("Benchmark row {$rowNumber} PU must be greater than zero.");
        }

        return $decimal;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}

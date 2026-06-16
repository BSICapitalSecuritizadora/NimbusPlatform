<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\SpreadsheetReferenceRowData;
use App\Domain\PuCalculator\ValueObjects\Decimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;

class PuSpreadsheetReferenceReader
{
    /**
     * @return array{sheet_name: string, rows: list<SpreadsheetReferenceRowData>}
     */
    public function read(string $path, string $sheetName = 'PuDiario'): array
    {
        $rows = SimpleExcelReader::create($path)
            ->fromSheetName($sheetName)
            ->noHeaderRow()
            ->getRows()
            ->all();

        $headerRowIndex = null;
        $columnMap = [];

        foreach ($rows as $index => $row) {
            if (($this->normalizeHeader($row[0] ?? null) ?? null) !== 'data') {
                continue;
            }

            $headerRowIndex = $index;

            foreach ($row as $columnIndex => $columnLabel) {
                $columnMap[$this->normalizeHeader($columnLabel) ?? ''] = $columnIndex;
            }

            break;
        }

        if ($headerRowIndex === null) {
            return ['sheet_name' => $sheetName, 'rows' => []];
        }

        $referenceRows = [];

        foreach (array_slice($rows, $headerRowIndex + 1) as $row) {
            $date = $this->parseDate($row[$columnMap['data'] ?? 0] ?? null);

            if ($date === null) {
                continue;
            }

            $referenceRows[] = new SpreadsheetReferenceRowData(
                date: $date,
                updatedUnitValue: $this->parseDecimal($row[$columnMap['valorunitariocorrigidomaisjurosvalorunitarioatualizado'] ?? -1] ?? null, DecimalRounder::VALIDATION_SCALE),
                residualUnitValue: $this->parseDecimal($row[$columnMap['valorunitarioresidual'] ?? -1] ?? null, DecimalRounder::VALIDATION_SCALE),
                interestRealUnitValue: $this->parseDecimal($row[$columnMap['jurosreal'] ?? -1] ?? null, DecimalRounder::VALIDATION_SCALE),
                amortizationUnitValue: $this->parseDecimal($row[$columnMap['amortizacaoreal'] ?? -1] ?? null, DecimalRounder::VALIDATION_SCALE),
                quantity: $this->parseDecimal($row[$columnMap['quantidade'] ?? -1] ?? null, DecimalRounder::QUANTITY_SCALE),
                totalValue: $this->parseDecimal($row[$columnMap['valortotal'] ?? -1] ?? null, DecimalRounder::VALIDATION_SCALE),
                paymentTotalValue: $this->parseDecimal($row[$columnMap['pgtotalseminadimpencia'] ?? -1] ?? null, DecimalRounder::VALIDATION_SCALE),
                indexRateValue: $this->parseDecimal($row[$columnMap['valordoindiceutilizado'] ?? -1] ?? null, DecimalRounder::RATE_SCALE),
                dupInterest: $this->parseInteger($row[$columnMap['dupjuros'] ?? -1] ?? null),
                dutInterest: $this->parseInteger($row[$columnMap['dutjuros'] ?? -1] ?? null),
            );
        }

        return [
            'sheet_name' => $sheetName,
            'rows' => $referenceRows,
        ];
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private function parseDecimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DecimalRounder)->round(
            Decimal::of(is_string($value) ? trim($value) : $value)->value(),
            $scale,
        );
    }

    private function parseInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeHeader(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = Str::of(Str::ascii(trim($value)))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();

        return $normalizedValue !== '' ? $normalizedValue : null;
    }
}

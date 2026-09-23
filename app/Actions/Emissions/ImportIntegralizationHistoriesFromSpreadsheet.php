<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\ValueObjects\Decimal;
use App\Enums\IntegralizationSource;
use App\Models\Emission;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Lê a planilha e entrega cada linha a {@see RecordIntegralizationHistory},
 * a mesma operação do cadastro manual. Aqui fica só o que é da planilha:
 * mapear colunas, converter células em decimal canônico e pular linhas sem
 * data ou sem quantidade (em branco, totais, cabeçalhos repetidos).
 */
class ImportIntegralizationHistoriesFromSpreadsheet
{
    protected const HEADER_FIELD_MAP = [
        'data' => 'date',
        'date' => 'date',
        'quantidade' => 'quantity',
        'quantity' => 'quantity',
        'qtd' => 'quantity',
        'pu' => 'unit_value',
        'precounitario' => 'unit_value',
        'unitvalue' => 'unit_value',
        'financeiro' => 'financial_value',
        'valorfinanceiro' => 'financial_value',
        'fundoinvestidor' => 'investor_fund',
        'fundo' => 'investor_fund',
        'investidor' => 'investor_fund',
    ];

    public function __construct(private RecordIntegralizationHistory $recordIntegralizationHistory) {}

    public function handle(string $path, Emission $emission): int
    {
        $rows = SimpleExcelReader::create($path)
            ->noHeaderRow()
            ->getRows()
            ->all();

        if ($rows === []) {
            return 0;
        }

        $columnMap = $this->resolveColumnMap($rows[0]);

        if ($columnMap['has_header']) {
            array_shift($rows);
        }

        $importedIntegralizationHistories = 0;

        DB::transaction(function () use ($rows, $columnMap, $emission, &$importedIntegralizationHistories): void {
            foreach ($rows as $row) {
                $date = $this->parseDate($row[$columnMap['columns']['date']] ?? null);
                $quantity = $this->parseAmount($row[$columnMap['columns']['quantity']] ?? null);

                if (! $date || $quantity === null || bccomp($quantity, '0', RecordIntegralizationHistory::QUANTITY_SCALE) <= 0) {
                    continue;
                }

                $this->recordIntegralizationHistory->createOrUpdateForDate($emission, [
                    'date' => $date,
                    'quantity' => $quantity,
                    'unit_value' => $this->parseAmount($row[$columnMap['columns']['unit_value']] ?? null),
                    'financial_value' => $this->parseAmount($row[$columnMap['columns']['financial_value']] ?? null),
                    'investor_fund' => $this->parseText($row[$columnMap['columns']['investor_fund']] ?? null),
                ], IntegralizationSource::Spreadsheet);

                $importedIntegralizationHistories++;
            }
        });

        return $importedIntegralizationHistories;
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array{has_header: bool, columns: array<string, int>}
     */
    protected function resolveColumnMap(array $headerRow): array
    {
        $columns = [];

        foreach ($headerRow as $index => $value) {
            $field = self::HEADER_FIELD_MAP[$this->normalizeHeader($value) ?? ''] ?? null;

            if ($field !== null) {
                $columns[$field] = $index;
            }
        }

        if (array_key_exists('date', $columns) && array_key_exists('quantity', $columns)) {
            return [
                'has_header' => true,
                'columns' => [
                    'date' => $columns['date'],
                    'quantity' => $columns['quantity'],
                    'unit_value' => $columns['unit_value'] ?? 2,
                    'financial_value' => $columns['financial_value'] ?? 3,
                    'investor_fund' => $columns['investor_fund'] ?? 4,
                ],
            ];
        }

        return [
            'has_header' => false,
            'columns' => [
                'date' => 0,
                'quantity' => 1,
                'unit_value' => 2,
                'financial_value' => 3,
                'investor_fund' => 4,
            ],
        ];
    }

    protected function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        if ($normalizedValue === '' || in_array(strtolower($normalizedValue), ['data', 'date'], true)) {
            return null;
        }

        foreach (['d/m/Y', 'd/m/Y H:i:s', 'Y-m-d', 'Y-m-d H:i:s'] as $format) {
            try {
                return Carbon::createFromFormat($format, $normalizedValue)->toDateString();
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($normalizedValue)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Decimal canônico ("1000.5"), sem passar por `float` quando a célula é
     * texto. Célula numérica já chega `float` do leitor e é convertida sem
     * notação científica; a escala da coluna é aplicada adiante.
     */
    protected function parseAmount(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return Decimal::of($value)->value();
        }

        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            return null;
        }

        $header = $this->normalizeHeader($normalizedValue);

        if ($header !== null && in_array($header, array_keys(self::HEADER_FIELD_MAP), true)) {
            return null;
        }

        $normalizedValue = str_replace(['R$', ' '], '', $normalizedValue);

        if (str_contains($normalizedValue, ',') && str_contains($normalizedValue, '.')) {
            $normalizedValue = str_replace('.', '', $normalizedValue);
        }

        if (str_contains($normalizedValue, ',')) {
            $normalizedValue = str_replace(',', '.', $normalizedValue);
        }

        if (! is_numeric($normalizedValue)) {
            return null;
        }

        return RecordIntegralizationHistory::isPlainDecimal($normalizedValue)
            ? $normalizedValue
            : Decimal::of((float) $normalizedValue)->value();
    }

    protected function parseText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    protected function normalizeHeader(mixed $value): ?string
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

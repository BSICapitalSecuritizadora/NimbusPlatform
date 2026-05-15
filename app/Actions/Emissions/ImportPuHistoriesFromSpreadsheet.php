<?php

namespace App\Actions\Emissions;

use App\Models\Emission;
use App\Models\PuHistory;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;

class ImportPuHistoriesFromSpreadsheet
{
    protected const HEADER_FIELD_MAP = [
        'data' => 'date',
        'date' => 'date',
        'pu' => 'unit_value',
        'puatualizado' => 'unit_value',
        'puatualizador' => 'unit_value',
        'precounitario' => 'unit_value',
        'unitvalue' => 'unit_value',
    ];

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

        $importedPuHistories = 0;

        foreach ($rows as $row) {
            $date = $this->parseDate($row[$columnMap['columns']['date']] ?? null);
            $unitValue = $this->parseAmount($row[$columnMap['columns']['unit_value']] ?? null);

            if (! $date || $unitValue === null || $unitValue <= 0) {
                continue;
            }

            $puHistory = PuHistory::query()
                ->where('emission_id', $emission->id)
                ->whereDate('date', $date)
                ->first();

            if ($puHistory) {
                $puHistory->fill(['unit_value' => $unitValue]);
                $puHistory->save();
            } else {
                PuHistory::query()->create([
                    'emission_id' => $emission->id,
                    'date' => $date,
                    'unit_value' => $unitValue,
                ]);
            }

            $importedPuHistories++;
        }

        $latestPuHistory = $emission->puHistories()->orderByDesc('date')->first();

        if ($latestPuHistory) {
            $emission->update(['current_pu' => $latestPuHistory->unit_value]);
        }

        return $importedPuHistories;
    }

    /**
     * @param  array<int, mixed>  $firstRow
     * @return array{has_header: bool, columns: array{date: int, unit_value: int}}
     */
    protected function resolveColumnMap(array $firstRow): array
    {
        $columns = [];

        foreach ($firstRow as $index => $value) {
            $field = self::HEADER_FIELD_MAP[$this->normalizeHeader($value) ?? ''] ?? null;

            if ($field !== null) {
                $columns[$field] = $index;
            }
        }

        if (array_key_exists('date', $columns) && array_key_exists('unit_value', $columns)) {
            return [
                'has_header' => true,
                'columns' => [
                    'date' => $columns['date'],
                    'unit_value' => $columns['unit_value'],
                ],
            ];
        }

        return [
            'has_header' => false,
            'columns' => $this->resolveLegacyColumns($firstRow),
        ];
    }

    /**
     * @param  array<int, mixed>  $firstRow
     * @return array{date: int, unit_value: int}
     */
    protected function resolveLegacyColumns(array $firstRow): array
    {
        $candidates = [
            ['date' => 0, 'unit_value' => 1],
            ['date' => 0, 'unit_value' => 11],
        ];

        foreach ($candidates as $candidate) {
            $date = $this->parseDate($firstRow[$candidate['date']] ?? null);
            $unitValue = $this->parseAmount($firstRow[$candidate['unit_value']] ?? null);

            if ($date && $unitValue !== null) {
                return $candidate;
            }
        }

        return $candidates[0];
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

    protected function parseAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        if ($normalizedValue === '' || in_array(strtolower($normalizedValue), ['pu', 'unit value'], true)) {
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

        return (float) $normalizedValue;
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

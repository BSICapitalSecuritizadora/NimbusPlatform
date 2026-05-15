<?php

namespace App\Actions\Emissions;

use App\Models\Emission;
use App\Models\Payment;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Str;
use Spatie\SimpleExcel\SimpleExcelReader;

class ImportPaymentsFromSpreadsheet
{
    protected const PAYMENT_FIELDS = [
        'premium_value',
        'interest_value',
        'amortization_value',
        'extra_amortization_value',
    ];

    protected const HEADER_FIELD_MAP = [
        'data' => 'payment_date',
        'datadopagamento' => 'payment_date',
        'premio' => 'premium_value',
        'juros' => 'interest_value',
        'pgtojurostotal' => 'interest_value',
        'pagtojurostotal' => 'interest_value',
        'amortizacao' => 'amortization_value',
        'amortizacaoextra' => 'extra_amortization_value',
        'amortizacaoextraordinaria' => 'extra_amortization_value',
    ];

    public function handle(string $path, Emission $emission): int
    {
        $rows = SimpleExcelReader::create($path)
            ->noHeaderRow()
            ->getRows()
            ->all();

        $columnMap = $this->resolveColumnMap($rows[0] ?? []);

        if ($columnMap['has_header']) {
            array_shift($rows);
        }

        $importedPayments = 0;

        foreach ($rows as $row) {
            $paymentDate = $this->parseDate($row[$columnMap['columns']['payment_date']] ?? null);
            $paymentValues = $this->extractPaymentValues($row, $columnMap['columns']);

            if (! $paymentDate || ! $paymentValues['should_import']) {
                continue;
            }

            $payment = Payment::query()
                ->where('emission_id', $emission->id)
                ->whereDate('payment_date', $paymentDate)
                ->first();

            if ($payment) {
                $payment->fill($paymentValues['values']);
                $payment->save();

                $importedPayments++;

                continue;
            }

            Payment::query()->create([
                'emission_id' => $emission->id,
                'payment_date' => $paymentDate,
                ...$this->defaultPaymentValues(),
                ...$paymentValues['values'],
            ]);

            $importedPayments++;
        }

        return $importedPayments;
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

        if (($columns !== []) && array_key_exists('payment_date', $columns)) {
            return [
                'has_header' => true,
                'columns' => $columns,
            ];
        }

        return [
            'has_header' => false,
            'columns' => [
                'payment_date' => 0,
                'interest_value' => 1,
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columnMap
     * @return array{should_import: bool, values: array<string, float>}
     */
    protected function extractPaymentValues(array $row, array $columnMap): array
    {
        $values = [];
        $hasAnyMappedAmount = false;

        foreach (self::PAYMENT_FIELDS as $field) {
            if (! array_key_exists($field, $columnMap)) {
                continue;
            }

            $amount = $this->parseAmount($row[$columnMap[$field]] ?? null);

            if ($amount !== null) {
                $hasAnyMappedAmount = true;
            }

            $values[$field] = $amount ?? 0.0;
        }

        return [
            'should_import' => $hasAnyMappedAmount,
            'values' => $values,
        ];
    }

    /**
     * @return array<string, float>
     */
    protected function defaultPaymentValues(): array
    {
        return [
            'premium_value' => 0.0,
            'interest_value' => 0.0,
            'amortization_value' => 0.0,
            'extra_amortization_value' => 0.0,
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

        if ($normalizedValue === '' || strtolower($normalizedValue) === 'data') {
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

        if ($normalizedValue === '' || strtolower($normalizedValue) === 'pgto. juros total') {
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

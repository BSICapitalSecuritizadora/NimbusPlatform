<?php

namespace App\Actions\Emissions;

use App\Models\Emission;
use App\Models\Payment;
use Carbon\Carbon;
use DateTimeInterface;
use Spatie\SimpleExcel\SimpleExcelReader;

class ImportPaymentsFromSpreadsheet
{
    public function handle(string $path, Emission $emission): int
    {
        $rows = SimpleExcelReader::create($path)
            ->noHeaderRow()
            ->getRows();

        $importedPayments = 0;

        $rows->each(function (array $row) use ($emission, &$importedPayments): void {
            $paymentDate = $this->parseDate($row[0] ?? null);
            $interestValue = $this->parseAmount($row[1] ?? null);

            if (! $paymentDate || $interestValue === null) {
                return;
            }

            $payment = Payment::query()
                ->where('emission_id', $emission->id)
                ->whereDate('payment_date', $paymentDate)
                ->first();

            if ($payment) {
                $payment->interest_value = $interestValue;
                $payment->save();

                $importedPayments++;

                return;
            }

            Payment::query()->create([
                'emission_id' => $emission->id,
                'payment_date' => $paymentDate,
                'premium_value' => 0,
                'interest_value' => $interestValue,
                'amortization_value' => 0,
                'extra_amortization_value' => 0,
            ]);

            $importedPayments++;
        });

        return $importedPayments;
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
}

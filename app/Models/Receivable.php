<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receivable extends Model
{
    /** @use HasFactory<\Database\Factories\ReceivableFactory> */
    use HasFactory;

    protected const MONEY_ATTRIBUTES = [
        'expected_interest_amount',
        'expected_amortization_amount',
        'received_installment_interest_amount',
        'received_installment_amortization_amount',
        'received_prepayment_interest_amount',
        'received_prepayment_amortization_amount',
        'received_default_interest_amount',
        'received_default_amortization_amount',
        'received_interest_and_penalty_amount',
        'performing_balance_pre_event_amount',
        'non_performing_balance_pre_event_amount',
        'performing_balance_post_event_amount',
        'non_performing_balance_post_event_amount',
        'monthly_default_balance_amount',
        'total_default_balance_amount',
        'linked_credits_current_amount',
        'overdue_up_to_30_days_amount',
        'overdue_31_to_60_days_amount',
        'overdue_61_to_90_days_amount',
        'overdue_91_to_120_days_amount',
        'overdue_121_to_150_days_amount',
        'overdue_151_to_180_days_amount',
        'overdue_181_to_360_days_amount',
        'overdue_over_360_days_amount',
        'prepaid_up_to_30_days_amount',
        'prepaid_31_to_60_days_amount',
        'prepaid_61_to_90_days_amount',
        'prepaid_91_to_120_days_amount',
        'prepaid_121_to_150_days_amount',
        'prepaid_151_to_180_days_amount',
        'prepaid_181_to_360_days_amount',
        'prepaid_over_360_days_amount',
        'linked_credits_up_to_30_days_amount',
        'linked_credits_31_to_60_days_amount',
        'linked_credits_61_to_90_days_amount',
        'linked_credits_91_to_120_days_amount',
        'linked_credits_121_to_150_days_amount',
        'linked_credits_151_to_180_days_amount',
        'linked_credits_181_to_360_days_amount',
        'linked_credits_over_360_days_amount',
        'guarantees_value_amount',
        'total_prepayment_amount',
        'total_outstanding_balance_amount',
    ];

    protected const DECIMAL_ATTRIBUTES = [
        'top_five_debtors_concentration_ratio',
        'portfolio_ltv_ratio',
        'sale_ltv_ratio',
        'portfolio_duration_years',
        'portfolio_duration_months',
    ];

    protected const INTEGER_ATTRIBUTES = [
        'active_contracts_count',
    ];

    protected $fillable = [
        'emission_id',
        'reference_month',
        'portfolio_id',
        'active_contracts_count',
        'expected_interest_amount',
        'expected_amortization_amount',
        'received_installment_interest_amount',
        'received_installment_amortization_amount',
        'received_prepayment_interest_amount',
        'received_prepayment_amortization_amount',
        'received_default_interest_amount',
        'received_default_amortization_amount',
        'received_interest_and_penalty_amount',
        'performing_balance_pre_event_amount',
        'non_performing_balance_pre_event_amount',
        'performing_balance_post_event_amount',
        'non_performing_balance_post_event_amount',
        'monthly_default_balance_amount',
        'total_default_balance_amount',
        'linked_credits_current_amount',
        'overdue_up_to_30_days_amount',
        'overdue_31_to_60_days_amount',
        'overdue_61_to_90_days_amount',
        'overdue_91_to_120_days_amount',
        'overdue_121_to_150_days_amount',
        'overdue_151_to_180_days_amount',
        'overdue_181_to_360_days_amount',
        'overdue_over_360_days_amount',
        'prepaid_up_to_30_days_amount',
        'prepaid_31_to_60_days_amount',
        'prepaid_61_to_90_days_amount',
        'prepaid_91_to_120_days_amount',
        'prepaid_121_to_150_days_amount',
        'prepaid_151_to_180_days_amount',
        'prepaid_181_to_360_days_amount',
        'prepaid_over_360_days_amount',
        'linked_credits_up_to_30_days_amount',
        'linked_credits_31_to_60_days_amount',
        'linked_credits_61_to_90_days_amount',
        'linked_credits_91_to_120_days_amount',
        'linked_credits_121_to_150_days_amount',
        'linked_credits_151_to_180_days_amount',
        'linked_credits_181_to_360_days_amount',
        'linked_credits_over_360_days_amount',
        'guarantees_value_amount',
        'total_prepayment_amount',
        'top_five_debtors_concentration_ratio',
        'total_outstanding_balance_amount',
        'portfolio_ltv_ratio',
        'sale_ltv_ratio',
        'portfolio_duration_years',
        'portfolio_duration_months',
        'average_rate_details',
        'summary_payload',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $receivable): void {
            $receivable->reference_month = self::normalizeReferenceMonth($receivable->reference_month);
            $receivable->portfolio_id = self::normalizeText($receivable->portfolio_id);
            $receivable->average_rate_details = self::normalizeMultilineText($receivable->average_rate_details);

            foreach (self::INTEGER_ATTRIBUTES as $attribute) {
                $receivable->{$attribute} = self::normalizeInteger($receivable->{$attribute});
            }

            foreach (self::MONEY_ATTRIBUTES as $attribute) {
                $receivable->{$attribute} = self::normalizeMoney($receivable->{$attribute});
            }

            foreach (self::DECIMAL_ATTRIBUTES as $attribute) {
                $receivable->{$attribute} = self::normalizeMetricDecimal($receivable->{$attribute});
            }
        });
    }

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'active_contracts_count' => 'integer',
            ...array_fill_keys(self::MONEY_ATTRIBUTES, 'decimal:2'),
            ...array_fill_keys(self::DECIMAL_ATTRIBUTES, 'decimal:6'),
            'summary_payload' => 'array',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public static function normalizeReferenceMonth(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->startOfMonth()->toDateString();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($value))
                ->startOfMonth()
                ->toDateString();
        }

        if (blank($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^(\d{2})\/(\d{4})$/', $value, $matches) === 1) {
            $month = (int) $matches[1];
            $year = (int) $matches[2];

            return checkdate($month, 1, $year)
                ? sprintf('%04d-%02d-01', $year, $month)
                : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];

            return checkdate($month, 1, $year)
                ? sprintf('%04d-%02d-01', $year, $month)
                : null;
        }

        try {
            return Carbon::parse($value)->startOfMonth()->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formatReferenceMonthForDisplay(mixed $value): string
    {
        $referenceMonth = self::normalizeReferenceMonth($value);

        if ($referenceMonth === null) {
            return '';
        }

        return Carbon::parse($referenceMonth)->format('m/Y');
    }

    public static function normalizeText(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return trim((string) $value);
    }

    public static function normalizeMultilineText(mixed $value): ?string
    {
        $value = self::normalizeText($value);

        if ($value === null) {
            return null;
        }

        $lines = array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/u', $value) ?: [],
        );
        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        return $lines === [] ? null : implode(PHP_EOL, $lines);
    }

    public static function normalizeInteger(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        $normalized = str_replace(['.', ','], ['', '.'], trim((string) $value));

        if (! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }

    public static function normalizeMoney(mixed $value): ?float
    {
        if (blank($value)) {
            return null;
        }

        return MoneyFormatter::normalizeDecimalValue($value);
    }

    public static function normalizeMetricDecimal(mixed $value): ?float
    {
        if (blank($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $value = trim((string) $value);
        $value = str_replace(['R$', ' '], '', $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $lastCommaPosition = strrpos($value, ',');
            $lastDotPosition = strrpos($value, '.');

            if (($lastCommaPosition !== false) && ($lastDotPosition !== false) && ($lastCommaPosition > $lastDotPosition)) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, '.')) {
            $parts = explode('.', $value);

            if ((count($parts) > 2) || (strlen((string) end($parts)) === 3)) {
                $value = str_replace('.', '', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } else {
            $value = str_replace(',', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}

<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesBoard extends Model
{
    /** @use HasFactory<\Database\Factories\SalesBoardFactory> */
    use HasFactory;

    protected const TRACKED_VALUE_FIELDS = [
        'stock_units',
        'financed_units',
        'paid_units',
        'exchanged_units',
        'total_units',
        'stock_value',
        'financed_value',
        'paid_value',
        'exchanged_value',
    ];

    protected $fillable = [
        'emission_id',
        'construction_id',
        'reference_month',
        'stock_units',
        'financed_units',
        'paid_units',
        'exchanged_units',
        'total_units',
        'stock_value',
        'financed_value',
        'paid_value',
        'exchanged_value',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $salesBoard): void {
            $salesBoard->reference_month = self::normalizeReferenceMonth($salesBoard->reference_month);
            $salesBoard->total_units = $salesBoard->calculateTotalUnits();
        });
    }

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'stock_units' => 'integer',
            'financed_units' => 'integer',
            'paid_units' => 'integer',
            'exchanged_units' => 'integer',
            'total_units' => 'integer',
            'stock_value' => 'decimal:2',
            'financed_value' => 'decimal:2',
            'paid_value' => 'decimal:2',
            'exchanged_value' => 'decimal:2',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function construction(): BelongsTo
    {
        return $this->belongsTo(Construction::class);
    }

    public function valueHistories(): HasMany
    {
        return $this->hasMany(SalesBoardHistory::class);
    }

    public function calculateTotalUnits(): int
    {
        return (int) $this->stock_units
            + (int) $this->financed_units
            + (int) $this->paid_units
            + (int) $this->exchanged_units;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function hasTrackedValueChanges(array $data): bool
    {
        $currentValues = $this->trackedValueSnapshotData();
        $incomingValues = $this->trackedValueSnapshotData($data);

        foreach (self::TRACKED_VALUE_FIELDS as $field) {
            if ($currentValues[$field] !== $incomingValues[$field]) {
                return true;
            }
        }

        return false;
    }

    public function snapshotTrackedValues(): SalesBoardHistory
    {
        return $this->valueHistories()->create($this->trackedValueSnapshotData());
    }

    public function getFormattedReferenceMonthAttribute(): string
    {
        return self::formatReferenceMonthForDisplay($this->reference_month);
    }

    public function getFormattedStockValueAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->stock_value);
    }

    public function getFormattedFinancedValueAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->financed_value);
    }

    public function getFormattedPaidValueAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->paid_value);
    }

    public function getFormattedExchangedValueAttribute(): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($this->exchanged_value);
    }

    public static function normalizeReferenceMonth(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->startOfMonth()->toDateString();
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, int|float|string|null>
     */
    protected function trackedValueSnapshotData(array $overrides = []): array
    {
        $stockUnits = self::normalizeIntegerValue($overrides['stock_units'] ?? $this->stock_units);
        $financedUnits = self::normalizeIntegerValue($overrides['financed_units'] ?? $this->financed_units);
        $paidUnits = self::normalizeIntegerValue($overrides['paid_units'] ?? $this->paid_units);
        $exchangedUnits = self::normalizeIntegerValue($overrides['exchanged_units'] ?? $this->exchanged_units);

        return [
            'reference_month' => self::normalizeReferenceMonth($overrides['reference_month'] ?? $this->reference_month),
            'stock_units' => $stockUnits,
            'financed_units' => $financedUnits,
            'paid_units' => $paidUnits,
            'exchanged_units' => $exchangedUnits,
            'total_units' => $stockUnits + $financedUnits + $paidUnits + $exchangedUnits,
            'stock_value' => MoneyFormatter::normalizeDecimalValue($overrides['stock_value'] ?? $this->stock_value),
            'financed_value' => MoneyFormatter::normalizeDecimalValue($overrides['financed_value'] ?? $this->financed_value),
            'paid_value' => MoneyFormatter::normalizeDecimalValue($overrides['paid_value'] ?? $this->paid_value),
            'exchanged_value' => MoneyFormatter::normalizeDecimalValue($overrides['exchanged_value'] ?? $this->exchanged_value),
        ];
    }

    protected static function normalizeIntegerValue(mixed $value): int
    {
        return MoneyFormatter::normalizeIntegerValue($value);
    }
}

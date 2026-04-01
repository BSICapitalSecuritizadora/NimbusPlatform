<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProposalProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'proposal_id', 'name', 'development_name', 'website_url',
        'requested_amount', 'land_market_value', 'land_area',
        'zip_code', 'street', 'address_number', 'address_complement', 'neighborhood', 'city', 'state',
        'launch_date',
        'sales_launch_date',
        'construction_start_date',
        'delivery_forecast_date',
        'remaining_months',
        'exchanged_units',
        'paid_units', 'unpaid_units', 'stock_units', 'units_total',
        'sales_percentage', 'incurred_cost', 'cost_to_incur', 'total_cost', 'work_stage_percentage',
        'paid_sales_value', 'unpaid_sales_value', 'stock_sales_value', 'gross_sales_value', 'received_value', 'value_until_keys', 'value_after_keys',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $project): void {
            $project->syncOverviewValues();
            $project->syncSalesMetrics();
            $project->syncSaleValues();
            $project->syncCostMetrics();
        });
    }

    protected function casts(): array
    {
        return [
            'launch_date' => 'date',
            'sales_launch_date' => 'date',
            'construction_start_date' => 'date',
            'delivery_forecast_date' => 'date',
            'requested_amount' => 'decimal:2',
            'land_market_value' => 'decimal:2',
            'land_area' => 'decimal:2',
            'exchanged_units' => 'integer',
            'paid_units' => 'integer',
            'unpaid_units' => 'integer',
            'stock_units' => 'integer',
            'units_total' => 'integer',
            'sales_percentage' => 'decimal:2',
            'incurred_cost' => 'decimal:2',
            'cost_to_incur' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'work_stage_percentage' => 'decimal:2',
            'paid_sales_value' => 'decimal:2',
            'unpaid_sales_value' => 'decimal:2',
            'stock_sales_value' => 'decimal:2',
            'gross_sales_value' => 'decimal:2',
            'received_value' => 'decimal:2',
            'value_until_keys' => 'decimal:2',
            'value_after_keys' => 'decimal:2',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function characteristics(): HasOne
    {
        return $this->hasOne(ProjectCharacteristic::class, 'project_id');
    }

    public function indicators(): HasOne
    {
        return $this->hasOne(ProjectIndicator::class, 'project_id');
    }

    public static function calculateCostTotal(mixed $costIncurred, mixed $costToIncur): float
    {
        return round(
            self::normalizeDecimalValue($costIncurred) + self::normalizeDecimalValue($costToIncur),
            2,
        );
    }

    public static function calculateUnitsTotal(
        mixed $unitsUnpaid,
        mixed $unitsPaid,
        mixed $unitsExchanged,
        mixed $unitsStock,
    ): int {
        return self::normalizeIntegerValue($unitsUnpaid)
            + self::normalizeIntegerValue($unitsPaid)
            + self::normalizeIntegerValue($unitsExchanged)
            + self::normalizeIntegerValue($unitsStock);
    }

    public static function calculateSalesPercentage(
        mixed $unitsUnpaid,
        mixed $unitsPaid,
        mixed $unitsExchanged,
        mixed $unitsStock,
    ): float {
        $unitsUnpaid = self::normalizeIntegerValue($unitsUnpaid);
        $unitsPaid = self::normalizeIntegerValue($unitsPaid);
        $unitsExchanged = self::normalizeIntegerValue($unitsExchanged);
        $unitsStock = self::normalizeIntegerValue($unitsStock);
        $unitsTotal = self::calculateUnitsTotal($unitsUnpaid, $unitsPaid, $unitsExchanged, $unitsStock);
        $sellableUnits = $unitsTotal - $unitsExchanged;

        if ($sellableUnits <= 0) {
            return 0.0;
        }

        return round((($unitsUnpaid + $unitsPaid) / $sellableUnits) * 100, 2);
    }

    public static function calculateWorkStagePercentage(mixed $costIncurred, mixed $costTotal): float
    {
        $costIncurred = self::normalizeDecimalValue($costIncurred);
        $costTotal = self::normalizeDecimalValue($costTotal);

        if ($costTotal <= 0) {
            return 0.0;
        }

        return round(($costIncurred / $costTotal) * 100, 2);
    }

    public static function calculatePaymentFlowTotal(
        mixed $valueReceived,
        mixed $valueUntilKeys,
        mixed $valuePostKeys,
    ): float {
        return round(
            self::normalizeDecimalValue($valueReceived)
                + self::normalizeDecimalValue($valueUntilKeys)
                + self::normalizeDecimalValue($valuePostKeys),
            2,
        );
    }

    public static function calculateSalesValuesTotal(
        mixed $valuePaid,
        mixed $valueUnpaid,
        mixed $valueStock,
    ): float {
        return round(
            self::normalizeDecimalValue($valuePaid)
                + self::normalizeDecimalValue($valueUnpaid)
                + self::normalizeDecimalValue($valueStock),
            2,
        );
    }

    protected function syncSalesMetrics(): void
    {
        $this->exchanged_units = self::normalizeIntegerValue($this->exchanged_units);
        $this->paid_units = self::normalizeIntegerValue($this->paid_units);
        $this->unpaid_units = self::normalizeIntegerValue($this->unpaid_units);
        $this->stock_units = self::normalizeIntegerValue($this->stock_units);
        $this->units_total = self::calculateUnitsTotal(
            $this->unpaid_units,
            $this->paid_units,
            $this->exchanged_units,
            $this->stock_units,
        );
        $this->sales_percentage = self::calculateSalesPercentage(
            $this->unpaid_units,
            $this->paid_units,
            $this->exchanged_units,
            $this->stock_units,
        );
    }

    protected function syncOverviewValues(): void
    {
        $this->requested_amount = self::normalizeDecimalValue($this->requested_amount);
        $this->land_market_value = self::normalizeDecimalValue($this->land_market_value);
        $this->land_area = self::normalizeDecimalValue($this->land_area);
        $this->remaining_months = self::normalizeIntegerValue($this->remaining_months);
    }

    protected function syncCostMetrics(): void
    {
        $this->incurred_cost = self::normalizeDecimalValue($this->incurred_cost);
        $this->cost_to_incur = self::normalizeDecimalValue($this->cost_to_incur);
        $this->total_cost = self::calculateCostTotal($this->incurred_cost, $this->cost_to_incur);
        $this->work_stage_percentage = self::calculateWorkStagePercentage($this->incurred_cost, $this->total_cost);
    }

    protected function syncSaleValues(): void
    {
        $this->paid_sales_value = self::normalizeDecimalValue($this->paid_sales_value);
        $this->unpaid_sales_value = self::normalizeDecimalValue($this->unpaid_sales_value);
        $this->stock_sales_value = self::normalizeDecimalValue($this->stock_sales_value);
        $this->received_value = self::normalizeDecimalValue($this->received_value);
        $this->value_until_keys = self::normalizeDecimalValue($this->value_until_keys);
        $this->value_after_keys = self::normalizeDecimalValue($this->value_after_keys);
        $this->gross_sales_value = self::calculateSalesValuesTotal(
            $this->paid_sales_value,
            $this->unpaid_sales_value,
            $this->stock_sales_value,
        );
    }

    public function getFormattedRequestedAmountAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->requested_amount);
    }

    public function getFormattedLandMarketValueAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->land_market_value);
    }

    public function getFormattedIncurredCostAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->incurred_cost);
    }

    public function getFormattedCostToIncurAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->cost_to_incur);
    }

    public function getFormattedTotalCostAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->total_cost);
    }

    public function getFormattedPaidSalesValueAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->paid_sales_value);
    }

    public function getFormattedUnpaidSalesValueAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->unpaid_sales_value);
    }

    public function getFormattedStockSalesValueAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->stock_sales_value);
    }

    public function getFormattedGrossSalesValueAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->gross_sales_value);
    }

    public function getFormattedReceivedValueAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->received_value);
    }

    public function getFormattedValueUntilKeysAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->value_until_keys);
    }

    public function getFormattedValueAfterKeysAttribute(): string
    {
        return self::formatCurrencyForDisplay($this->value_after_keys);
    }

    public function getFormattedPaymentFlowTotalAttribute(): string
    {
        return self::formatCurrencyForDisplay(self::calculatePaymentFlowTotal(
            $this->received_value,
            $this->value_until_keys,
            $this->value_after_keys,
        ));
    }

    public function getFormattedSalesPercentageAttribute(): string
    {
        return number_format((float) $this->sales_percentage, 2, ',', '.').'%';
    }

    public function getFormattedWorkStagePercentageAttribute(): string
    {
        return number_format((float) $this->work_stage_percentage, 2, ',', '.').'%';
    }

    public function getLaunchMonthAttribute(): ?string
    {
        return $this->launch_date?->format('Y-m');
    }

    public function getSalesLaunchMonthAttribute(): ?string
    {
        return $this->sales_launch_date?->format('Y-m');
    }

    public function getConstructionStartMonthAttribute(): ?string
    {
        return $this->construction_start_date?->format('Y-m');
    }

    public function getDeliveryForecastMonthAttribute(): ?string
    {
        return $this->delivery_forecast_date?->format('Y-m');
    }

    public function getFormattedLaunchMonthAttribute(): string
    {
        return $this->launch_date?->format('m/Y') ?? '—';
    }

    public function getFormattedSalesLaunchMonthAttribute(): string
    {
        return $this->sales_launch_date?->format('m/Y') ?? '—';
    }

    public function getFormattedConstructionStartMonthAttribute(): string
    {
        return $this->construction_start_date?->format('m/Y') ?? '—';
    }

    public function getFormattedDeliveryForecastMonthAttribute(): string
    {
        return $this->delivery_forecast_date?->format('m/Y') ?? '—';
    }

    protected static function normalizeIntegerValue(mixed $value): int
    {
        return (int) round(self::normalizeDecimalValue($value));
    }

    public static function normalizeDecimalValue(mixed $value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        $value = trim((string) $value);

        if ($value === '') {
            return 0.0;
        }

        $value = str_replace(['R$', ' '], '', $value);

        if (str_contains($value, ',')) {
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
            return 0.0;
        }

        return round((float) $value, 2);
    }

    public static function formatCurrencyForDisplay(mixed $value): string
    {
        return number_format(self::normalizeDecimalValue($value), 2, ',', '.');
    }
}

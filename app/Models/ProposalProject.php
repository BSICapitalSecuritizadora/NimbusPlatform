<?php

namespace App\Models;

use App\Concerns\MoneyFormatter;
use App\Concerns\ProjectCalculator;
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
        return ProjectCalculator::calculateCostTotal($costIncurred, $costToIncur);
    }

    public static function calculateUnitsTotal(
        mixed $unitsUnpaid,
        mixed $unitsPaid,
        mixed $unitsExchanged,
        mixed $unitsStock,
    ): int {
        return ProjectCalculator::calculateUnitsTotal($unitsUnpaid, $unitsPaid, $unitsExchanged, $unitsStock);
    }

    public static function calculateSalesPercentage(
        mixed $unitsUnpaid,
        mixed $unitsPaid,
        mixed $unitsExchanged,
        mixed $unitsStock,
    ): float {
        return ProjectCalculator::calculateSalesPercentage($unitsUnpaid, $unitsPaid, $unitsExchanged, $unitsStock);
    }

    public static function calculateWorkStagePercentage(mixed $costIncurred, mixed $costTotal): float
    {
        return ProjectCalculator::calculateWorkStagePercentage($costIncurred, $costTotal);
    }

    public static function calculatePaymentFlowTotal(
        mixed $valueReceived,
        mixed $valueUntilKeys,
        mixed $valuePostKeys,
    ): float {
        return ProjectCalculator::calculatePaymentFlowTotal($valueReceived, $valueUntilKeys, $valuePostKeys);
    }

    public static function calculateSalesValuesTotal(
        mixed $valuePaid,
        mixed $valueUnpaid,
        mixed $valueStock,
    ): float {
        return ProjectCalculator::calculateSalesValuesTotal($valuePaid, $valueUnpaid, $valueStock);
    }

    protected function syncSalesMetrics(): void
    {
        $this->exchanged_units = MoneyFormatter::normalizeIntegerValue($this->exchanged_units);
        $this->paid_units = MoneyFormatter::normalizeIntegerValue($this->paid_units);
        $this->unpaid_units = MoneyFormatter::normalizeIntegerValue($this->unpaid_units);
        $this->stock_units = MoneyFormatter::normalizeIntegerValue($this->stock_units);
        $this->units_total = ProjectCalculator::calculateUnitsTotal(
            $this->unpaid_units,
            $this->paid_units,
            $this->exchanged_units,
            $this->stock_units,
        );
        $this->sales_percentage = ProjectCalculator::calculateSalesPercentage(
            $this->unpaid_units,
            $this->paid_units,
            $this->exchanged_units,
            $this->stock_units,
        );
    }

    protected function syncOverviewValues(): void
    {
        $this->requested_amount = MoneyFormatter::normalizeDecimalValue($this->requested_amount);
        $this->land_market_value = MoneyFormatter::normalizeDecimalValue($this->land_market_value);
        $this->land_area = MoneyFormatter::normalizeDecimalValue($this->land_area);
        $this->remaining_months = MoneyFormatter::normalizeIntegerValue($this->remaining_months);
    }

    protected function syncCostMetrics(): void
    {
        $this->incurred_cost = MoneyFormatter::normalizeDecimalValue($this->incurred_cost);
        $this->cost_to_incur = MoneyFormatter::normalizeDecimalValue($this->cost_to_incur);
        $this->total_cost = ProjectCalculator::calculateCostTotal($this->incurred_cost, $this->cost_to_incur);
        $this->work_stage_percentage = ProjectCalculator::calculateWorkStagePercentage($this->incurred_cost, $this->total_cost);
    }

    protected function syncSaleValues(): void
    {
        $this->paid_sales_value = MoneyFormatter::normalizeDecimalValue($this->paid_sales_value);
        $this->unpaid_sales_value = MoneyFormatter::normalizeDecimalValue($this->unpaid_sales_value);
        $this->stock_sales_value = MoneyFormatter::normalizeDecimalValue($this->stock_sales_value);
        $this->received_value = MoneyFormatter::normalizeDecimalValue($this->received_value);
        $this->value_until_keys = MoneyFormatter::normalizeDecimalValue($this->value_until_keys);
        $this->value_after_keys = MoneyFormatter::normalizeDecimalValue($this->value_after_keys);
        $this->gross_sales_value = ProjectCalculator::calculateSalesValuesTotal(
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
        return MoneyFormatter::normalizeIntegerValue($value);
    }

    public static function normalizeDecimalValue(mixed $value): float
    {
        return MoneyFormatter::normalizeDecimalValue($value);
    }

    public static function formatCurrencyForDisplay(mixed $value): string
    {
        return MoneyFormatter::formatCurrencyForDisplay($value);
    }
}

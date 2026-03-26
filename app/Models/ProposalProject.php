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
        'proposal_id', 'name', 'company_name', 'site',
        'value_requested', 'land_market_value', 'land_area',
        'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado',
        'launch_date',
        'sales_launch_date',
        'construction_start_date',
        'delivery_forecast_date',
        'remaining_months',
        'units_exchanged',
        'units_paid', 'units_unpaid', 'units_stock', 'units_total',
        'sales_percentage', 'cost_incurred', 'cost_to_incur', 'cost_total', 'work_stage_percentage',
        'value_paid', 'value_unpaid', 'value_stock', 'value_total_sale', 'value_received', 'value_until_keys', 'value_post_keys',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $project): void {
            $project->syncSalesMetrics();
            $project->syncSaleValues();
            $project->syncCostMetrics();
        });
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
        $this->units_exchanged = self::normalizeIntegerValue($this->units_exchanged);
        $this->units_paid = self::normalizeIntegerValue($this->units_paid);
        $this->units_unpaid = self::normalizeIntegerValue($this->units_unpaid);
        $this->units_stock = self::normalizeIntegerValue($this->units_stock);
        $this->units_total = self::calculateUnitsTotal(
            $this->units_unpaid,
            $this->units_paid,
            $this->units_exchanged,
            $this->units_stock,
        );
        $this->sales_percentage = self::calculateSalesPercentage(
            $this->units_unpaid,
            $this->units_paid,
            $this->units_exchanged,
            $this->units_stock,
        );
    }

    protected function syncCostMetrics(): void
    {
        $this->cost_incurred = self::normalizeDecimalValue($this->cost_incurred);
        $this->cost_to_incur = self::normalizeDecimalValue($this->cost_to_incur);
        $this->cost_total = self::calculateCostTotal($this->cost_incurred, $this->cost_to_incur);
        $this->work_stage_percentage = self::calculateWorkStagePercentage($this->cost_incurred, $this->cost_total);
    }

    protected function syncSaleValues(): void
    {
        $this->value_paid = self::normalizeDecimalValue($this->value_paid);
        $this->value_unpaid = self::normalizeDecimalValue($this->value_unpaid);
        $this->value_stock = self::normalizeDecimalValue($this->value_stock);
        $this->value_received = self::normalizeDecimalValue($this->value_received);
        $this->value_until_keys = self::normalizeDecimalValue($this->value_until_keys);
        $this->value_post_keys = self::normalizeDecimalValue($this->value_post_keys);
        $this->value_total_sale = self::calculateSalesValuesTotal(
            $this->value_paid,
            $this->value_unpaid,
            $this->value_stock,
        );
    }

    protected static function normalizeIntegerValue(mixed $value): int
    {
        return (int) round(self::normalizeDecimalValue($value));
    }

    protected static function normalizeDecimalValue(mixed $value): float
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
}

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

    public static function calculateWorkStagePercentage(mixed $costIncurred, mixed $costTotal): float
    {
        $costIncurred = self::normalizeDecimalValue($costIncurred);
        $costTotal = self::normalizeDecimalValue($costTotal);

        if ($costTotal <= 0) {
            return 0.0;
        }

        return round(($costIncurred / $costTotal) * 100, 2);
    }

    protected function syncCostMetrics(): void
    {
        $this->cost_incurred = self::normalizeDecimalValue($this->cost_incurred);
        $this->cost_to_incur = self::normalizeDecimalValue($this->cost_to_incur);
        $this->cost_total = self::calculateCostTotal($this->cost_incurred, $this->cost_to_incur);
        $this->work_stage_percentage = self::calculateWorkStagePercentage($this->cost_incurred, $this->cost_total);
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

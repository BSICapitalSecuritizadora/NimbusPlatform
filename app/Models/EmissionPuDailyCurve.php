<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmissionPuDailyCurve extends Model
{
    /** @use HasFactory<\Database\Factories\EmissionPuDailyCurveFactory> */
    use HasFactory;

    protected $fillable = [
        'emission_id',
        'curve_date',
        'calculation_version',
        'is_business_day',
        'unit_base_value',
        'unit_corrected_value',
        'factor_di',
        'factor_di_accumulated',
        'factor_spread',
        'factor_spread_di',
        'interest_real_unit_value',
        'updated_unit_value',
        'amortization_ratio',
        'amortization_unit_value',
        'amortization_value',
        'residual_unit_value',
        'quantity',
        'total_value',
        'interest_payment_unit_value',
        'interest_payment_value',
        'payment_total_unit_value',
        'payment_total_value',
        'dup_correction',
        'dut_correction',
        'dup_interest',
        'dut_interest',
        'index_rate_date',
        'index_rate_value',
        'event_original_date',
        'event_effective_date',
    ];

    protected function casts(): array
    {
        return [
            'curve_date' => 'date',
            'calculation_version' => 'string',
            'is_business_day' => 'boolean',
            'unit_base_value' => 'decimal:16',
            'unit_corrected_value' => 'decimal:16',
            'factor_di' => 'decimal:16',
            'factor_di_accumulated' => 'decimal:16',
            'factor_spread' => 'decimal:16',
            'factor_spread_di' => 'decimal:16',
            'interest_real_unit_value' => 'decimal:16',
            'updated_unit_value' => 'decimal:16',
            'amortization_ratio' => 'decimal:16',
            'amortization_unit_value' => 'decimal:16',
            'amortization_value' => 'decimal:16',
            'residual_unit_value' => 'decimal:16',
            'quantity' => 'decimal:4',
            'total_value' => 'decimal:16',
            'interest_payment_unit_value' => 'decimal:16',
            'interest_payment_value' => 'decimal:16',
            'payment_total_unit_value' => 'decimal:16',
            'payment_total_value' => 'decimal:16',
            'dup_correction' => 'integer',
            'dut_correction' => 'integer',
            'dup_interest' => 'integer',
            'dut_interest' => 'integer',
            'index_rate_date' => 'date',
            'index_rate_value' => 'decimal:8',
            'event_original_date' => 'date',
            'event_effective_date' => 'date',
        ];
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function scopeForCalculationVersion($query, ?string $calculationVersion)
    {
        if ($calculationVersion === null) {
            return $query;
        }

        return $query->where('calculation_version', $calculationVersion);
    }

    public static function latestCalculationVersionForEmission(int $emissionId): ?string
    {
        return static::query()
            ->where('emission_id', $emissionId)
            ->orderByDesc('id')
            ->value('calculation_version');
    }
}

<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuCalculationMethod;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use Database\Factories\EmissionPuParameterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EmissionPuParameter extends Model
{
    /** @use HasFactory<EmissionPuParameterFactory> */
    use HasFactory;

    protected $attributes = [
        'first_coupon_pre_integralization_premium_enabled' => false,
        'first_coupon_pre_integralization_apply_index_factor' => true,
        'first_coupon_pre_integralization_apply_spread_factor' => true,
    ];

    protected $fillable = [
        'emission_id',
        'curve_start_date',
        'curve_end_date',
        'initial_unit_value',
        'spread_rate',
        'annual_rate',
        'indexer',
        'calculation_method',
        'method_version',
        'rounding_policy',
        'business_day_basis',
        'calendar_code',
        'index_rate_lookup_mode',
        'index_rate_lag_business_days',
        'first_coupon_pre_integralization_premium_enabled',
        'first_coupon_pre_integralization_business_days',
        'first_coupon_pre_integralization_apply_index_factor',
        'first_coupon_pre_integralization_apply_spread_factor',
        'index_lag_months',
        'base_index_date',
        'correction_frequency',
        'index_projection_policy',
        'legacy_projection_enabled',
    ];

    protected function casts(): array
    {
        return [
            'curve_start_date' => 'date',
            'curve_end_date' => 'date',
            'initial_unit_value' => 'decimal:16',
            'spread_rate' => 'decimal:8',
            'annual_rate' => 'decimal:8',
            'business_day_basis' => 'integer',
            'index_rate_lag_business_days' => 'integer',
            'first_coupon_pre_integralization_premium_enabled' => 'boolean',
            'first_coupon_pre_integralization_business_days' => 'integer',
            'first_coupon_pre_integralization_apply_index_factor' => 'boolean',
            'first_coupon_pre_integralization_apply_spread_factor' => 'boolean',
            'index_lag_months' => 'integer',
            'base_index_date' => 'date',
            'legacy_projection_enabled' => 'boolean',
        ];
    }

    public function resolvedCalculationMethod(): PuCalculationMethod
    {
        if ($this->calculation_method !== null) {
            $method = PuCalculationMethod::tryFrom((string) $this->calculation_method);

            if ($method !== null) {
                return $method;
            }
        }

        return PuCalculationMethod::forIndexer($this->indexer_enum);
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
    }

    public function selectionEvidence(): MorphMany
    {
        return $this->morphMany(BusinessCalendarSelectionEvidence::class, 'subject');
    }

    public function hasFirstCouponPreIntegralizationPremium(): bool
    {
        return (bool) $this->first_coupon_pre_integralization_premium_enabled;
    }

    public function getIndexerEnumAttribute(): PuIndexer
    {
        return PuIndexer::from((string) $this->indexer);
    }

    public function getIndexRateLookupModeEnumAttribute(): PuIndexRateLookupMode
    {
        return PuIndexRateLookupMode::from(
            (string) ($this->index_rate_lookup_mode ?? PuIndexRateLookupMode::PreviousAvailableBusinessDay->value),
        );
    }
}

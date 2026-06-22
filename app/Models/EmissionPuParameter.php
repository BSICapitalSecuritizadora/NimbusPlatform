<?php

namespace App\Models;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmissionPuParameter extends Model
{
    /** @use HasFactory<\Database\Factories\EmissionPuParameterFactory> */
    use HasFactory;

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
            'index_lag_months' => 'integer',
            'base_index_date' => 'date',
            'legacy_projection_enabled' => 'boolean',
        ];
    }

    public function resolvedCalculationMethod(): \App\Domain\PuCalculator\Enums\PuCalculationMethod
    {
        if ($this->calculation_method !== null) {
            $method = \App\Domain\PuCalculator\Enums\PuCalculationMethod::tryFrom((string) $this->calculation_method);

            if ($method !== null) {
                return $method;
            }
        }

        return \App\Domain\PuCalculator\Enums\PuCalculationMethod::forIndexer($this->indexer_enum);
    }

    public function emission(): BelongsTo
    {
        return $this->belongsTo(Emission::class);
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

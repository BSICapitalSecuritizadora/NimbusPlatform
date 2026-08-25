<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmissionPuParameter>
 */
class EmissionPuParameterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'curve_start_date' => '2026-01-01',
            'curve_end_date' => '2026-01-31',
            'initial_unit_value' => '1000.0000000000000000',
            'spread_rate' => '6.50000000',
            'indexer' => PuIndexer::Cdi->value,
            'business_day_basis' => 252,
            'calendar_code' => 'B3',
            'index_rate_lookup_mode' => PuIndexRateLookupMode::PreviousAvailableBusinessDay->value,
            'index_rate_lag_business_days' => 1,
            'first_coupon_pre_integralization_premium_enabled' => false,
            'first_coupon_pre_integralization_business_days' => null,
            'first_coupon_pre_integralization_apply_index_factor' => true,
            'first_coupon_pre_integralization_apply_spread_factor' => true,
            'legacy_projection_enabled' => true,
        ];
    }
}

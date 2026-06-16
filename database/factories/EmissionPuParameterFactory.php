<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\Emission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmissionPuParameter>
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
            'legacy_projection_enabled' => true,
        ];
    }
}

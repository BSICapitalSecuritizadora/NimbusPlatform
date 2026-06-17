<?php

namespace Database\Factories;

use App\Models\Emission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmissionPuDailyCurve>
 */
class EmissionPuDailyCurveFactory extends Factory
{
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'curve_date' => fake()->date(),
            'calculation_version' => 'v1',
            'is_business_day' => true,
            'unit_base_value' => '1000.0000000000000000',
            'unit_corrected_value' => '1000.0000000000000000',
            'factor_di' => '1.0000000000000000',
            'factor_di_accumulated' => '1.0000000000000000',
            'factor_spread' => '1.0000000000000000',
            'factor_spread_di' => '1.0000000000000000',
            'interest_real_unit_value' => '0.0000000000000000',
            'updated_unit_value' => '1000.0000000000000000',
            'amortization_ratio' => '0.0000000000000000',
            'amortization_unit_value' => '0.0000000000000000',
            'amortization_value' => '0.0000000000000000',
            'residual_unit_value' => '1000.0000000000000000',
            'quantity' => '1000.0000',
            'total_value' => '1000000.0000000000000000',
            'interest_payment_unit_value' => '0.0000000000000000',
            'interest_payment_value' => '0.0000000000000000',
            'payment_total_unit_value' => '0.0000000000000000',
            'payment_total_value' => '0.0000000000000000',
            'dup_correction' => 0,
            'dut_correction' => 0,
            'dup_interest' => 0,
            'dut_interest' => 0,
            'index_rate_date' => fake()->date(),
            'index_rate_value' => '13.65000000',
            'event_original_date' => null,
            'event_effective_date' => null,
        ];
    }
}

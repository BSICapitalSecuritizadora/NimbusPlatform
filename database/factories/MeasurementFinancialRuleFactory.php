<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\MeasurementFinancialRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeasurementFinancialRule>
 */
class MeasurementFinancialRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'name' => 'Retenção contratual',
            'description' => 'Retenção prevista nas condições da emissão, sujeita à conferência do Finalizador.',
            'direction' => 'under',
            'maximum_difference_amount' => '10000.00',
            'maximum_difference_percent' => null,
            'requires_document' => false,
            'effective_from' => '2026-01-01',
            'effective_until' => '2026-12-31',
            'created_by' => User::factory(),
        ];
    }
}

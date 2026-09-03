<?php

namespace Database\Factories;

use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuExternalValidationRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmissionPuExternalValidationRow>
 */
class EmissionPuExternalValidationRowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_validation_id' => EmissionPuExternalValidation::factory(),
            'reference_date' => fake()->date(),
            'candidate_unit_value' => '1000.0000000000000000',
            'external_unit_value' => '1000.0000000000000000',
            'absolute_difference' => '0.0000000000000000',
            'relative_difference_percentage' => '0.0000000000000000',
            'classification' => 'reported_without_tolerance',
        ];
    }
}

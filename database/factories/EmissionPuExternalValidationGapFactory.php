<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuExternalValidationGapType;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuExternalValidationGap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmissionPuExternalValidationGap>
 */
class EmissionPuExternalValidationGapFactory extends Factory
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
            'gap_type' => PuExternalValidationGapType::CandidateWithoutReference->value,
        ];
    }
}

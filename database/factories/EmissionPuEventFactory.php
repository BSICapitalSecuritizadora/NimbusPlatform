<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Models\Emission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmissionPuEvent>
 */
class EmissionPuEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'event_type' => PuEventType::InterestPayment->value,
            'original_date' => '2026-01-31',
            'effective_date' => '2026-01-31',
            'amortization_type' => PuAmortizationType::None->value,
            'amortization_value' => null,
            'sequence' => 1,
            'description' => fake()->sentence(),
        ];
    }
}

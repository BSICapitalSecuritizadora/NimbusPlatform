<?php

namespace Database\Factories;

use App\Enums\GuaranteeValueSource;
use App\Enums\GuaranteeValueStatus;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeMonthlyPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuaranteeMonthlyPosition>
 */
class GuaranteeMonthlyPositionFactory extends Factory
{
    protected $model = GuaranteeMonthlyPosition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guarantee_id' => Guarantee::factory(),
            'emission_id' => Emission::factory(),
            'reference_month' => now()->startOfMonth()->toDateString(),
            'current_value' => fake()->randomFloat(2, 100000, 10000000),
            'value_source' => GuaranteeValueSource::Manual,
            'value_status' => GuaranteeValueStatus::Manual,
            'computed_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_value' => null,
            'eligible_value' => null,
            'value_status' => GuaranteeValueStatus::Pending,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\GuaranteeSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuaranteeSnapshot>
 */
class GuaranteeSnapshotFactory extends Factory
{
    protected $model = GuaranteeSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'reference_month' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-01'),
            'quota_value' => fake()->randomFloat(2, 50000, 2000000),
            'outstanding_balance' => fake()->randomFloat(2, 50000, 2000000),
        ];
    }
}

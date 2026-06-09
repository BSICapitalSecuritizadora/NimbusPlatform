<?php

namespace Database\Factories;

use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeasurementPlanSet>
 */
class MeasurementPlanSetFactory extends Factory
{
    protected $model = MeasurementPlanSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_id' => Operation::factory(),
            'construction_id' => null,
            'name' => 'Plano '.fake()->unique()->numberBetween(1, 100000),
            'is_default' => false,
            'construction_fund_amount' => fake()->randomFloat(2, 50000, 10000000),
            'initial_incurred_amount' => fake()->randomFloat(2, 0, 1000000),
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
            'name' => 'Plano padrão',
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operation>
 */
class OperationFactory extends Factory
{
    protected $model = Operation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'construction_id' => null,
            'title' => fake()->company().' - Obra',
            'status' => fake()->randomElement(array_keys(Operation::STATUS_OPTIONS)),
            'issuer' => fake()->company(),
            'amount' => fake()->randomFloat(2, 100000, 50000000),
            'construction_fund_amount' => fake()->randomFloat(2, 50000, 10000000),
            'due_date' => fake()->dateTimeBetween('+1 month', '+3 years')->format('Y-m-d'),
            'next_measurement_at' => fake()->dateTimeBetween('now', '+2 months')->format('Y-m-d'),
        ];
    }

    public function forEmission(Emission $emission): static
    {
        return $this->state(fn (): array => [
            'emission_id' => $emission->id,
        ]);
    }
}

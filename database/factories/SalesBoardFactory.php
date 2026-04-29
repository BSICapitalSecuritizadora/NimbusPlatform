<?php

namespace Database\Factories;

use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoard>
 */
class SalesBoardFactory extends Factory
{
    protected $model = SalesBoard::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'construction_id' => Construction::factory(),
            'reference_month' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-01'),
            'stock_units' => fake()->numberBetween(0, 50),
            'financed_units' => fake()->numberBetween(0, 50),
            'paid_units' => fake()->numberBetween(0, 50),
            'exchanged_units' => fake()->numberBetween(0, 50),
            'stock_value' => fake()->randomFloat(2, 0, 10000000),
            'financed_value' => fake()->randomFloat(2, 0, 10000000),
            'paid_value' => fake()->randomFloat(2, 0, 10000000),
            'exchanged_value' => fake()->randomFloat(2, 0, 10000000),
        ];
    }

    public function forEmissionAndConstruction(Emission $emission, Construction $construction): static
    {
        return $this->state(fn (): array => [
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
        ]);
    }
}

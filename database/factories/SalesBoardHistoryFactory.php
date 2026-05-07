<?php

namespace Database\Factories;

use App\Models\SalesBoard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SalesBoardHistory>
 */
class SalesBoardHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stockUnits = fake()->numberBetween(0, 50);
        $financedUnits = fake()->numberBetween(0, 50);
        $paidUnits = fake()->numberBetween(0, 50);
        $exchangedUnits = fake()->numberBetween(0, 50);

        return [
            'sales_board_id' => SalesBoard::factory(),
            'reference_month' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-01'),
            'stock_units' => $stockUnits,
            'financed_units' => $financedUnits,
            'paid_units' => $paidUnits,
            'exchanged_units' => $exchangedUnits,
            'total_units' => $stockUnits + $financedUnits + $paidUnits + $exchangedUnits,
            'stock_value' => fake()->randomFloat(2, 0, 10000000),
            'financed_value' => fake()->randomFloat(2, 0, 10000000),
            'paid_value' => fake()->randomFloat(2, 0, 10000000),
            'exchanged_value' => fake()->randomFloat(2, 0, 10000000),
        ];
    }
}

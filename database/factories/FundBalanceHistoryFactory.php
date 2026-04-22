<?php

namespace Database\Factories;

use App\Models\Fund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FundBalanceHistory>
 */
class FundBalanceHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fund_id' => Fund::factory(),
            'date' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-d'),
            'balance' => fake()->randomFloat(2, 1000, 500000),
        ];
    }
}

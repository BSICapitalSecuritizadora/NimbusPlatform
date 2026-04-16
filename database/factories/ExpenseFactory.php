<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\Expense;
use App\Models\ExpenseServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'expense_service_provider_id' => ExpenseServiceProvider::factory(),
            'category' => fake()->randomElement(array_keys(Expense::CATEGORY_OPTIONS)),
            'period' => Expense::PERIOD_SINGLE,
            'start_date' => fake()->date(),
            'end_date' => null,
        ];
    }
}

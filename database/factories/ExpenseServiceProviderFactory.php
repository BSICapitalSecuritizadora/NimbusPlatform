<?php

namespace Database\Factories;

use App\Models\ExpenseServiceProvider;
use App\Models\ExpenseServiceProviderType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseServiceProvider>
 */
class ExpenseServiceProviderFactory extends Factory
{
    protected $model = ExpenseServiceProvider::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cnpj' => fake()->unique()->numerify('##############'),
            'name' => fake()->company(),
            'expense_service_provider_type_id' => ExpenseServiceProviderType::factory(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ExpenseServiceProvider;
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
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ExpenseServiceProviderType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExpenseServiceProviderType>
 */
class ExpenseServiceProviderTypeFactory extends Factory
{
    protected $model = ExpenseServiceProviderType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}

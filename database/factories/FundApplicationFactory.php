<?php

namespace Database\Factories;

use App\Models\FundApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundApplication>
 */
class FundApplicationFactory extends Factory
{
    protected $model = FundApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Aplicação '.fake()->unique()->company(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Emission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Emission>
 */
class EmissionFactory extends Factory
{
    protected $model = Emission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' - Série '.fake()->numberBetween(1, 100),
            'type' => fake()->randomElement(['CRI', 'CRA', 'CR']),
            'if_code' => fake()->bothify('??######'),
            'isin_code' => fake()->bothify('BR??????????'),
            'status' => fake()->randomElement(['Ativo', 'Encerrado']),
            'issuer' => fake()->company(),
            'fiduciary_regime' => fake()->randomElement(['Sim', 'Não']),
            'issue_date' => fake()->date(),
            'maturity_date' => fake()->dateTimeBetween('+1 year', '+10 years')->format('Y-m-d'),
            'series' => (string) fake()->numberBetween(1, 10),
            'emission_number' => (string) fake()->numberBetween(1, 5),
            'issued_quantity' => fake()->numberBetween(100, 100000),
            'issued_price' => fake()->randomFloat(2, 100, 10000),
            'issued_volume' => fake()->randomFloat(2, 10000, 100000000),
            'is_public' => false,
        ];
    }
}

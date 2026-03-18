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
            'name' => fake()->company().' - Serie '.fake()->numberBetween(1, 100),
            'type' => fake()->randomElement(array_keys(Emission::TYPE_OPTIONS)),
            'if_code' => fake()->bothify('??######'),
            'isin_code' => fake()->bothify('BR??????????'),
            'status' => fake()->randomElement(array_keys(Emission::STATUS_OPTIONS)),
            'issuer' => fake()->company(),
            'fiduciary_regime' => fake()->sentence(2),
            'issue_date' => fake()->date(),
            'maturity_date' => fake()->dateTimeBetween('+1 year', '+10 years')->format('Y-m-d'),
            'monetary_update_period' => fake()->randomElement(['Mensal', 'Trimestral', 'Semestral']),
            'series' => (string) fake()->numberBetween(1, 10),
            'emission_number' => (string) fake()->numberBetween(1, 5),
            'issued_quantity' => fake()->numberBetween(100, 100000),
            'monetary_update_months' => (string) fake()->numberBetween(1, 12),
            'interest_payment_frequency' => fake()->randomElement(['Mensal', 'Trimestral', 'Semestral']),
            'offer_type' => fake()->randomElement(['476', '400', 'Publica']),
            'concentration' => fake()->randomElement(['Pulverizada', 'Concentrada']),
            'issued_price' => fake()->randomFloat(2, 100, 10000),
            'amortization_frequency' => fake()->randomElement(['Mensal', 'Trimestral', 'Semestral', 'Bullet']),
            'integralized_quantity' => fake()->numberBetween(100, 100000),
            'trustee_agent' => fake()->company(),
            'debtor' => fake()->company(),
            'remuneration' => fake()->randomElement(['CDI + 2,00% a.a.', 'IPCA + 7,50% a.a.']),
            'prepayment_possibility' => fake()->boolean(),
            'segment' => fake()->randomElement(['Real Estate', 'Agro', 'Credito']),
            'issued_volume' => fake()->randomFloat(2, 10000, 100000000),
            'is_public' => false,
            'description' => fake()->paragraph(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }
}

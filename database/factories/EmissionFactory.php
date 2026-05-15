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

    private const REMUNERATION_SAMPLES = [
        ['indexer' => 'CDI', 'rate' => 2.00],
        ['indexer' => 'IPCA', 'rate' => 7.50],
        ['indexer' => 'Prefixado', 'rate' => 11.25],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $remunerationSample = fake()->randomElement(self::REMUNERATION_SAMPLES);

        return [
            'name' => fake()->company().' - Serie '.fake()->numberBetween(1, 100),
            'type' => fake()->randomElement(array_keys(Emission::TYPE_OPTIONS)),
            'if_code' => fake()->bothify('??######'),
            'isin_code' => fake()->bothify('BR??????????'),
            'status' => fake()->randomElement(array_keys(Emission::STATUS_OPTIONS)),
            'issuer_situation' => fake()->randomElement(array_keys(Emission::ISSUER_SITUATION_OPTIONS)),
            'issuer' => fake()->company(),
            'settlement_bank' => fake()->company(),
            'registrar' => fake()->company(),
            'fiduciary_regime' => fake()->randomElement(['Sim', 'Não']),
            'issue_date' => fake()->date(),
            'maturity_date' => fake()->dateTimeBetween('+1 year', '+10 years')->format('Y-m-d'),
            'monetary_update_period' => fake()->randomElement(['Mensal', 'Anual']),
            'series' => (string) fake()->numberBetween(1, 10),
            'emission_number' => (string) fake()->numberBetween(1, 5),
            'issued_quantity' => fake()->numberBetween(100, 100000),
            'monetary_update_months' => (string) fake()->numberBetween(1, 12),
            'interest_payment_frequency' => fake()->randomElement(['Mensal', 'Anual']),
            'offer_type' => 'CVM 160',
            'concentration' => fake()->randomElement(['Concentrado', 'Pulverizado']),
            'issued_price' => fake()->randomFloat(2, 100, 10000),
            'amortization_frequency' => fake()->randomElement(['Mensal', 'Anual', 'Bullet']),
            'integralized_quantity' => fake()->numberBetween(100, 100000),
            'trustee_agent' => fake()->company(),
            'debtor' => fake()->company(),
            'law_firm' => fake()->company(),
            'remuneration_indexer' => $remunerationSample['indexer'],
            'remuneration_rate' => $remunerationSample['rate'],
            'prepayment_possibility' => fake()->boolean(),
            'registered_with_cvm' => fake()->randomElement(['Sim', 'Não']),
            'form_type' => fake()->randomElement(array_keys(Emission::FORM_OPTIONS)),
            'segment' => fake()->randomElement(['Real Estate', 'Agro', 'Credito']),
            'issued_volume' => fake()->randomFloat(2, 10000, 100000000),
            'corporate_purpose' => fake()->paragraph(),
            'subscription_and_integralization_terms' => fake()->paragraph(),
            'amortization_payment_schedule' => fake()->paragraph(),
            'remuneration_payment_schedule' => fake()->paragraph(),
            'use_of_proceeds' => fake()->paragraph(),
            'repactuation' => fake()->paragraph(),
            'optional_early_redemption' => fake()->paragraph(),
            'early_amortization' => fake()->paragraph(),
            'remuneration_calculation' => fake()->paragraph(),
            'guarantee_fund' => fake()->randomElement(['Sim', 'Não']),
            'expense_fund' => fake()->randomElement(['Sim', 'Não']),
            'reserve_fund' => fake()->randomElement(['Sim', 'Não']),
            'works_fund' => fake()->randomElement(['Sim', 'Não']),
            'property_description' => fake()->paragraph(),
            'segregated_estate' => fake()->paragraph(),
            'guarantees_description' => fake()->paragraph(),
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

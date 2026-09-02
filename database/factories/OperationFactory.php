<?php

namespace Database\Factories;

use App\Enums\OperationStatus;
use App\Models\Emission;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operation>
 */
class OperationFactory extends Factory
{
    protected $model = Operation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'construction_id' => null,
            'title' => fake()->company().' - Obra',
            // A operação de teste nasce operacional porque é esse o estado em que
            // quase todo cenário do módulo de medições começa; quem precisa de
            // rascunho ou de um terminal declara o estado explicitamente.
            'status' => OperationStatus::Active,
            'issuer' => fake()->company(),
            'amount' => fake()->randomFloat(2, 100000, 50000000),
            'construction_fund_amount' => fake()->randomFloat(2, 50000, 10000000),
            'due_date' => fake()->dateTimeBetween('+1 month', '+3 years')->format('Y-m-d'),
            'next_measurement_at' => fake()->dateTimeBetween('now', '+2 months')->format('Y-m-d'),
        ];
    }

    public function forEmission(Emission $emission): static
    {
        return $this->state(fn (): array => [
            'emission_id' => $emission->id,
        ]);
    }
}

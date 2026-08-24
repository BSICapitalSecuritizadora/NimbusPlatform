<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Models\Client;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'construction_unit_id' => ConstructionUnit::factory(),
            'code' => 'CVC-'.fake()->unique()->numberBetween(10000, 99999),
            'sale_date' => fake()->dateTimeBetween('-4 years', '-1 month')->format('Y-m-d'),
            'sale_value' => fake()->randomFloat(2, 150000, 1500000),
            'status' => ContractStatus::Active,
            'cancellation_date' => null,
        ];
    }

    public function forUnit(ConstructionUnit $unit): static
    {
        return $this->state(fn (): array => [
            'construction_unit_id' => $unit->id,
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn (): array => [
            'client_id' => $client->id,
        ]);
    }

    public function settled(): static
    {
        return $this->state(fn (): array => [
            'status' => ContractStatus::Settled,
        ]);
    }

    public function exchanged(): static
    {
        return $this->state(fn (): array => [
            'status' => ContractStatus::Exchanged,
        ]);
    }

    /**
     * A distratado contract releases the unit, which is what makes room for the
     * resale in the tests.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ContractStatus::Cancelled,
            'cancellation_date' => fake()->dateTimeBetween($attributes['sale_date'] ?? '-4 years', 'now')->format('Y-m-d'),
        ]);
    }
}

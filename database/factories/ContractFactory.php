<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Models\Client;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * A contract needs at least one buyer, and buyers live in
     * `contract_clients`. A bare `Contract::factory()` therefore gets one, so it
     * still produces a valid contract.
     *
     * This runs after the buyers a state named, never before: {@see forClient()}
     * and {@see withBuyers()} attach through `hasAttached()`, which Laravel
     * resolves while storing the model -- ahead of every `afterCreating`. So the
     * check below sees them and steps aside, and no throwaway client is ever
     * created for a fixture that already said who bought.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Contract $contract): void {
            if ($contract->clients()->exists()) {
                return;
            }

            $contract->clients()->attach(Client::factory()->create());
        });
    }

    /**
     * The single buyer of the contract -- the common case, and the shorthand the
     * suite already reads everywhere.
     */
    public function forClient(Client $client): static
    {
        return $this->hasAttached($client, [], 'clients');
    }

    /**
     * Two or more buyers on one contract.
     */
    public function withBuyers(Client ...$clients): static
    {
        return $this->hasAttached(new Collection($clients), [], 'clients');
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

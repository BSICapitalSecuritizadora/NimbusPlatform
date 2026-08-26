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
            /**
             * Carrier, not a buyer. `contracts.client_id` is legacy and nothing
             * in the application reads it any more, so the factory uses it only
             * to carry the intended buyer from the state into `configure()`,
             * which moves it to the buyer table and clears the column. A
             * contract built here therefore comes out exactly like one the
             * application creates: `client_id` NULL, buyers in the pivot.
             */
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

    /**
     * Moves the carrier column into the buyer table and clears it, so every
     * contract this factory builds ends up in the plural shape.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Contract $contract): void {
            if ($contract->client_id === null) {
                return;
            }

            $contract->clients()->syncWithoutDetaching([$contract->client_id]);
            $contract->forceFill(['client_id' => null])->saveQuietly();
        });
    }

    /**
     * The single buyer of the contract -- the common case, and the shorthand the
     * suite already reads everywhere.
     */
    public function forClient(Client $client): static
    {
        return $this->state(fn (): array => [
            'client_id' => $client->getKey(),
        ]);
    }

    /**
     * Two or more buyers on one contract.
     *
     * Bypasses the carrier entirely: the column can hold one id and this is the
     * state that exists because a contract can have more.
     */
    public function withBuyers(Client ...$clients): static
    {
        return $this
            ->state(fn (): array => ['client_id' => null])
            ->afterCreating(function (Contract $contract) use ($clients): void {
                $contract->clients()->syncWithoutDetaching(
                    collect($clients)->map(fn (Client $client): int => (int) $client->getKey())->all(),
                );
            });
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

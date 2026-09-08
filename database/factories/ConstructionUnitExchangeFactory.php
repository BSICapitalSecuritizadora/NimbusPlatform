<?php

namespace Database\Factories;

use App\Enums\ConstructionUnitExchangeKind;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConstructionUnitExchange>
 */
class ConstructionUnitExchangeFactory extends Factory
{
    protected $model = ConstructionUnitExchange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'construction_unit_id' => ConstructionUnit::factory(),
            'contract_id' => null,
            'exchange_value' => fake()->randomFloat(2, 100000, 2000000),
            'effective_from' => '2026-01-01',
            'ended_on' => null,
            'kind' => ConstructionUnitExchangeKind::Baseline,
            'reason' => 'Permuta declarada na estruturação da operação.',
            'created_by_id' => null,
        ];
    }

    public function forUnit(ConstructionUnit $constructionUnit): static
    {
        return $this->state(fn (): array => ['construction_unit_id' => $constructionUnit->getKey()]);
    }

    public function forContract(Contract $contract): static
    {
        return $this->state(fn (): array => ['contract_id' => $contract->getKey()]);
    }

    public function worth(float|int|string $value): static
    {
        return $this->state(fn (): array => ['exchange_value' => $value]);
    }

    public function effectiveFrom(string $date): static
    {
        return $this->state(fn (): array => ['effective_from' => $date]);
    }

    public function endedOn(?string $date): static
    {
        return $this->state(fn (): array => ['ended_on' => $date]);
    }

    public function extraordinary(): static
    {
        return $this->state(fn (): array => ['kind' => ConstructionUnitExchangeKind::Extraordinary]);
    }

    public function registeredBy(User $user): static
    {
        return $this->state(fn (): array => ['created_by_id' => $user->getKey()]);
    }
}

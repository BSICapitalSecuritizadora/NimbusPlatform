<?php

namespace Database\Factories;

use App\Enums\UnitValueSource;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConstructionUnitValue>
 */
class ConstructionUnitValueFactory extends Factory
{
    protected $model = ConstructionUnitValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'construction_unit_id' => ConstructionUnit::factory(),
            'value' => fake()->randomFloat(2, 100000, 3000000),
            'effective_from' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'source' => UnitValueSource::Manual,
            'reason' => 'Atualização de tabela comercial.',
            'created_by_id' => null,
        ];
    }

    public function forUnit(ConstructionUnit $constructionUnit): static
    {
        return $this->state(fn (): array => ['construction_unit_id' => $constructionUnit->getKey()]);
    }

    public function effectiveFrom(string $date): static
    {
        return $this->state(fn (): array => ['effective_from' => $date]);
    }

    public function worth(float|int|string $value): static
    {
        return $this->state(fn (): array => ['value' => $value]);
    }

    public function fromSpreadsheet(): static
    {
        return $this->state(fn (): array => ['source' => UnitValueSource::SpreadsheetImport]);
    }

    public function registeredBy(User $user): static
    {
        return $this->state(fn (): array => ['created_by_id' => $user->getKey()]);
    }
}

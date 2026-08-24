<?php

namespace Database\Factories;

use App\Models\Construction;
use App\Models\ConstructionUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConstructionUnit>
 */
class ConstructionUnitFactory extends Factory
{
    protected $model = ConstructionUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'construction_id' => Construction::factory(),
            'block' => str_pad((string) fake()->numberBetween(1, 9), 2, '0', STR_PAD_LEFT),
            'unit' => (string) fake()->unique()->numberBetween(101, 1999),
        ];
    }

    public function forConstruction(Construction $construction): static
    {
        return $this->state(fn (): array => [
            'construction_id' => $construction->id,
        ]);
    }
}

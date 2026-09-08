<?php

namespace Database\Factories;

use App\Enums\SalesBoardCycleStatus;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoardCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardCycle>
 */
class SalesBoardCycleFactory extends Factory
{
    protected $model = SalesBoardCycle::class;

    public function definition(): array
    {
        $month = CarbonImmutable::parse('2026-07-01');

        return [
            'emission_id' => Emission::factory(),
            'construction_id' => Construction::factory(),
            'reference_month' => $month->toDateString(),
            'position_date' => $month->endOfMonth()->toDateString(),
            'status' => SalesBoardCycleStatus::Generated,
            'created_by_id' => null,
        ];
    }

    public function forConstruction(Construction $construction): self
    {
        return $this->state(fn (): array => [
            'construction_id' => $construction->getKey(),
            'emission_id' => $construction->emission_id,
        ]);
    }

    public function referenceMonth(string $month): self
    {
        $month = CarbonImmutable::parse($month)->startOfMonth();

        return $this->state(fn (): array => [
            'reference_month' => $month->toDateString(),
            'position_date' => $month->endOfMonth()->toDateString(),
        ]);
    }
}

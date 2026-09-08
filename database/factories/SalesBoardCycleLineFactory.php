<?php

namespace Database\Factories;

use App\Enums\SalesBoardUnitClassification;
use App\Models\ConstructionUnit;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardCycleLine>
 */
class SalesBoardCycleLineFactory extends Factory
{
    protected $model = SalesBoardCycleLine::class;

    public function definition(): array
    {
        return [
            'sales_board_cycle_baseline_id' => SalesBoardCycleBaseline::factory(),
            'construction_unit_id' => ConstructionUnit::factory(),
            'block' => '01',
            'unit' => (string) fake()->numberBetween(101, 999),
            'classification' => SalesBoardUnitClassification::Stock,
            'unit_reference_value' => '500000.00',
            'source_fingerprint' => str_repeat('c', 64),
            'snapshot_fingerprint' => str_repeat('d', 64),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\SalesBoardMovementType;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardCycleMovement>
 */
class SalesBoardCycleMovementFactory extends Factory
{
    protected $model = SalesBoardCycleMovement::class;

    public function definition(): array
    {
        return [
            'sales_board_cycle_baseline_id' => SalesBoardCycleBaseline::factory(),
            'movement_type' => SalesBoardMovementType::Sale,
            'construction_unit_id' => ConstructionUnit::factory(),
            'block' => '01',
            'unit' => (string) fake()->numberBetween(101, 999),
            'contract_id' => Contract::factory(),
            'contract_code' => 'CT-'.fake()->numberBetween(1, 9999),
            'event_date' => '2026-07-05',
            'sale_date' => '2026-07-05',
            'sale_value' => '600000.00',
            'source_fingerprint' => str_repeat('e', 64),
            'snapshot_fingerprint' => str_repeat('f', 64),
        ];
    }
}

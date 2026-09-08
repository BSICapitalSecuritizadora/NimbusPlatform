<?php

namespace Database\Factories;

use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardCycleBaseline>
 */
class SalesBoardCycleBaselineFactory extends Factory
{
    protected $model = SalesBoardCycleBaseline::class;

    public function definition(): array
    {
        return [
            'sales_board_cycle_id' => SalesBoardCycle::factory(),
            'version' => 1,
            'units_total' => 0,
            'stock_units' => 0,
            'stock_value' => '0.00',
            'financed_units' => 0,
            'financed_value' => '0.00',
            'settled_units' => 0,
            'settled_value' => '0.00',
            'exchanged_units' => 0,
            'exchanged_value' => '0.00',
            'undetermined_units' => 0,
            'is_complete' => true,
            'source_fingerprint' => str_repeat('a', 64),
            'snapshot_fingerprint' => str_repeat('b', 64),
            'computed_at' => now(),
            'computed_by_id' => null,
            'reason' => null,
            'is_stale' => false,
            'stale_impact' => SalesBoardStaleImpact::None,
            'stale_detected_at' => null,
            'last_checked_at' => now(),
            'last_observed_source_fingerprint' => str_repeat('a', 64),
            'last_observed_snapshot_fingerprint' => str_repeat('b', 64),
        ];
    }
}

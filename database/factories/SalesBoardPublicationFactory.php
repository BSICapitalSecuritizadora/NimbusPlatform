<?php

namespace Database\Factories;

use App\Models\SalesBoard;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardPublication>
 */
class SalesBoardPublicationFactory extends Factory
{
    protected $model = SalesBoardPublication::class;

    public function definition(): array
    {
        return [
            'sales_board_cycle_id' => SalesBoardCycle::factory(),
            'sales_board_cycle_baseline_id' => SalesBoardCycleBaseline::factory(),
            'sales_board_builder_review_id' => SalesBoardBuilderReview::factory(),
            'sales_board_management_review_id' => SalesBoardManagementReview::factory(),
            'sales_board_id' => SalesBoard::factory(),
            'snapshot_fingerprint' => str_repeat('c', 64),
            'source_fingerprint' => str_repeat('d', 64),
            'observed_source_fingerprint' => str_repeat('d', 64),
            'source_changed' => false,
            'published_at' => now(),
        ];
    }
}

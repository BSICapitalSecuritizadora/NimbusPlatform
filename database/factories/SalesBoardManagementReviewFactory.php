<?php

namespace Database\Factories;

use App\Enums\SalesBoardManagementReviewStatus;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardManagementReview>
 */
class SalesBoardManagementReviewFactory extends Factory
{
    protected $model = SalesBoardManagementReview::class;

    public function definition(): array
    {
        return [
            'sales_board_cycle_id' => SalesBoardCycle::factory(),
            'sales_board_cycle_baseline_id' => SalesBoardCycleBaseline::factory(),
            'sales_board_builder_review_id' => SalesBoardBuilderReview::factory(),
            'attempt' => 1,
            'status' => SalesBoardManagementReviewStatus::Draft,
            'snapshot_fingerprint' => str_repeat('c', 64),
            'opened_at' => now(),
            'source_changed' => false,
        ];
    }

    public function forBuilderReview(SalesBoardBuilderReview $builderReview): self
    {
        return $this->state(fn (): array => [
            'sales_board_cycle_id' => $builderReview->sales_board_cycle_id,
            'sales_board_cycle_baseline_id' => $builderReview->sales_board_cycle_baseline_id,
            'sales_board_builder_review_id' => $builderReview->getKey(),
            'snapshot_fingerprint' => $builderReview->snapshot_fingerprint,
        ]);
    }
}

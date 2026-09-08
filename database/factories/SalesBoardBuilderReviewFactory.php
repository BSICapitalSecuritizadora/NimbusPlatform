<?php

namespace Database\Factories;

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardBuilderReview>
 */
class SalesBoardBuilderReviewFactory extends Factory
{
    protected $model = SalesBoardBuilderReview::class;

    public function definition(): array
    {
        return [
            'sales_board_cycle_id' => SalesBoardCycle::factory(),
            'sales_board_cycle_baseline_id' => SalesBoardCycleBaseline::factory(),
            'attempt' => 1,
            'status' => SalesBoardBuilderReviewStatus::Draft,
            'snapshot_fingerprint' => str_repeat('b', 64),
            'opened_at' => now(),
        ];
    }

    public function forCycle(SalesBoardCycle $cycle): self
    {
        return $this->state(fn (): array => [
            'sales_board_cycle_id' => $cycle->getKey(),
            'sales_board_cycle_baseline_id' => $cycle->current_baseline_id,
            'snapshot_fingerprint' => $cycle->currentBaseline?->snapshot_fingerprint ?? str_repeat('b', 64),
        ]);
    }
}

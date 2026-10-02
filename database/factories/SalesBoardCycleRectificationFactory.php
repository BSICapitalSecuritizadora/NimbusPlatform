<?php

namespace Database\Factories;

use App\Enums\SalesBoardRectificationStatus;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardCycleRectification>
 */
class SalesBoardCycleRectificationFactory extends Factory
{
    protected $model = SalesBoardCycleRectification::class;

    public function definition(): array
    {
        return [
            'sales_board_cycle_id' => SalesBoardCycle::factory(),
            'sequence_number' => 1,
            'status' => SalesBoardRectificationStatus::Open,
            'rectified_publication_id' => SalesBoardPublication::factory(),
            'opening_baseline_id' => SalesBoardCycleBaseline::factory(),
            'reason' => 'Contrato da unidade 101 lançado com o valor errado.',
            'requested_by_user_id' => null,
            'requested_at' => now(),
        ];
    }

    public function open(): self
    {
        return $this->state(fn (): array => [
            'status' => SalesBoardRectificationStatus::Open,
            'closed_at' => null,
            'closed_by_user_id' => null,
            'closing_reason' => null,
        ]);
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => SalesBoardRectificationStatus::Published,
            'closed_at' => now(),
        ]);
    }

    public function abandoned(): self
    {
        return $this->state(fn (): array => [
            'status' => SalesBoardRectificationStatus::Abandoned,
            'closed_at' => now(),
            'closing_reason' => 'A diferença foi resolvida na competência seguinte.',
        ]);
    }
}

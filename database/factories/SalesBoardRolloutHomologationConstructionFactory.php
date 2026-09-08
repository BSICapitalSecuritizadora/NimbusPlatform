<?php

namespace Database\Factories;

use App\Enums\SalesBoardRolloutComparisonStatus;
use App\Models\Construction;
use App\Models\SalesBoardRolloutHomologation;
use App\Models\SalesBoardRolloutHomologationConstruction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardRolloutHomologationConstruction>
 */
class SalesBoardRolloutHomologationConstructionFactory extends Factory
{
    protected $model = SalesBoardRolloutHomologationConstruction::class;

    public function definition(): array
    {
        return [
            'sales_board_rollout_homologation_id' => SalesBoardRolloutHomologation::factory(),
            'construction_id' => Construction::factory(),
            'is_ready' => true,
            'comparison_status' => SalesBoardRolloutComparisonStatus::Matched,
            'accepted_difference' => false,
        ];
    }
}

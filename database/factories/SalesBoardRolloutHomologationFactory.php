<?php

namespace Database\Factories;

use App\Enums\SalesBoardRolloutHomologationStatus;
use App\Models\Emission;
use App\Models\SalesBoardRolloutHomologation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardRolloutHomologation>
 */
class SalesBoardRolloutHomologationFactory extends Factory
{
    protected $model = SalesBoardRolloutHomologation::class;

    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'attempt' => 1,
            'status' => SalesBoardRolloutHomologationStatus::Draft,
            'proposed_start_reference_month' => '2026-08-01',
            'comparison_reference_month' => '2026-07-01',
            'auto_open_builder_review' => false,
        ];
    }
}

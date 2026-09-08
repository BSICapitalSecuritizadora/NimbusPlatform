<?php

namespace Database\Factories;

use App\Enums\SalesBoardAutomationTargetStatus;
use App\Models\Construction;
use App\Models\SalesBoardAutomationTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardAutomationTarget>
 */
class SalesBoardAutomationTargetFactory extends Factory
{
    protected $model = SalesBoardAutomationTarget::class;

    public function definition(): array
    {
        return [
            'construction_id' => Construction::factory(),
            'reference_month' => '2026-08-01',
            'due_date' => '2026-09-13',
            'status' => SalesBoardAutomationTargetStatus::Pending,
            'attempt_count' => 0,
            'auto_open_builder_review' => false,
        ];
    }
}

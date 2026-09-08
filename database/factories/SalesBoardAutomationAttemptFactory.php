<?php

namespace Database\Factories;

use App\Enums\SalesBoardAutomationAttemptOutcome;
use App\Models\SalesBoardAutomationAttempt;
use App\Models\SalesBoardAutomationRun;
use App\Models\SalesBoardAutomationTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardAutomationAttempt>
 */
class SalesBoardAutomationAttemptFactory extends Factory
{
    protected $model = SalesBoardAutomationAttempt::class;

    public function definition(): array
    {
        return [
            'sales_board_automation_run_id' => SalesBoardAutomationRun::factory(),
            'sales_board_automation_target_id' => SalesBoardAutomationTarget::factory(),
            'attempt_number' => 1,
            'outcome' => SalesBoardAutomationAttemptOutcome::Generated,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\SalesBoardAutomationRunStatus;
use App\Enums\SalesBoardAutomationRunTrigger;
use App\Models\SalesBoardAutomationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardAutomationRun>
 */
class SalesBoardAutomationRunFactory extends Factory
{
    protected $model = SalesBoardAutomationRun::class;

    public function definition(): array
    {
        return [
            'trigger' => SalesBoardAutomationRunTrigger::Scheduled,
            'status' => SalesBoardAutomationRunStatus::Completed,
            'as_of_date' => '2026-09-13',
            'latest_due_reference_month' => '2026-08-01',
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}

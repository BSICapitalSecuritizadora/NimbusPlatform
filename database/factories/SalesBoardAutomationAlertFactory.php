<?php

namespace Database\Factories;

use App\Enums\SalesBoardAutomationAlertType;
use App\Models\SalesBoardAutomationAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesBoardAutomationAlert>
 */
class SalesBoardAutomationAlertFactory extends Factory
{
    protected $model = SalesBoardAutomationAlert::class;

    public function definition(): array
    {
        return [
            'alert_type' => SalesBoardAutomationAlertType::GenerationBlocked,
            'dedupe_key' => hash('sha256', (string) fake()->unique()->numberBetween(1, 1_000_000)),
            'sent_at' => now(),
        ];
    }
}

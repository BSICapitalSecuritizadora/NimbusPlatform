<?php

namespace Database\Factories;

use App\Models\SlaConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaConfiguration>
 */
class SlaConfigurationFactory extends Factory
{
    protected $model = SlaConfiguration::class;

    public function definition(): array
    {
        return [
            'stage' => fake()->numberBetween(1, 5),
            'duration_value' => fake()->numberBetween(2, 10),
            'duration_unit' => 'days',
            'warning_threshold_percent' => 75,
            'escalation_threshold_percent' => 100,
            'exclude_weekends' => true,
            'exclude_holidays' => true,
            'exclude_paused_time' => true,
            'is_active' => true,
        ];
    }

    public function forStage(int $stage, int $days = 5): static
    {
        return $this->state(fn (): array => [
            'stage' => $stage,
            'duration_value' => $days,
            'duration_unit' => 'days',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

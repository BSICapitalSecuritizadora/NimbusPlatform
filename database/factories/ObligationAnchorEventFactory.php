<?php

namespace Database\Factories;

use App\Models\ObligationAnchorEvent;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationAnchorEvent>
 */
class ObligationAnchorEventFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (ObligationAnchorEvent $anchorEvent): void {
            if ($anchorEvent->obligation_series_rule_id !== null) {
                return;
            }

            $series = ObligationSeries::query()->findOrFail($anchorEvent->obligation_series_id);
            $anchorEvent->obligation_series_rule_id = ObligationSeriesRule::factory()
                ->for($series, 'series')
                ->create()
                ->id;
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'obligation_series_id' => ObligationSeries::factory(),
            'obligation_series_rule_id' => null,
            'event_name' => 'Recebimento da solicitação',
            'occurred_on' => now()->toDateString(),
            'notes' => null,
            'recorded_by' => null,
        ];
    }
}

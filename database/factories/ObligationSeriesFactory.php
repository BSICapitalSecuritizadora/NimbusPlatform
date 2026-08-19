<?php

namespace Database\Factories;

use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInvalidDayPolicy;
use App\Enums\ObligationSeriesStatus;
use App\Models\Emission;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationSeries>
 */
class ObligationSeriesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'obligation_category' => 'Informacional',
            'obligation_type' => 'Relatório Periódico',
            'responsible_party' => 'Emissora',
            'responsible_area' => 'Gestão',
            'priority' => 'medium',
            'frequency' => null,
            'starts_on' => null,
            'ends_on' => null,
            'due_rule_type' => null,
            'due_day' => null,
            'due_offset_months' => 0,
            'due_offset_days' => null,
            'invalid_day_policy' => null,
            'calendar_code' => null,
            'generation_horizon_days' => 90,
            'status' => ObligationSeriesStatus::AwaitingConfiguration,
            'is_legacy_backfill' => false,
        ];
    }

    public function monthly(
        string $startsOn = '2026-01-01',
        string $endsOn = '2026-12-31',
        int $dueDay = 10,
        int $dueOffsetMonths = 1,
    ): static {
        return $this
            ->state(fn (): array => [
                'frequency' => ObligationFrequency::Monthly,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'due_rule_type' => ObligationDueRuleType::FixedDay,
                'due_day' => $dueDay,
                'due_offset_months' => $dueOffsetMonths,
                'due_offset_days' => null,
                'invalid_day_policy' => ObligationInvalidDayPolicy::LastValidDay,
                'status' => ObligationSeriesStatus::Active,
                'configuration_confirmed_at' => now(),
            ])
            ->afterCreating(function (ObligationSeries $series): void {
                ObligationSeriesRule::factory()->for($series, 'series')->create([
                    'version' => 1,
                    'effective_from' => $series->starts_on,
                    'frequency' => $series->frequency,
                    'due_rule_type' => $series->due_rule_type,
                    'due_day' => $series->due_day,
                    'due_offset_months' => $series->due_offset_months,
                    'due_offset_days' => $series->due_offset_days,
                    'invalid_day_policy' => $series->invalid_day_policy,
                    'calendar_code' => $series->calendar_code,
                ]);
            });
    }
}

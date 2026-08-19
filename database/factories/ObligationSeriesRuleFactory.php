<?php

namespace Database\Factories;

use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationFrequency;
use App\Enums\ObligationInvalidDayPolicy;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationSeriesRule>
 */
class ObligationSeriesRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'obligation_series_id' => ObligationSeries::factory(),
            'version' => 1,
            'effective_from' => now()->startOfMonth(),
            'frequency' => ObligationFrequency::Monthly,
            'due_rule_type' => ObligationDueRuleType::FixedDay,
            'due_day' => 10,
            'due_offset_months' => 1,
            'due_offset_days' => null,
            'invalid_day_policy' => ObligationInvalidDayPolicy::LastValidDay,
            'calendar_code' => null,
            'created_by' => null,
            'change_reason' => 'Configuração inicial da recorrência.',
        ];
    }
}

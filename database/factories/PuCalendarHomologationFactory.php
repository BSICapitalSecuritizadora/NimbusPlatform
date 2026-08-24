<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuCalendarHomologationStatus;
use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\Emission;
use App\Models\PuCalendarHomologation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PuCalendarHomologation>
 */
class PuCalendarHomologationFactory extends Factory
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
            'candidate_calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'purpose' => 'cdi_accrual_dup_and_lookup_lag',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => PuCalendarHomologationStatus::Draft->value,
            'legacy_parameter_snapshot' => ['calendar_code' => BusinessCalendarRegistry::LEGACY_B3],
            'candidate_parameter_snapshot' => ['calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA],
            'evidence_matrix' => null,
            'external_reference' => ['availability' => 'not_assessed'],
        ];
    }
}

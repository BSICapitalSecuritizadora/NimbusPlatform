<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarSelectionEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessCalendarSelectionEvidence>
 */
class BusinessCalendarSelectionEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'context' => 'contractual_calendar',
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'source_document' => 'Termo de securitização',
            'clause_reference' => 'Cláusula 5.1',
        ];
    }
}

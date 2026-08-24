<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarImportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessCalendarImportRun>
 */
class BusinessCalendarImportRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'batch_uuid' => fake()->uuid(),
            'business_calendar_year_id' => null,
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'year' => 2026,
            'source' => 'anbima',
            'source_is_official' => true,
            'source_file' => 'feriados_nacionais.xls',
            'source_document' => 'https://www.anbima.com.br/feriados/',
            'checksum' => fake()->sha256(),
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'triggered_by' => null,
            'triggered_by_process' => 'testing',
            'records_found' => 13,
            'records_inserted' => 13,
            'records_changed' => 0,
            'removals_detected' => 0,
            'conflicts_detected' => 0,
            'errors' => null,
            'result' => 'succeeded',
            'dry_run' => false,
        ];
    }
}

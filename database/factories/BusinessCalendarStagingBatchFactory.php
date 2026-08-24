<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarStagingBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessCalendarStagingBatch>
 */
class BusinessCalendarStagingBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'batch_uuid' => (string) Str::uuid(),
            'calendar_code' => BusinessCalendarRegistry::B3_LISTED_TRADING,
            'year' => 2026,
            'source' => 'b3_official_document',
            'source_is_official' => true,
            'source_document' => 'Ofício B3 de teste',
            'source_revision' => 'v1',
            'checksum' => hash('sha256', fake()->uuid()),
            'status' => BusinessCalendarStagingBatch::STATUS_PENDING_REVIEW,
            'records_staged' => 1,
            'staged_at' => now(),
        ];
    }
}

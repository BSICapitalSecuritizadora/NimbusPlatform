<?php

namespace Database\Factories;

use App\Models\BusinessCalendarStagedDate;
use App\Models\BusinessCalendarStagingBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessCalendarStagedDate>
 */
class BusinessCalendarStagedDateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_calendar_staging_batch_id' => BusinessCalendarStagingBatch::factory(),
            'calendar_date' => '2026-01-02',
            'is_business_day' => true,
            'description' => 'Sessão regular',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessCalendarOverride>
 */
class BusinessCalendarOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_calendar_year_id' => null,
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'calendar_date' => fake()->dateTimeBetween('2026-01-01', '2026-12-31')->format('Y-m-d'),
            'reason' => fake()->sentence(),
            'previous_is_business_day' => true,
            'new_is_business_day' => false,
            'created_by' => User::factory(),
            'applied_at' => now(),
            'revision' => 2,
        ];
    }
}

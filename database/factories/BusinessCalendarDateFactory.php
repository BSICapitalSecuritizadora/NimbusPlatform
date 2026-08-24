<?php

namespace Database\Factories;

use App\Models\BusinessCalendarDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessCalendarDate>
 */
class BusinessCalendarDateFactory extends Factory
{
    public function definition(): array
    {
        $calendarDate = CarbonImmutable::instance(fake()->dateTimeBetween('-1 month', '+1 month'));

        return [
            'calendar_code' => 'B3',
            'calendar_date' => $calendarDate->toDateString(),
            'is_business_day' => ! $calendarDate->isWeekend(),
            'description' => null,
            'data_origin' => 'inferred',
            'source' => 'calendar_inference',
            'source_is_official' => false,
        ];
    }
}

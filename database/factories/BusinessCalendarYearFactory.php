<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Support\BusinessCalendarRegistry;
use App\Models\BusinessCalendarYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessCalendarYear>
 */
class BusinessCalendarYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'calendar_code' => BusinessCalendarRegistry::BR_BANKING_ANBIMA,
            'year' => fake()->numberBetween(2020, 2035),
            'status' => 'provisional',
            'source' => 'anbima',
            'source_is_official' => true,
            'source_document' => 'feriados_nacionais.xls',
            'source_revision' => null,
            'revision' => 1,
            'checksum' => fake()->sha256(),
            'confirmed_at' => null,
            'confirmed_by' => null,
        ];
    }
}

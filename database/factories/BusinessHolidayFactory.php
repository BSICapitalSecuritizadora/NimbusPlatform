<?php

namespace Database\Factories;

use App\Models\BusinessHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHoliday>
 */
class BusinessHolidayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'calendar_code' => 'B3',
            'holiday_date' => $this->faker->dateTimeBetween('-2 years', '+2 years')->format('Y-m-d'),
            'name' => $this->faker->randomElement([
                'Confraternização Universal',
                'Tiradentes',
                'Natal',
                'Finados',
                'Independência do Brasil',
            ]),
            'source' => 'anbima',
            'data_origin' => 'imported',
            'source_is_official' => true,
            'source_file' => 'feriados_nacionais.xls',
            'source_document' => 'https://www.anbima.com.br/feriados/',
            'imported_at' => now(),
            'imported_by' => null,
            'notes' => null,
        ];
    }
}

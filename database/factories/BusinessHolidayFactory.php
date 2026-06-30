<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BusinessHoliday>
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
            'source_file' => 'feriados_nacionais.xls',
            'imported_at' => now(),
            'imported_by' => null,
            'notes' => null,
        ];
    }
}

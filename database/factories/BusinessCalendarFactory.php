<?php

namespace Database\Factories;

use App\Models\BusinessCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessCalendar>
 */
class BusinessCalendarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'CONTRACT_'.Str::upper(fake()->unique()->lexify('??????'));

        return [
            'code' => $code,
            'name' => fake()->sentence(3),
            'purpose' => fake()->sentence(),
            'calendar_type' => 'contractual',
            'source' => 'Documento contratual',
            'status' => 'active',
            'import_mode' => 'manual_approval',
            'is_official' => false,
            'financial_use_allowed' => true,
            'is_legacy' => false,
            'is_homologation' => false,
            'accepts_anbima' => false,
            'available_for_new_configurations' => true,
        ];
    }
}

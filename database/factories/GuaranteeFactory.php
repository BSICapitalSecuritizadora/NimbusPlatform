<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\Guarantee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guarantee>
 */
class GuaranteeFactory extends Factory
{
    protected $model = Guarantee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'guarantee_type' => fake()->randomElement([
                'Alienacao fiduciaria',
                'Cessao fiduciaria',
                'Fianca',
            ]),
            'minimum_value' => fake()->randomFloat(2, 10000, 5000000),
            'validity_start_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'validity_end_date' => fake()->dateTimeBetween('+1 month', '+5 years')->format('Y-m-d'),
            'description' => fake()->sentence(),
            'evaluation_frequency' => fake()->randomElement([
                'Mensal',
                'Trimestral',
                'Semestral',
                'Anual',
            ]),
        ];
    }
}

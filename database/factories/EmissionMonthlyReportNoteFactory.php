<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\EmissionMonthlyReportNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmissionMonthlyReportNote>
 */
class EmissionMonthlyReportNoteFactory extends Factory
{
    protected $model = EmissionMonthlyReportNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'reference_month' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-01'),
            'category' => fake()->randomElement(array_keys(EmissionMonthlyReportNote::CATEGORY_OPTIONS)),
            'title' => fake()->sentence(4),
            'content' => fake()->paragraph(),
            'is_visible_on_report' => true,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['is_visible_on_report' => false]);
    }
}

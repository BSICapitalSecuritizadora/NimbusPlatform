<?php

namespace Database\Factories;

use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeasurementPlanLine>
 */
class MeasurementPlanLineFactory extends Factory
{
    protected $model = MeasurementPlanLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plannedMonthly = fake()->randomFloat(2, 1, 15);
        $plannedCumulative = fake()->randomFloat(2, $plannedMonthly, 100);
        $realizedCumulative = fake()->randomFloat(2, 0, 100);

        return [
            'plan_set_id' => MeasurementPlanSet::factory(),
            'operation_id' => fn (array $attributes) => MeasurementPlanSet::find($attributes['plan_set_id'])?->operation_id
                ?? MeasurementPlanSet::factory()->create()->operation_id,
            'sequence_number' => fake()->unique()->numberBetween(1, 100000),
            'planned_monthly_percent' => $plannedMonthly,
            'planned_cumulative_percent' => $plannedCumulative,
            'initial_realized_cumulative_percent' => 0,
            'realized_monthly_percent' => fake()->randomFloat(2, 0, 15),
            'realized_cumulative_percent' => $realizedCumulative,
            'measurement_date' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-01'),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalBenchmarkRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmissionPuExternalBenchmarkRow>
 */
class EmissionPuExternalBenchmarkRowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benchmark_id' => EmissionPuExternalBenchmark::factory(),
            'reference_date' => fake()->date(),
            'unit_value' => '1000.0000000000000000',
        ];
    }
}

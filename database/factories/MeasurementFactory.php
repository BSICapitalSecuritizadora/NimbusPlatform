<?php

namespace Database\Factories;

use App\Models\Measurement;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Measurement>
 */
class MeasurementFactory extends Factory
{
    protected $model = Measurement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_id' => Operation::factory(),
            'reference_month' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-01'),
            'filename' => fake()->word().'.pdf',
            'storage_path' => 'measurements/'.fake()->uuid().'.pdf',
            'notes' => fake()->optional()->sentence(),
            'status' => 'pending',
            'current_stage' => 1,
            'uploaded_at' => now(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Measurement;
use App\Models\MeasurementPause;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeasurementPause>
 */
class MeasurementPauseFactory extends Factory
{
    protected $model = MeasurementPause::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'measurement_id' => Measurement::factory(),
            'stage' => 1,
            'paused_by' => User::factory(),
            'pause_reason' => fake()->sentence(),
            'paused_operation_status' => 'active',
            'paused_at' => now(),
        ];
    }
}

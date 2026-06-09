<?php

namespace Database\Factories;

use App\Models\Measurement;
use App\Models\MeasurementReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeasurementReview>
 */
class MeasurementReviewFactory extends Factory
{
    protected $model = MeasurementReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'measurement_id' => Measurement::factory(),
            'stage' => 1,
            'reviewer_user_id' => User::factory(),
            'status' => 'pending',
            'notes' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => 'rejected',
            'reviewed_at' => now(),
            'notes' => fake()->sentence(),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Measurement;
use App\Models\MeasurementAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeasurementAsset>
 */
class MeasurementAssetFactory extends Factory
{
    protected $model = MeasurementAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'measurement_id' => Measurement::factory(),
            'plan_set_id' => null,
            'filename' => fake()->word().'.pdf',
            'storage_path' => 'measurements/assets/'.fake()->uuid().'.pdf',
            'size' => fake()->numberBetween(1024, 10485760),
            'uploaded_at' => now(),
        ];
    }
}

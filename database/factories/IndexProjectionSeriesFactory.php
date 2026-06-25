<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\IndexProjectionSeriesStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IndexProjectionSeries>
 */
class IndexProjectionSeriesFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'indexer' => PuIndexer::Ipca->value,
            'name' => 'IPCA projetado '.fake()->year(),
            'status' => IndexProjectionSeriesStatus::Draft->value,
            'projection_source' => 'ANBIMA',
            'projection_policy' => 'market',
            'version' => 'v1',
            'reference_date' => fake()->date(),
            'description' => fake()->sentence(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => IndexProjectionSeriesStatus::Approved->value,
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => IndexProjectionSeriesStatus::Rejected->value,
            'rejected_at' => now(),
        ]);
    }
}

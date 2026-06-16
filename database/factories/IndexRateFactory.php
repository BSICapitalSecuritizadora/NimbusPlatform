<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuIndexer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IndexRate>
 */
class IndexRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'indexer' => PuIndexer::Cdi->value,
            'rate_date' => fake()->date(),
            'rate_value' => fake()->randomFloat(4, 8, 16),
            'source' => 'manual',
            'source_reference' => fake()->sentence(),
        ];
    }
}

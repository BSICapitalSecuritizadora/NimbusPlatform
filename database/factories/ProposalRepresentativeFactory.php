<?php

namespace Database\Factories;

use App\Models\ProposalRepresentative;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalRepresentative>
 */
class ProposalRepresentativeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'queue_position' => fake()->unique()->numberBetween(1, 999),
            'is_active' => true,
        ];
    }
}

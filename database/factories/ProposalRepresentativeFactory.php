<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProposalRepresentative>
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

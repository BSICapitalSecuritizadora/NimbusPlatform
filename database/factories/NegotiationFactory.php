<?php

namespace Database\Factories;

use App\Models\Construction;
use App\Models\Emission;
use App\Models\Negotiation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Negotiation>
 */
class NegotiationFactory extends Factory
{
    protected $model = Negotiation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'construction_id' => Construction::factory(),
            'reference_month' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-01'),
            'sales' => fake()->numberBetween(0, 50),
            'cancellations' => fake()->numberBetween(0, 20),
        ];
    }

    public function forEmissionAndConstruction(Emission $emission, Construction $construction): static
    {
        return $this->state(fn (): array => [
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
        ]);
    }
}

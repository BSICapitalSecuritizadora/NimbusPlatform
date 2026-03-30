<?php

namespace Database\Factories;

use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<Vacancy>
 */
class VacancyFactory extends Factory
{
    protected $model = Vacancy::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->jobTitle(),
            'department' => fake()->randomElement([
                'Comercial',
                'Operações',
                'Relações com Investidores',
                'Estruturação',
            ]),
            'location' => fake()->randomElement([
                'São Paulo, SP',
                'Rio de Janeiro, RJ',
                'Remoto',
            ]),
            'type' => fake()->randomElement([
                'CLT',
                'PJ',
                'Estágio',
                'Freelance',
            ]),
            'description' => fake()->paragraphs(3, true),
            'requirements' => fake()->paragraphs(2, true),
            'benefits' => fake()->paragraphs(2, true),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}

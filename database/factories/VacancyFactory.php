<?php

namespace Database\Factories;

use App\Enums\VacancyDepartment;
use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyStatus;
use App\Enums\VacancyWorkModel;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vacancy>
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
        $status = fake()->randomElement([VacancyStatus::Published, VacancyStatus::Draft, VacancyStatus::Paused]);

        return [
            'title' => fake()->unique()->jobTitle(),
            'slug' => null,
            'status' => $status,
            'department' => fake()->randomElement(VacancyDepartment::cases())->value,
            'location' => fake()->randomElement([
                'São Paulo, SP',
                'Rio de Janeiro, RJ',
                'Remoto',
            ]),
            'type' => fake()->randomElement(VacancyEmploymentType::cases())->value,
            'work_model' => fake()->randomElement(VacancyWorkModel::cases())->value,
            'description' => fake()->paragraphs(3, true),
            'requirements' => fake()->paragraphs(2, true),
            'benefits' => fake()->paragraphs(2, true),
            'positions' => fake()->numberBetween(1, 3),
            'hiring_manager_id' => null,
            'published_at' => $status === VacancyStatus::Published ? now() : null,
            'expires_at' => null,
            'closed_at' => null,
            'salary_min' => null,
            'salary_max' => null,
            'salary_visible' => false,
            'internal_notes' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => VacancyStatus::Paused,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => VacancyStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => VacancyStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => VacancyStatus::Paused,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => VacancyStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'status' => VacancyStatus::Archived,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => VacancyStatus::Published,
            'published_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);
    }
}

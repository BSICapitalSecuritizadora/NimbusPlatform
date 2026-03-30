<?php

namespace Database\Factories;

use App\Models\JobApplication;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<JobApplication>
 */
class JobApplicationFactory extends Factory
{
    protected $model = JobApplication::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vacancy_id' => Vacancy::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('(##) #####-####'),
            'linkedin_url' => fake()->boolean() ? fake()->url() : null,
            'resume_path' => 'resumes/'.fake()->uuid().'.pdf',
            'message' => fake()->boolean() ? fake()->paragraph() : null,
            'status' => JobApplication::STATUS_NEW,
            'internal_notes' => null,
            'reviewed_at' => null,
            'reviewed_by_user_id' => null,
        ];
    }

    public function withStatus(string $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ]);
    }
}

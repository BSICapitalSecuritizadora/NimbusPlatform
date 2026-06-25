<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ObligationComment>
 */
class ObligationCommentFactory extends Factory
{
    protected $model = ObligationComment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $emission = Emission::factory();

        return [
            'obligation_id' => Obligation::factory()->for($emission),
            'emission_id' => $emission,
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
            'is_internal' => true,
            'edited_at' => null,
            'edited_by' => null,
        ];
    }

    public function edited(?User $editor = null): static
    {
        return $this->state(fn (): array => [
            'edited_at' => now(),
            'edited_by' => $editor?->id,
        ]);
    }
}

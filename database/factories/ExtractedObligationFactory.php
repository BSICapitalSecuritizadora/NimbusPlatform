<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\ExtractedObligation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtractedObligation>
 */
class ExtractedObligationFactory extends Factory
{
    protected $model = ExtractedObligation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'document_id' => null,
            'title' => fake()->sentence(4),
            'obligation_type' => 'Relatório Periódico',
            'obligation_category' => 'Informacional',
            'description' => fake()->paragraph(),
            'responsible_party' => 'Emissora',
            'responsible_area' => 'Gestão',
            'recurrence' => 'Mensal',
            'due_rule' => 'até o 10º dia útil de cada mês',
            'due_date' => null,
            'priority' => 'medium',
            'status' => 'suggested',
            'required_evidence' => null,
            'source_clause' => 'Cláusula 8.1',
            'source_page' => fake()->numberBetween(1, 50),
            'source_excerpt' => fake()->sentence(10),
            'confidence_score' => fake()->randomFloat(2, 0.6, 1),
            'review_notes' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'rejected',
            'reviewed_at' => now(),
        ]);
    }
}

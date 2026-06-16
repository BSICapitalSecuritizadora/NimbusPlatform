<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\Obligation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Obligation>
 */
class ObligationFactory extends Factory
{
    protected $model = Obligation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'extracted_obligation_id' => null,
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
            'status' => 'em_dia',
            'required_evidence' => null,
            'source_clause' => 'Cláusula 8.1',
            'source_page' => fake()->numberBetween(1, 50),
            'source_excerpt' => fake()->sentence(10),
            'notes' => null,
        ];
    }
}

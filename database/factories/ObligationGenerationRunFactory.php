<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\ObligationGenerationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationGenerationRun>
 */
class ObligationGenerationRunFactory extends Factory
{
    protected $model = ObligationGenerationRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'document_id' => null,
            'user_id' => null,
            'status' => ObligationGenerationRun::STATUS_PENDING,
            'current_step' => 'queued',
            'message' => 'Geração na fila de processamento...',
            'generated_count' => 0,
            'error_message' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => ObligationGenerationRun::STATUS_RUNNING,
            'current_step' => 'extracting',
            'message' => 'Extraindo obrigações do documento...',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ObligationGenerationRun::STATUS_COMPLETED,
            'current_step' => 'completed',
            'message' => 'Geração concluída com sucesso.',
            'generated_count' => fake()->numberBetween(1, 20),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ObligationGenerationRun::STATUS_FAILED,
            'current_step' => 'failed',
            'message' => 'Não foi possível concluir a geração das obrigações.',
            'error_message' => 'Erro de exemplo.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }
}

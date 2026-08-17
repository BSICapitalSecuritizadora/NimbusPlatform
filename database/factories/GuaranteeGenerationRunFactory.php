<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\GuaranteeGenerationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuaranteeGenerationRun>
 */
class GuaranteeGenerationRunFactory extends Factory
{
    protected $model = GuaranteeGenerationRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'status' => GuaranteeGenerationRun::STATUS_PENDING,
            'current_step' => 'queued',
            'message' => 'Aguardando processamento...',
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GuaranteeGenerationRun::STATUS_RUNNING,
            'current_step' => 'extracting',
            'started_at' => now(),
        ]);
    }

    public function completed(int $detectedCount = 3): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GuaranteeGenerationRun::STATUS_COMPLETED,
            'current_step' => 'completed',
            'detected_count' => $detectedCount,
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GuaranteeGenerationRun::STATUS_FAILED,
            'current_step' => 'failed',
            'error_message' => 'Falha ao processar o documento.',
            'finished_at' => now(),
        ]);
    }
}

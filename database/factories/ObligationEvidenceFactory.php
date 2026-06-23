<?php

namespace Database\Factories;

use App\Models\Emission;
use App\Models\Obligation;
use App\Models\ObligationEvidence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ObligationEvidence>
 */
class ObligationEvidenceFactory extends Factory
{
    protected $model = ObligationEvidence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $emission = Emission::factory();

        return [
            'obligation_id' => Obligation::factory()->for($emission),
            'emission_id' => $emission,
            'uploaded_by' => null,
            'original_name' => fake()->word().'.pdf',
            'path' => 'nimbus_docs/obligation-evidences/'.fake()->uuid().'.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 2_000_000),
            'description' => null,
            'status' => ObligationEvidence::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'rejection_reason' => null,
            'uploaded_at' => now(),
        ];
    }

    public function approved(?User $reviewer = null): static
    {
        return $this->state(fn (): array => [
            'status' => ObligationEvidence::STATUS_APPROVED,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
            'review_notes' => null,
            'rejection_reason' => null,
        ]);
    }

    public function rejected(?User $reviewer = null): static
    {
        return $this->state(fn (): array => [
            'status' => ObligationEvidence::STATUS_REJECTED,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
            'review_notes' => null,
            'rejection_reason' => 'Documento inconsistente.',
        ]);
    }
}

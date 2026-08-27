<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuBaselineEvidenceDocumentType;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceStatus;
use App\Domain\PuCalculator\Enums\PuBaselineEvidenceType;
use App\Models\Document;
use App\Models\Emission;
use App\Models\EmissionPuBaselineEvidence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmissionPuBaselineEvidence>
 */
class EmissionPuBaselineEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'document_id' => Document::factory(),
            'evidence_type' => PuBaselineEvidenceType::FirstIntegralizationDate,
            'document_type' => PuBaselineEvidenceDocumentType::B3SettlementStatement,
            'evidenced_value' => fake()->date(),
            'reference' => 'Página 1',
            'confidence' => 'high',
            'status' => PuBaselineEvidenceStatus::PendingReview,
            'created_by' => User::factory(),
        ];
    }
}

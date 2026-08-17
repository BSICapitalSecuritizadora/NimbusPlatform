<?php

namespace Database\Factories;

use App\Enums\GuaranteeDetectionStatus;
use App\Enums\GuaranteeEventType;
use App\Enums\GuaranteeEvidenceLevel;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Enums\GuaranteeType;
use App\Enums\LegalDocumentType;
use App\Models\Emission;
use App\Models\ExtractedGuarantee;
use App\Models\Guarantee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtractedGuarantee>
 */
class ExtractedGuaranteeFactory extends Factory
{
    protected $model = ExtractedGuarantee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'document_id' => null,
            'status' => GuaranteeDetectionStatus::Suggested,
            'event_type' => GuaranteeEventType::Constitution,
            'type' => GuaranteeType::RealEstateFiduciaryAlienation,
            'name' => 'Alienação Fiduciária de Imóvel',
            'identification' => [
                'registration_number' => '45.721',
                'registry_office' => '2º RGI de Niterói/RJ',
                'owner' => 'SPE Exemplo Ltda.',
            ],
            'contracted_value' => 23500000,
            'requirement_basis' => GuaranteeRequirementBasis::None,
            'document_type' => LegalDocumentType::SecuritizationTerm,
            'document_date' => '2024-01-10',
            'source_clause' => '8.3.1',
            'source_page' => 42,
            'source_excerpt' => 'Fica constituída, em favor da Emissora, a alienação fiduciária do imóvel matriculado sob o nº 45.721.',
            'confidence_score' => 0.92,
            'field_evidence' => [
                'type' => GuaranteeEvidenceLevel::Explicit->value,
                'contracted_value' => GuaranteeEvidenceLevel::Explicit->value,
            ],
            'has_conflict' => false,
        ];
    }

    public function receivablesCoverage(float $percentage = 1.2): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => GuaranteeType::ReceivablesFiduciaryAssignment,
            'name' => 'Cessão Fiduciária de Recebíveis',
            'identification' => ['portfolio' => 'Recebíveis do empreendimento'],
            'contracted_value' => null,
            'requirement_basis' => GuaranteeRequirementBasis::Percentage,
            'requirement_percentage' => $percentage,
            'requirement_base' => GuaranteeRequirementBase::OutstandingBalance,
            'source_clause' => '5.2',
            'source_page' => 17,
        ]);
    }

    /** Candidata que altera uma garantia já confirmada, vinda de aditamento. */
    public function amending(Guarantee $guarantee, GuaranteeEventType $eventType = GuaranteeEventType::Amendment): static
    {
        return $this->state(fn (array $attributes): array => [
            'related_guarantee_id' => $guarantee->id,
            'event_type' => $eventType,
            'document_type' => LegalDocumentType::TermAmendment,
        ]);
    }

    public function withConflict(string $reason): static
    {
        return $this->state(fn (array $attributes): array => [
            'has_conflict' => true,
            'conflict_reason' => $reason,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GuaranteeDetectionStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GuaranteeDetectionStatus::Rejected,
            'reviewed_at' => now(),
            'review_notes' => 'Cláusula não constitui garantia.',
        ]);
    }
}

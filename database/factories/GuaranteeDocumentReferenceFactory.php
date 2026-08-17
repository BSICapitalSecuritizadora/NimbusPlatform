<?php

namespace Database\Factories;

use App\Enums\GuaranteeConfidenceLevel;
use App\Enums\GuaranteeDocumentReferenceType;
use App\Enums\LegalDocumentType;
use App\Models\Guarantee;
use App\Models\GuaranteeDocumentReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuaranteeDocumentReference>
 */
class GuaranteeDocumentReferenceFactory extends Factory
{
    protected $model = GuaranteeDocumentReference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guarantee_id' => Guarantee::factory(),
            'reference_type' => GuaranteeDocumentReferenceType::Constitution,
            'document_title' => 'Termo de Securitização',
            'document_type' => LegalDocumentType::SecuritizationTerm,
            'document_date' => '2024-01-10',
            'clause' => '8.3.1',
            'page' => 42,
            'excerpt' => 'Fica constituída, em favor da Emissora, a alienação fiduciária do imóvel.',
            'confidence_level' => GuaranteeConfidenceLevel::High,
            'confidence_score' => 0.94,
            'extraction_method' => 'gemini',
        ];
    }

    public function ofType(GuaranteeDocumentReferenceType $type): static
    {
        return $this->state(fn (array $attributes): array => ['reference_type' => $type]);
    }
}

<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuCurvePromotionStatus;
use App\Models\EmissionPuCurvePromotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * As FKs obrigatórias (`emission_id`, `candidate_curve_version_id`,
 * `external_validation_id`, `requested_by`) NÃO têm default aqui de propósito:
 * uma promoção só é coerente sobre a cadeia inteira já governada -- candidate
 * validada externamente por revisor independente, benchmark imutável e dossiê de
 * comparação persistido. Quem quer esse cenário usa
 * `PuCandidateGovernanceFixture::externallyValidatedCandidate()` e o serviço de
 * pedido real; a factory existe para os estados de lifecycle.
 *
 * @extends Factory<EmissionPuCurvePromotion>
 */
class EmissionPuCurvePromotionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'calculation_version' => 'v1',
            'candidate_checksum' => hash('sha256', fake()->uuid()),
            'input_fingerprint' => hash('sha256', fake()->uuid()),
            'benchmark_dataset_sha256' => hash('sha256', fake()->uuid()),
            'comparison_sha256' => hash('sha256', fake()->uuid()),
            'rows_count' => 3,
            'status' => PuCurvePromotionStatus::PendingReview->value,
            'requested_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => PuCurvePromotionStatus::Approved->value,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => PuCurvePromotionStatus::Rejected->value,
            'reviewed_at' => now(),
            'review_reason' => 'Rejeitada no cenário sintético.',
        ]);
    }

    public function executed(): static
    {
        return $this->state(fn (): array => [
            'status' => PuCurvePromotionStatus::Executed->value,
            'reviewed_at' => now(),
            'promoted_at' => now(),
        ]);
    }
}

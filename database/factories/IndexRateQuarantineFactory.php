<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuIndexAnomalyType;
use App\Domain\PuCalculator\Enums\PuIndexQuarantineStatus;
use App\Models\IndexRateQuarantine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndexRateQuarantine>
 */
class IndexRateQuarantineFactory extends Factory
{
    /**
     * Salto suspeito de CDI ainda pendente; os estados mudam a situação.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fingerprint = hash('sha256', fake()->unique()->uuid());

        return [
            'indexer' => 'CDI',
            'rate_date' => '2026-03-16',
            'source' => 'bcb_sgs',
            'external_series_code' => '4389',
            'anomaly_type' => PuIndexAnomalyType::ImplausibleJump,
            'received_value' => '149.00000000',
            'raw_value' => '149.00',
            'reason' => 'O valor 149.00000000 difere 10x ou mais da observação realizada anterior.',
            'reference_value' => '14.90000000',
            'reference_date' => '2026-03-13',
            'fingerprint' => $fingerprint,
            'open_fingerprint' => $fingerprint,
            'status' => PuIndexQuarantineStatus::PendingReview,
            'occurrences' => 1,
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ];
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => PuIndexQuarantineStatus::Rejected,
            'open_fingerprint' => null,
            'decided_at' => now(),
            'decision_reason' => 'Valor conferido na fonte: erro de unidade.',
        ]);
    }
}

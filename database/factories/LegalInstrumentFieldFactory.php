<?php

namespace Database\Factories;

use App\Enums\GuaranteeEvidenceLevel;
use App\Enums\LegalInstrumentFieldKey;
use App\Enums\LegalInstrumentFieldStatus;
use App\Models\LegalInstrument;
use App\Models\LegalInstrumentField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalInstrumentField>
 */
class LegalInstrumentFieldFactory extends Factory
{
    protected $model = LegalInstrumentField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_instrument_id' => LegalInstrument::factory(),
            'field_key' => LegalInstrumentFieldKey::OriginalAmount,
            'value_type' => LegalInstrumentFieldKey::OriginalAmount->valueType(),
            'value' => '30000000',
            'value_numeric' => 30000000,
            'effective_date' => '2024-01-10',
            'status' => LegalInstrumentFieldStatus::Confirmed,
            'evidence_level' => GuaranteeEvidenceLevel::Explicit,
            'confidence_score' => 0.95,
            'clause' => '2.1',
            'page' => 3,
        ];
    }

    public function forKey(LegalInstrumentFieldKey $key, ?string $value, ?float $numeric = null, ?string $date = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'field_key' => $key,
            'value_type' => $key->valueType(),
            'value' => $value,
            'value_numeric' => $numeric,
            'value_date' => $date,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LegalInstrumentFieldStatus::PendingReview,
        ]);
    }

    public function effectiveOn(string $date): static
    {
        return $this->state(fn (array $attributes): array => ['effective_date' => $date]);
    }
}

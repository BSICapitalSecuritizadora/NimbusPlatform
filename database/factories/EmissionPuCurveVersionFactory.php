<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveInternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuCurveReviewStatus;
use App\Domain\PuCalculator\Enums\PuCurveRole;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmissionPuCurveVersion>
 */
class EmissionPuCurveVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emission_id' => Emission::factory(),
            'calculation_version' => 'v1',
            'curve_role' => PuCurveRole::Operational->value,
            'review_status' => PuCurveReviewStatus::NotApplicable->value,
            'batch_id' => (string) Str::uuid(),
            'status' => PuCurveStatus::Generated->value,
            'engine_version' => 'phase1-cdi-v1',
            'parameters_snapshot' => null,
            'rows_count' => 4,
            'error_message' => null,
            'validation_summary' => null,
            'generated_at' => now(),
        ];
    }

    public function homologated(): static
    {
        return $this->state(fn (): array => [
            'status' => PuCurveStatus::Homologated->value,
            'homologated_at' => now(),
        ]);
    }

    public function obsolete(): static
    {
        return $this->state(fn (): array => [
            'status' => PuCurveStatus::Obsolete->value,
        ]);
    }

    public function candidate(): static
    {
        return $this->state(fn (): array => [
            'curve_role' => PuCurveRole::Candidate->value,
            'status' => PuCurveStatus::Validated->value,
            'candidate_as_of' => now()->toDateString(),
            'input_fingerprint' => hash('sha256', fake()->uuid()),
            'curve_checksum' => hash('sha256', fake()->uuid()),
            'internal_validation_status' => PuCurveInternalValidationStatus::Passed->value,
            'external_validation_status' => PuCurveExternalValidationStatus::Pending->value,
            'review_status' => PuCurveReviewStatus::PendingReview->value,
            'validated_at' => now(),
        ]);
    }
}

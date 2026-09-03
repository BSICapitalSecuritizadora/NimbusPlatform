<?php

namespace Database\Factories;

use App\Domain\PuCalculator\Enums\PuCurveExternalValidationStatus;
use App\Domain\PuCalculator\Enums\PuExternalValidationCoverageStatus;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalValidation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmissionPuExternalValidation>
 */
class EmissionPuExternalValidationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_curve_version_id' => EmissionPuCurveVersion::factory()->candidate(),
            'benchmark_id' => EmissionPuExternalBenchmark::factory(),
            'candidate_checksum' => hash('sha256', fake()->uuid()),
            'benchmark_dataset_sha256' => hash('sha256', fake()->uuid()),
            'comparison_algorithm_version' => 'exact-date-financial-diff-v1',
            'comparison_sha256' => hash('sha256', fake()->uuid()),
            'coverage_status' => PuExternalValidationCoverageStatus::Full->value,
            'compared_rows' => 1,
            'candidate_dates_without_reference' => 0,
            'reference_dates_without_candidate' => 0,
            'status' => PuCurveExternalValidationStatus::Pending->value,
            'generated_by' => User::factory(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_reason' => null,
        ];
    }
}

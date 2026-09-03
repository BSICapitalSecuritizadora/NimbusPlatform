<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuExternalBenchmarkComparisonResult;
use App\Domain\PuCalculator\Enums\PuExternalValidationCoverageStatus;
use App\Domain\PuCalculator\Enums\PuExternalValidationGapType;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use App\Models\EmissionPuExternalBenchmark;
use App\Models\EmissionPuExternalBenchmarkRow;
use App\Models\EmissionPuExternalValidation;

final class PuExternalBenchmarkComparisonPlanService
{
    public const ALGORITHM_VERSION = 'exact-date-financial-diff-v1';

    public const ACTION_READY = 'ready_to_compare_external_benchmark';

    public const ACTION_ALREADY_COMPARED = 'external_benchmark_already_compared';

    public const ACTION_BENCHMARK_NOT_FOUND = 'external_benchmark_not_found';

    public const ACTION_BENCHMARK_MISMATCH = 'external_benchmark_emission_mismatch';

    public const ACTION_BENCHMARK_INTEGRITY_FAILURE = 'external_benchmark_integrity_failure';

    public const ACTION_NO_OVERLAP = 'external_comparison_no_overlap';

    public const ACTION_CONFLICT = 'external_comparison_conflict';

    public function __construct(
        private readonly PuCandidateExternalValidationEligibilityService $eligibility,
        private readonly PuExternalBenchmarkIntegrityService $benchmarkIntegrity,
        private readonly PuNumericHomologationFinancialDiffService $financialDiff,
        private readonly PuExternalBenchmarkFingerprintService $fingerprints,
        private readonly PuExternalComparisonIntegrityService $comparisonIntegrity,
    ) {}

    public function plan(
        ?EmissionPuCurveVersion $candidate,
        ?EmissionPuExternalBenchmark $benchmark,
    ): PuExternalBenchmarkComparisonResult {
        $eligibility = $this->eligibility->inspect($candidate);

        if (! $eligibility['ready'] || ! $candidate instanceof EmissionPuCurveVersion) {
            return new PuExternalBenchmarkComparisonResult(
                action: $eligibility['action'],
                reason: $eligibility['reason'],
                candidateVersionId: $candidate?->id,
                benchmarkId: $benchmark?->id,
            );
        }

        if (! $benchmark instanceof EmissionPuExternalBenchmark) {
            return $this->failure($candidate, null, self::ACTION_BENCHMARK_NOT_FOUND, 'The external benchmark does not exist.');
        }

        if ($benchmark->emission_id !== $candidate->emission_id) {
            return $this->failure(
                $candidate,
                $benchmark,
                self::ACTION_BENCHMARK_MISMATCH,
                'Candidate and external benchmark must belong to the same emission.',
            );
        }

        $benchmarkIntegrity = $this->benchmarkIntegrity->inspect($benchmark);

        if (! $benchmarkIntegrity['valid']) {
            return $this->failure(
                $candidate,
                $benchmark,
                self::ACTION_BENCHMARK_INTEGRITY_FAILURE,
                $benchmarkIntegrity['reason'],
            );
        }

        $candidateRows = $candidate->dailyCurves()
            ->orderBy('curve_date')
            ->get(['curve_date', 'updated_unit_value'])
            ->map(fn (EmissionPuDailyCurve $row): array => [
                'curve_date' => $row->curve_date->toDateString(),
                'unit_value' => (string) $row->updated_unit_value,
            ])
            ->all();
        $referenceRows = $benchmark->rows()
            ->orderBy('reference_date')
            ->get(['reference_date', 'unit_value'])
            ->map(fn (EmissionPuExternalBenchmarkRow $row): array => [
                'curve_date' => $row->reference_date->toDateString(),
                'unit_value' => (string) $row->unit_value,
            ])
            ->all();
        $financialDiff = $this->financialDiff->compareRows($candidateRows, [
            'source' => $benchmark->source_name,
            'reference_date' => $benchmark->reference_as_of?->toDateString(),
            'document_id' => $benchmark->source_document_id,
            'status' => $benchmark->status->value,
            'rows' => $referenceRows,
        ]);
        $differences = [];
        $gaps = [];

        foreach ($financialDiff->differences as $difference) {
            $date = (string) $difference['curve_date'];
            $candidateValue = $difference['candidate'] ?? null;
            $referenceValue = $difference['reference'] ?? null;

            if (is_string($candidateValue) && is_string($referenceValue)) {
                $differences[] = [
                    'reference_date' => $date,
                    'candidate_unit_value' => $candidateValue,
                    'external_unit_value' => $referenceValue,
                    'absolute_difference' => $difference['absolute_difference'],
                    'relative_difference_percentage' => $difference['relative_difference_percentage'],
                    'classification' => $difference['classification'],
                ];

                continue;
            }

            $gaps[] = [
                'reference_date' => $date,
                'gap_type' => is_string($candidateValue)
                    ? PuExternalValidationGapType::CandidateWithoutReference->value
                    : PuExternalValidationGapType::ReferenceWithoutCandidate->value,
            ];
        }

        $candidateGapCount = collect($gaps)->where(
            'gap_type',
            PuExternalValidationGapType::CandidateWithoutReference->value,
        )->count();
        $referenceGapCount = collect($gaps)->where(
            'gap_type',
            PuExternalValidationGapType::ReferenceWithoutCandidate->value,
        )->count();
        $coverage = $this->coverage(count($differences), $candidateGapCount, $referenceGapCount);

        if ($coverage === PuExternalValidationCoverageStatus::None) {
            return new PuExternalBenchmarkComparisonResult(
                action: self::ACTION_NO_OVERLAP,
                reason: 'No exact reference date overlaps the candidate; no validation dossier can be created.',
                candidateVersionId: $candidate->id,
                benchmarkId: $benchmark->id,
                candidateChecksum: $candidate->curve_checksum,
                benchmarkDatasetSha256: $benchmark->dataset_sha256,
                comparisonAlgorithmVersion: self::ALGORITHM_VERSION,
                coverageStatus: $coverage->value,
                candidateDatesWithoutReference: $candidateGapCount,
                referenceDatesWithoutCandidate: $referenceGapCount,
                gaps: $gaps,
                tolerancePolicy: null,
            );
        }

        $comparisonSha256 = $this->fingerprints->comparison(
            candidateChecksum: (string) $candidate->curve_checksum,
            benchmarkDatasetSha256: $benchmark->dataset_sha256,
            algorithmVersion: self::ALGORITHM_VERSION,
            coverageStatus: $coverage->value,
            rows: $differences,
            gaps: $gaps,
        );
        $existing = EmissionPuExternalValidation::query()
            ->where('candidate_curve_version_id', $candidate->id)
            ->where('benchmark_id', $benchmark->id)
            ->where('comparison_algorithm_version', self::ALGORITHM_VERSION)
            ->first();

        if ($existing instanceof EmissionPuExternalValidation) {
            $existingIntegrity = $this->comparisonIntegrity->inspect($existing);
            $isSame = $existingIntegrity['valid']
                && hash_equals($existing->candidate_checksum, (string) $candidate->curve_checksum)
                && hash_equals($existing->benchmark_dataset_sha256, $benchmark->dataset_sha256)
                && hash_equals($existing->comparison_sha256, $comparisonSha256);

            return new PuExternalBenchmarkComparisonResult(
                action: $isSame ? self::ACTION_ALREADY_COMPARED : self::ACTION_CONFLICT,
                reason: $isSame
                    ? 'The same reproducible candidate × benchmark comparison already exists.'
                    : 'The existing comparison identity does not match the current deterministic result.',
                candidateVersionId: $candidate->id,
                benchmarkId: $benchmark->id,
                externalValidationId: $existing->id,
                candidateChecksum: $candidate->curve_checksum,
                benchmarkDatasetSha256: $benchmark->dataset_sha256,
                comparisonAlgorithmVersion: self::ALGORITHM_VERSION,
                comparisonSha256: $comparisonSha256,
                coverageStatus: $coverage->value,
                comparedRows: count($differences),
                candidateDatesWithoutReference: $candidateGapCount,
                referenceDatesWithoutCandidate: $referenceGapCount,
                differences: $differences,
                gaps: $gaps,
                maximumAbsoluteDifference: $financialDiff->maximumAbsoluteDifference,
                maximumRelativeDifference: $financialDiff->maximumRelativeDifference,
                tolerancePolicy: null,
            );
        }

        return new PuExternalBenchmarkComparisonResult(
            action: self::ACTION_READY,
            reason: 'Exact-date differences are ready to be persisted without a tolerance classification.',
            candidateVersionId: $candidate->id,
            benchmarkId: $benchmark->id,
            candidateChecksum: $candidate->curve_checksum,
            benchmarkDatasetSha256: $benchmark->dataset_sha256,
            comparisonAlgorithmVersion: self::ALGORITHM_VERSION,
            comparisonSha256: $comparisonSha256,
            coverageStatus: $coverage->value,
            comparedRows: count($differences),
            candidateDatesWithoutReference: $candidateGapCount,
            referenceDatesWithoutCandidate: $referenceGapCount,
            differences: $differences,
            gaps: $gaps,
            maximumAbsoluteDifference: $financialDiff->maximumAbsoluteDifference,
            maximumRelativeDifference: $financialDiff->maximumRelativeDifference,
            tolerancePolicy: null,
        );
    }

    private function coverage(
        int $comparedRows,
        int $candidateGaps,
        int $referenceGaps,
    ): PuExternalValidationCoverageStatus {
        if ($comparedRows === 0) {
            return PuExternalValidationCoverageStatus::None;
        }

        return $candidateGaps === 0 && $referenceGaps === 0
            ? PuExternalValidationCoverageStatus::Full
            : PuExternalValidationCoverageStatus::Partial;
    }

    private function failure(
        EmissionPuCurveVersion $candidate,
        ?EmissionPuExternalBenchmark $benchmark,
        string $action,
        string $reason,
    ): PuExternalBenchmarkComparisonResult {
        return new PuExternalBenchmarkComparisonResult(
            action: $action,
            reason: $reason,
            candidateVersionId: $candidate->id,
            benchmarkId: $benchmark?->id,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Enums\PuExternalValidationGapType;
use App\Models\EmissionPuExternalValidation;
use App\Models\EmissionPuExternalValidationGap;
use App\Models\EmissionPuExternalValidationRow;

final class PuExternalComparisonIntegrityService
{
    public function __construct(private readonly PuExternalBenchmarkFingerprintService $fingerprints) {}

    /** @return array{valid:bool,reason:string,checksum:string} */
    public function inspect(EmissionPuExternalValidation $validation): array
    {
        $rows = $validation->rows()
            ->orderBy('reference_date')
            ->orderBy('id')
            ->get()
            ->map(fn (EmissionPuExternalValidationRow $row): array => [
                'reference_date' => $row->reference_date?->toDateString(),
                'candidate_unit_value' => (string) $row->candidate_unit_value,
                'external_unit_value' => (string) $row->external_unit_value,
                'absolute_difference' => (string) $row->absolute_difference,
                'relative_difference_percentage' => $row->relative_difference_percentage !== null
                    ? (string) $row->relative_difference_percentage
                    : null,
                'classification' => (string) $row->classification,
            ])
            ->all();
        $gaps = $validation->gaps()
            ->orderBy('reference_date')
            ->orderBy('gap_type')
            ->get()
            ->map(fn (EmissionPuExternalValidationGap $gap): array => [
                'reference_date' => $gap->reference_date?->toDateString() ?? '',
                'gap_type' => $gap->gap_type instanceof PuExternalValidationGapType
                    ? $gap->gap_type->value
                    : (string) $gap->gap_type,
            ])
            ->all();
        $checksum = $this->fingerprints->comparison(
            candidateChecksum: $validation->candidate_checksum,
            benchmarkDatasetSha256: $validation->benchmark_dataset_sha256,
            algorithmVersion: $validation->comparison_algorithm_version,
            coverageStatus: $validation->coverage_status->value,
            rows: $rows,
            gaps: $gaps,
        );
        $candidateGaps = collect($gaps)->where(
            'gap_type',
            PuExternalValidationGapType::CandidateWithoutReference->value,
        )->count();
        $referenceGaps = collect($gaps)->where(
            'gap_type',
            PuExternalValidationGapType::ReferenceWithoutCandidate->value,
        )->count();

        $valid = count($rows) === $validation->compared_rows
            && $candidateGaps === $validation->candidate_dates_without_reference
            && $referenceGaps === $validation->reference_dates_without_candidate
            && hash_equals($validation->comparison_sha256, $checksum);

        return [
            'valid' => $valid,
            'reason' => $valid
                ? 'The persisted external comparison is intact.'
                : 'The persisted external comparison rows, coverage or checksum is inconsistent.',
            'checksum' => $checksum,
        ];
    }
}

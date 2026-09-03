<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuExternalBenchmarkComparisonResult
{
    /**
     * @param  list<array<string, ?string>>  $differences
     * @param  list<array{reference_date:string,gap_type:string}>  $gaps
     */
    public function __construct(
        public string $action,
        public string $reason,
        public ?int $candidateVersionId = null,
        public ?int $benchmarkId = null,
        public ?int $externalValidationId = null,
        public ?string $candidateChecksum = null,
        public ?string $benchmarkDatasetSha256 = null,
        public ?string $comparisonAlgorithmVersion = null,
        public ?string $comparisonSha256 = null,
        public ?string $coverageStatus = null,
        public int $comparedRows = 0,
        public int $candidateDatesWithoutReference = 0,
        public int $referenceDatesWithoutCandidate = 0,
        public array $differences = [],
        public array $gaps = [],
        public ?string $maximumAbsoluteDifference = null,
        public ?string $maximumRelativeDifference = null,
        public ?string $tolerancePolicy = null,
        public int $writes = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'candidate_version_id' => $this->candidateVersionId,
            'benchmark_id' => $this->benchmarkId,
            'external_validation_id' => $this->externalValidationId,
            'candidate_checksum' => $this->candidateChecksum,
            'benchmark_dataset_sha256' => $this->benchmarkDatasetSha256,
            'comparison_algorithm_version' => $this->comparisonAlgorithmVersion,
            'comparison_sha256' => $this->comparisonSha256,
            'coverage_status' => $this->coverageStatus,
            'compared_rows' => $this->comparedRows,
            'candidate_dates_without_reference' => $this->candidateDatesWithoutReference,
            'reference_dates_without_candidate' => $this->referenceDatesWithoutCandidate,
            'differences' => $this->differences,
            'gaps' => $this->gaps,
            'maximum_absolute_difference' => $this->maximumAbsoluteDifference,
            'maximum_relative_difference' => $this->maximumRelativeDifference,
            'tolerance_policy' => $this->tolerancePolicy,
            'writes' => $this->writes,
        ];
    }
}

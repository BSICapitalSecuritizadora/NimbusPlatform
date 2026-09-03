<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuExternalBenchmarkImportResult
{
    /**
     * @param  list<string>  $candidateDatesWithoutReference
     * @param  list<string>  $referenceDatesWithoutCandidate
     */
    public function __construct(
        public string $action,
        public string $reason,
        public ?int $candidateVersionId = null,
        public ?int $benchmarkId = null,
        public ?string $sourceType = null,
        public ?string $sourceName = null,
        public ?string $referenceAsOf = null,
        public ?int $sourceEvidenceId = null,
        public ?int $sourceDocumentId = null,
        public ?PuExternalBenchmarkDataset $dataset = null,
        public ?string $coveragePreview = null,
        public array $candidateDatesWithoutReference = [],
        public array $referenceDatesWithoutCandidate = [],
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
            'source_type' => $this->sourceType,
            'source_name' => $this->sourceName,
            'reference_as_of' => $this->referenceAsOf,
            'source_evidence_id' => $this->sourceEvidenceId,
            'source_document_id' => $this->sourceDocumentId,
            'dataset' => $this->dataset?->toArray(),
            'coverage_preview' => $this->coveragePreview,
            'candidate_dates_without_reference' => $this->candidateDatesWithoutReference,
            'reference_dates_without_candidate' => $this->referenceDatesWithoutCandidate,
            'writes' => $this->writes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCandidateExternalValidationResult
{
    public function __construct(
        public string $action,
        public string $reason,
        public ?int $externalValidationId = null,
        public ?int $candidateVersionId = null,
        public ?int $benchmarkId = null,
        public ?string $decision = null,
        public ?string $externalValidationStatus = null,
        public ?int $reviewerId = null,
        public int $writes = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'external_validation_id' => $this->externalValidationId,
            'candidate_version_id' => $this->candidateVersionId,
            'benchmark_id' => $this->benchmarkId,
            'decision' => $this->decision,
            'external_validation_status' => $this->externalValidationStatus,
            'reviewer_id' => $this->reviewerId,
            'writes' => $this->writes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCandidateCurveReviewResult
{
    public function __construct(
        public string $action,
        public string $reason,
        public ?int $candidateVersionId = null,
        public ?string $calculationVersion = null,
        public ?string $curveRole = null,
        public ?string $reviewStatus = null,
        public ?string $internalValidationStatus = null,
        public ?string $externalValidationStatus = null,
        public ?int $makerId = null,
        public ?int $reviewerId = null,
        public ?string $decision = null,
        public int $writes = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'candidate_version_id' => $this->candidateVersionId,
            'calculation_version' => $this->calculationVersion,
            'curve_role' => $this->curveRole,
            'review_status' => $this->reviewStatus,
            'internal_validation_status' => $this->internalValidationStatus,
            'external_validation_status' => $this->externalValidationStatus,
            'maker_id' => $this->makerId,
            'reviewer_id' => $this->reviewerId,
            'decision' => $this->decision,
            'writes' => $this->writes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCurvePromotionResult
{
    public function __construct(
        public string $action,
        public string $reason,
        public ?PuCurvePromotionPlan $plan = null,
        public ?int $promotionId = null,
        public ?string $promotionStatus = null,
        public ?int $candidateVersionId = null,
        public ?string $calculationVersion = null,
        public ?string $curveRole = null,
        public ?int $previousOperationalVersionId = null,
        public ?int $newOperationalVersionId = null,
        public ?int $externalValidationId = null,
        public ?int $requesterId = null,
        public ?int $reviewerId = null,
        public ?int $executorId = null,
        public ?string $decision = null,
        public int $writes = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'plan' => $this->plan?->toArray(),
            'promotion_id' => $this->promotionId,
            'promotion_status' => $this->promotionStatus,
            'candidate_version_id' => $this->candidateVersionId,
            'calculation_version' => $this->calculationVersion,
            'curve_role' => $this->curveRole,
            'previous_operational_version_id' => $this->previousOperationalVersionId,
            'new_operational_version_id' => $this->newOperationalVersionId,
            'external_validation_id' => $this->externalValidationId,
            'requester_id' => $this->requesterId,
            'reviewer_id' => $this->reviewerId,
            'executor_id' => $this->executorId,
            'decision' => $this->decision,
            'writes' => $this->writes,
        ];
    }
}

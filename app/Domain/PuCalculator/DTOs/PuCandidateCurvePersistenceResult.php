<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCandidateCurvePersistenceResult
{
    public function __construct(
        public string $action,
        public string $reason,
        public PuCandidateCurvePersistencePlan $plan,
        public ?int $candidateVersionId = null,
        public ?string $calculationVersion = null,
        public ?int $actorId = null,
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
            'actor_id' => $this->actorId,
            'writes' => $this->writes,
            'plan' => $this->plan->toArray(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuNumericHomologationResult
{
    public function __construct(
        public string $action,
        public string $reason,
        public PuNumericHomologationPlan $plan,
        public ?PuCandidateCurve $candidate,
        public ?PuNumericHomologationValidationResult $validation,
        public ?PuNumericHomologationComparisonResult $comparison,
        public int $writes = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'plan' => $this->plan->toArray(),
            'candidate' => $this->candidate?->summary(),
            'validation' => $this->validation?->toArray(),
            'comparison' => $this->comparison?->toArray(),
            'writes' => $this->writes,
        ];
    }
}

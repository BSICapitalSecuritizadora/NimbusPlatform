<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuNumericPreparationResult
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $action,
        public string $reason,
        public int $writes,
        public PuNumericPreparationPlan $plan,
        public ?int $actorId = null,
        public array $details = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'writes' => $this->writes,
            'actor_id' => $this->actorId,
            'details' => $this->details,
            'plan' => $this->plan->toArray(),
        ];
    }
}

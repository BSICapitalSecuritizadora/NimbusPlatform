<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuCandidateCurvePersistencePlan
{
    /**
     * @param  list<int>  $divergentCandidateIds
     */
    public function __construct(
        public string $action,
        public string $reason,
        public PuNumericHomologationResult $homologation,
        public ?int $currentOperationalVersionId,
        public ?string $currentOperationalCalculationVersion,
        public int $candidateCount,
        public ?int $identicalCandidateId,
        public array $divergentCandidateIds,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'homologation' => $this->homologation->toArray(),
            'current_operational_version_id' => $this->currentOperationalVersionId,
            'current_operational_calculation_version' => $this->currentOperationalCalculationVersion,
            'candidate_count' => $this->candidateCount,
            'identical_candidate_id' => $this->identicalCandidateId,
            'divergent_candidate_ids' => $this->divergentCandidateIds,
        ];
    }
}

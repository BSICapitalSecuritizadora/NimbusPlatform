<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\DTOs;

final readonly class PuBaselineCandidatePersistenceResult
{
    /**
     * @param  list<string>  $pendingFields
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>|null  $existingConfiguration
     * @param  array<string, mixed>  $proposedConfiguration
     * @param  list<array{candidate_field:string,parameter_field:?string,persisted:bool,reason:string}>  $mapping
     * @param  list<array{field:string,existing:mixed,candidate:mixed}>  $diff
     * @param  list<string>  $blockingRequirements
     * @param  array<string, bool|int|null>  $financialEffects
     * @param  array<string, mixed>  $provenance
     * @param  array<string, mixed>  $futureSnapshotWindow
     */
    public function __construct(
        public int $emissionId,
        public string $readinessStatus,
        public array $pendingFields,
        public string $action,
        public string $reason,
        public array $candidate,
        public ?array $existingConfiguration,
        public array $proposedConfiguration,
        public array $mapping,
        public array $diff,
        public array $blockingRequirements,
        public array $financialEffects,
        public array $provenance,
        public array $futureSnapshotWindow,
        public string $candidateFingerprint,
        public int $writes = 0,
        public ?int $parameterId = null,
        public ?int $actorId = null,
    ) {}

    public function withOutcome(
        string $action,
        string $reason,
        int $writes = 0,
        ?int $parameterId = null,
        ?int $actorId = null,
    ): self {
        return new self(
            emissionId: $this->emissionId,
            readinessStatus: $this->readinessStatus,
            pendingFields: $this->pendingFields,
            action: $action,
            reason: $reason,
            candidate: $this->candidate,
            existingConfiguration: $this->existingConfiguration,
            proposedConfiguration: $this->proposedConfiguration,
            mapping: $this->mapping,
            diff: $this->diff,
            blockingRequirements: $this->blockingRequirements,
            financialEffects: $this->financialEffects,
            provenance: $this->provenance,
            futureSnapshotWindow: $this->futureSnapshotWindow,
            candidateFingerprint: $this->candidateFingerprint,
            writes: $writes,
            parameterId: $parameterId ?? $this->parameterId,
            actorId: $actorId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'emission_id' => $this->emissionId,
            'readiness_status' => $this->readinessStatus,
            'pending_fields' => $this->pendingFields,
            'action' => $this->action,
            'reason' => $this->reason,
            'candidate' => $this->candidate,
            'existing_configuration' => $this->existingConfiguration,
            'proposed_configuration' => $this->proposedConfiguration,
            'mapping' => $this->mapping,
            'diff' => $this->diff,
            'blocking_requirements' => $this->blockingRequirements,
            'financial_effects' => $this->financialEffects,
            'provenance' => $this->provenance,
            'future_snapshot_window' => $this->futureSnapshotWindow,
            'candidate_fingerprint' => $this->candidateFingerprint,
            'writes' => $this->writes,
            'parameter_id' => $this->parameterId,
            'actor_id' => $this->actorId,
        ];
    }
}

<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementCycleEventType;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementHistorySourceType;
use App\Enums\MeasurementResponsibility;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class MeasurementCycleEvent implements Arrayable
{
    /**
     * @param  array{type: ?string, operation_id: ?int, stage: ?int, responsibility: ?string}|null  $delegationScope
     * @param  list<int>  $paymentIds
     * @param  list<string>  $missingReasons
     */
    public function __construct(
        public ?int $sourceActivityId,
        public ?string $sourceEvent,
        public MeasurementHistorySourceType $sourceType,
        public int $measurementId,
        public ?int $operationId,
        public ?CarbonImmutable $occurredAt,
        public MeasurementCycleEventType $eventType,
        public ?int $stageBefore,
        public ?int $stageAfter,
        public ?string $statusBefore,
        public ?string $statusAfter,
        public ?int $actorId,
        public ?MeasurementResponsibility $responsibility,
        public ?int $expectedResponsibleId,
        public ?bool $delegated,
        public ?int $delegationId,
        public ?int $delegatorId,
        public ?array $delegationScope,
        public ?bool $adminOverride,
        public ?int $workflowRevision,
        public ?string $reason,
        public array $paymentIds,
        public ?string $paymentAmount,
        public MeasurementHistoryCompleteness $completeness,
        public array $missingReasons,
    ) {}

    public function withMissingReason(
        string $reason,
        MeasurementHistoryCompleteness $completeness = MeasurementHistoryCompleteness::Partial,
    ): self {
        return new self(
            sourceActivityId: $this->sourceActivityId,
            sourceEvent: $this->sourceEvent,
            sourceType: $this->sourceType,
            measurementId: $this->measurementId,
            operationId: $this->operationId,
            occurredAt: $this->occurredAt,
            eventType: $this->eventType,
            stageBefore: $this->stageBefore,
            stageAfter: $this->stageAfter,
            statusBefore: $this->statusBefore,
            statusAfter: $this->statusAfter,
            actorId: $this->actorId,
            responsibility: $this->responsibility,
            expectedResponsibleId: $this->expectedResponsibleId,
            delegated: $this->delegated,
            delegationId: $this->delegationId,
            delegatorId: $this->delegatorId,
            delegationScope: $this->delegationScope,
            adminOverride: $this->adminOverride,
            workflowRevision: $this->workflowRevision,
            reason: $this->reason,
            paymentIds: $this->paymentIds,
            paymentAmount: $this->paymentAmount,
            completeness: $this->completeness->combine($completeness),
            missingReasons: array_values(array_unique([...$this->missingReasons, $reason])),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_activity_id' => $this->sourceActivityId,
            'source_event' => $this->sourceEvent,
            'source_type' => $this->sourceType->value,
            'measurement_id' => $this->measurementId,
            'operation_id' => $this->operationId,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'event_type' => $this->eventType->value,
            'stage_before' => $this->stageBefore,
            'stage_after' => $this->stageAfter,
            'status_before' => $this->statusBefore,
            'status_after' => $this->statusAfter,
            'actor_id' => $this->actorId,
            'responsibility' => $this->responsibility?->value,
            'expected_responsible_id' => $this->expectedResponsibleId,
            'delegated' => $this->delegated,
            'delegation_id' => $this->delegationId,
            'delegator_id' => $this->delegatorId,
            'delegation_scope' => $this->delegationScope,
            'admin_override' => $this->adminOverride,
            'workflow_revision' => $this->workflowRevision,
            'reason' => $this->reason,
            'payment_ids' => $this->paymentIds,
            'payment_amount' => $this->paymentAmount,
            'completeness' => $this->completeness->value,
            'missing_reasons' => $this->missingReasons,
        ];
    }
}

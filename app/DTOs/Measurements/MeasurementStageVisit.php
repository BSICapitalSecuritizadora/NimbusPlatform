<?php

namespace App\DTOs\Measurements;

use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/** @implements Arrayable<string, mixed> */
final readonly class MeasurementStageVisit implements Arrayable
{
    /**
     * @param  array{type: ?string, operation_id: ?int, stage: ?int, responsibility: ?string}|null  $delegationScope
     * @param  list<string>  $missingReasons
     */
    public function __construct(
        public int $measurementId,
        public int $stage,
        public int $sequence,
        public ?CarbonImmutable $enteredAt,
        public ?CarbonImmutable $exitedAt,
        public MeasurementStageExitReason $exitReason,
        public ?MeasurementCycleEvent $decisionEvent,
        public ?int $exitActorId,
        public ?MeasurementResponsibility $responsibility,
        public ?int $expectedResponsibleId,
        public ?bool $delegated,
        public ?int $delegationId,
        public ?int $delegatorId,
        public ?array $delegationScope,
        public ?bool $adminOverride,
        public ?int $revisionStart,
        public ?int $revisionEnd,
        public ?int $calendarDuration,
        public ?int $pausedDuration,
        public ?int $activeDuration,
        public MeasurementHistoryCompleteness $completeness,
        public array $missingReasons,
    ) {}

    public function withFallbackExit(
        CarbonImmutable $exitedAt,
        MeasurementStageExitReason $exitReason,
        ?int $exitActorId,
        string $missingReason,
    ): self {
        return new self(
            measurementId: $this->measurementId,
            stage: $this->stage,
            sequence: $this->sequence,
            enteredAt: $this->enteredAt,
            exitedAt: $exitedAt,
            exitReason: $exitReason,
            decisionEvent: null,
            exitActorId: $exitActorId,
            responsibility: $this->responsibility,
            expectedResponsibleId: null,
            delegated: null,
            delegationId: null,
            delegatorId: null,
            delegationScope: null,
            adminOverride: null,
            revisionStart: $this->revisionStart,
            revisionEnd: null,
            calendarDuration: null,
            pausedDuration: null,
            activeDuration: null,
            completeness: $this->completeness->combine(MeasurementHistoryCompleteness::Partial),
            missingReasons: array_values(array_unique([...$this->missingReasons, $missingReason])),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'measurement_id' => $this->measurementId,
            'stage' => $this->stage,
            'sequence' => $this->sequence,
            'entered_at' => $this->enteredAt?->toIso8601String(),
            'exited_at' => $this->exitedAt?->toIso8601String(),
            'exit_reason' => $this->exitReason->value,
            'decision_event' => $this->decisionEvent?->toArray(),
            'exit_actor_id' => $this->exitActorId,
            'responsibility' => $this->responsibility?->value,
            'expected_responsible_id' => $this->expectedResponsibleId,
            'delegated' => $this->delegated,
            'delegation_id' => $this->delegationId,
            'delegator_id' => $this->delegatorId,
            'delegation_scope' => $this->delegationScope,
            'admin_override' => $this->adminOverride,
            'revision_start' => $this->revisionStart,
            'revision_end' => $this->revisionEnd,
            'calendar_duration' => $this->calendarDuration,
            'paused_duration' => $this->pausedDuration,
            'active_duration' => $this->activeDuration,
            'completeness' => $this->completeness->value,
            'missing_reasons' => $this->missingReasons,
        ];
    }
}

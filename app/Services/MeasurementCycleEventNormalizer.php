<?php

namespace App\Services;

use App\DTOs\Measurements\MeasurementCycleEvent;
use App\Enums\MeasurementCycleEventType;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementHistorySourceType;
use App\Enums\MeasurementResponsibility;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementReview;
use App\Models\User;
use App\Support\ActivityLog\ActivityPropertyReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class MeasurementCycleEventNormalizer
{
    private const WORKFLOW_LOG = 'measurement_workflow';

    private const DEDUPLICATION_WINDOW_SECONDS = 5;

    /**
     * @param  Collection<int, Activity>  $activities
     * @return Collection<int, MeasurementCycleEvent>
     */
    public function normalizeMany(Collection $activities, ?Measurement $measurement = null): Collection
    {
        $normalized = $activities
            ->map(fn (Activity $activity): ?MeasurementCycleEvent => $this->normalize($activity, $measurement))
            ->filter(fn (?MeasurementCycleEvent $event): bool => $event instanceof MeasurementCycleEvent)
            ->values();

        $normalized = $this->correlateReviewActors($normalized, $measurement);

        return $this->deduplicateModelActivities($normalized)
            ->sort(fn (MeasurementCycleEvent $left, MeasurementCycleEvent $right): int => $this->compareEvents($left, $right))
            ->values();
    }

    public function normalize(Activity $activity, ?Measurement $measurement = null): ?MeasurementCycleEvent
    {
        if ($activity->log_name === self::WORKFLOW_LOG) {
            return $this->normalizeWorkflowActivity($activity, $measurement);
        }

        if ($activity->subject_type === (new Measurement)->getMorphClass()) {
            return $this->normalizeModelActivity($activity, $measurement);
        }

        return null;
    }

    private function normalizeWorkflowActivity(
        Activity $activity,
        ?Measurement $measurement,
    ): ?MeasurementCycleEvent {
        $eventType = $this->workflowEventType($activity->description);

        if (! $eventType instanceof MeasurementCycleEventType) {
            return null;
        }

        $properties = new ActivityPropertyReader($activity->properties);
        $missingReasons = [];
        $completeness = MeasurementHistoryCompleteness::Complete;
        $propertyMeasurementId = $properties->nullableInt('measurement_id');
        $subjectMeasurementId = $activity->subject_id !== null ? (int) $activity->subject_id : null;
        $measurementId = $propertyMeasurementId ?? $subjectMeasurementId;

        if ($measurementId === null) {
            return null;
        }

        if ($propertyMeasurementId !== null
            && $subjectMeasurementId !== null
            && $propertyMeasurementId !== $subjectMeasurementId) {
            $this->degrade(
                $missingReasons,
                $completeness,
                'measurement_id_mismatch',
                MeasurementHistoryCompleteness::Insufficient,
            );
        }

        $occurredAt = $this->immutableDate($activity->created_at);

        if (! $occurredAt instanceof CarbonImmutable) {
            $this->degrade(
                $missingReasons,
                $completeness,
                'event_time_missing',
                MeasurementHistoryCompleteness::Insufficient,
            );
        }

        $stage = $properties->nullableInt('stage');
        $statusBefore = $properties->nullableString('from_status');
        $statusAfter = $properties->nullableString('to_status');
        [$stageBefore, $stageAfter] = $this->workflowStages(
            $eventType,
            $stage,
            $statusBefore,
            $statusAfter,
            $properties,
            $missingReasons,
            $completeness,
        );

        if ($this->requiresStatusTransition($eventType)
            && ($statusBefore === null || $statusAfter === null)) {
            $this->degrade($missingReasons, $completeness, 'status_transition_incomplete');
        }

        $responsibility = $this->responsibility(
            $properties->nullableString('responsibility'),
            $missingReasons,
            $completeness,
        );

        if ($this->requiresResponsibility($eventType)
            && ! $responsibility instanceof MeasurementResponsibility) {
            $this->degrade($missingReasons, $completeness, 'responsibility_unknown');
        }

        $expectedResponsibleId = $properties->nullableInt('expected_responsible_user_id');

        if ($this->requiresExpectedResponsible($eventType) && $expectedResponsibleId === null) {
            $this->degrade($missingReasons, $completeness, 'expected_responsible_unknown');
        }

        $actorId = $this->actorId(
            $activity,
            $properties,
            $eventType,
            $stageBefore,
            $measurement,
            $missingReasons,
            $completeness,
        );

        if ($actorId === null) {
            $this->degrade($missingReasons, $completeness, 'actor_unknown');
        }

        $delegated = $properties->nullableBool('delegated');
        $delegationId = $properties->nullableInt('delegation_id');
        $delegatorId = $properties->nullableInt('delegator_user_id');
        $delegationScope = $this->delegationScope($properties, $missingReasons, $completeness);

        if (! $properties->has('delegated') || $delegated === null) {
            $this->degrade($missingReasons, $completeness, 'delegation_metadata_unknown');
        } elseif ($delegated === true
            && ($delegationId === null || $delegatorId === null || $delegationScope === null)) {
            $this->degrade($missingReasons, $completeness, 'delegation_snapshot_incomplete');
        }

        $adminOverride = $properties->nullableBool('admin_override');

        if (! $properties->has('admin_override') || $adminOverride === null) {
            $this->degrade($missingReasons, $completeness, 'admin_override_unknown');
        }

        $workflowRevision = $properties->nullableInt('workflow_revision');

        if (! $properties->has('workflow_revision') || $workflowRevision === null) {
            $this->degrade($missingReasons, $completeness, 'workflow_revision_unknown');
        }

        $paymentIds = $properties->intList('payment_ids');
        $singlePaymentId = $properties->nullableInt('payment_id');
        $operationId = $properties->nullableInt('operation_id');
        $paymentAmount = $properties->nullableDecimal('amount');

        if ($operationId === null) {
            $this->degrade($missingReasons, $completeness, 'operation_id_unknown');
        } elseif ($measurement instanceof Measurement
            && (int) $measurement->operation_id !== $operationId) {
            $this->degrade(
                $missingReasons,
                $completeness,
                'operation_id_mismatch',
                MeasurementHistoryCompleteness::Insufficient,
            );
        }

        if ($singlePaymentId !== null) {
            $paymentIds = array_values(array_unique([...$paymentIds, $singlePaymentId]));
        }

        if (in_array($eventType, [
            MeasurementCycleEventType::PaymentRegistered,
            MeasurementCycleEventType::ReceiptAttached,
            MeasurementCycleEventType::ReceiptDeleted,
        ], true) && $paymentIds === []) {
            $this->degrade(
                $missingReasons,
                $completeness,
                'payment_reference_missing',
                MeasurementHistoryCompleteness::Insufficient,
            );
        }

        $reason = $properties->nullableString('notes') ?? $properties->nullableString('reason');

        if (in_array($eventType, [
            MeasurementCycleEventType::StageRejected,
            MeasurementCycleEventType::FinalizationReturned,
            MeasurementCycleEventType::StagePaused,
        ], true) && $reason === null) {
            $this->degrade($missingReasons, $completeness, 'reason_missing');
        }

        foreach ($properties->issues() as $issue) {
            $this->degrade($missingReasons, $completeness, $issue);
        }

        return new MeasurementCycleEvent(
            sourceActivityId: $activity->getKey() !== null ? (int) $activity->getKey() : null,
            sourceEvent: $activity->description,
            sourceType: MeasurementHistorySourceType::WorkflowActivity,
            measurementId: $measurementId,
            operationId: $operationId,
            occurredAt: $occurredAt,
            eventType: $eventType,
            stageBefore: $stageBefore,
            stageAfter: $stageAfter,
            statusBefore: $statusBefore,
            statusAfter: $statusAfter,
            actorId: $actorId,
            responsibility: $responsibility,
            expectedResponsibleId: $expectedResponsibleId,
            delegated: $delegated,
            delegationId: $delegationId,
            delegatorId: $delegatorId,
            delegationScope: $delegationScope,
            adminOverride: $adminOverride,
            workflowRevision: $workflowRevision,
            reason: $reason,
            paymentIds: $paymentIds,
            paymentAmount: $paymentAmount,
            completeness: $completeness,
            missingReasons: array_values(array_unique($missingReasons)),
        );
    }

    private function normalizeModelActivity(
        Activity $activity,
        ?Measurement $measurement,
    ): ?MeasurementCycleEvent {
        $properties = new ActivityPropertyReader($activity->properties);
        $attributes = $properties->nested('attributes');
        $old = $properties->nested('old');
        $eventType = null;
        $stageBefore = $old->nullableInt('current_stage');
        $stageAfter = $attributes->nullableInt('current_stage') ?? $stageBefore;
        $statusBefore = $old->nullableString('status');
        $statusAfter = $attributes->nullableString('status') ?? $statusBefore;
        $eventName = $activity->event ?? $activity->description;

        if ($eventName === 'created') {
            $eventType = MeasurementCycleEventType::MeasurementCreated;
            $stageBefore = null;
        } elseif ($eventName === 'updated') {
            $transition = $this->legacyTransition(
                $stageBefore,
                $stageAfter,
                $statusBefore,
                $statusAfter,
            );

            if ($transition !== null) {
                [$eventType, $stageBefore, $stageAfter] = $transition;
            }
        }

        if (! $eventType instanceof MeasurementCycleEventType) {
            return null;
        }

        $measurementId = $activity->subject_id !== null
            ? (int) $activity->subject_id
            : ($measurement?->getKey() !== null ? (int) $measurement->getKey() : null);

        if ($measurementId === null) {
            return null;
        }

        $missingReasons = [];
        $completeness = $eventType === MeasurementCycleEventType::MeasurementCreated
            ? MeasurementHistoryCompleteness::Complete
            : MeasurementHistoryCompleteness::Partial;

        if (! $activity->created_at) {
            $this->degrade(
                $missingReasons,
                $completeness,
                'event_time_missing',
                MeasurementHistoryCompleteness::Insufficient,
            );
        }

        if ($eventType !== MeasurementCycleEventType::MeasurementCreated) {
            $missingReasons[] = 'legacy_model_activity_fallback';
        }

        foreach ([...$properties->issues(), ...$attributes->issues(), ...$old->issues()] as $issue) {
            $this->degrade($missingReasons, $completeness, $issue);
        }

        $actorId = $this->causerUserId($activity);
        $workflowRevision = $attributes->nullableInt('workflow_revision');

        foreach ($attributes->issues() as $issue) {
            $this->degrade($missingReasons, $completeness, $issue);
        }

        if ($actorId === null && $eventType !== MeasurementCycleEventType::MeasurementCreated) {
            $this->degrade($missingReasons, $completeness, 'actor_unknown');
        }

        return new MeasurementCycleEvent(
            sourceActivityId: $activity->getKey() !== null ? (int) $activity->getKey() : null,
            sourceEvent: $activity->description,
            sourceType: MeasurementHistorySourceType::ModelActivity,
            measurementId: $measurementId,
            operationId: $measurement?->operation_id !== null ? (int) $measurement->operation_id : null,
            occurredAt: $this->immutableDate($activity->created_at),
            eventType: $eventType,
            stageBefore: $stageBefore,
            stageAfter: $stageAfter,
            statusBefore: $statusBefore,
            statusAfter: $statusAfter,
            actorId: $actorId,
            responsibility: $stageBefore !== null
                ? MeasurementResponsibility::primaryForStage($stageBefore)
                : null,
            expectedResponsibleId: null,
            delegated: null,
            delegationId: null,
            delegatorId: null,
            delegationScope: null,
            adminOverride: null,
            workflowRevision: $workflowRevision,
            reason: null,
            paymentIds: [],
            paymentAmount: null,
            completeness: $completeness,
            missingReasons: array_values(array_unique($missingReasons)),
        );
    }

    private function workflowEventType(string $description): ?MeasurementCycleEventType
    {
        return match ($description) {
            'measurement_submitted' => MeasurementCycleEventType::Submitted,
            'measurement_stage_approved' => MeasurementCycleEventType::StageApproved,
            'measurement_stage_rejected' => MeasurementCycleEventType::StageRejected,
            'measurement_finalization_returned' => MeasurementCycleEventType::FinalizationReturned,
            'measurement_stage_paused' => MeasurementCycleEventType::StagePaused,
            'measurement_stage_resumed' => MeasurementCycleEventType::StageResumed,
            'measurement_payment_registered' => MeasurementCycleEventType::PaymentRegistered,
            'measurement_receipt_attached' => MeasurementCycleEventType::ReceiptAttached,
            'measurement_receipt_deleted' => MeasurementCycleEventType::ReceiptDeleted,
            'measurement_finalized' => MeasurementCycleEventType::Finalized,
            'measurement_engineering_snapshot_created' => MeasurementCycleEventType::EngineeringSnapshotCreated,
            'measurement_engineering_snapshot_invalidated' => MeasurementCycleEventType::EngineeringSnapshotInvalidated,
            default => null,
        };
    }

    /**
     * @param  list<string>  $missingReasons
     * @return array{0: ?int, 1: ?int}
     */
    private function workflowStages(
        MeasurementCycleEventType $eventType,
        ?int $stage,
        ?string $statusBefore,
        ?string $statusAfter,
        ActivityPropertyReader $properties,
        array &$missingReasons,
        MeasurementHistoryCompleteness &$completeness,
    ): array {
        $target = $properties->nullableInt('target_stage');

        if ($eventType === MeasurementCycleEventType::Submitted) {
            $this->validateTransitionShape(
                $stage === 1
                    && $target === null
                    && in_array($statusBefore, ['pending', 'in_review'], true)
                    && $statusAfter === 'in_review',
                $properties,
                $missingReasons,
                $completeness,
            );

            return [null, $stage];
        }

        if ($eventType === MeasurementCycleEventType::StageApproved) {
            $derivedNextStage = $stage !== null ? $stage + 1 : null;
            $valid = match ($stage) {
                1, 2 => in_array($statusBefore, ['pending', 'in_review'], true)
                    && $statusAfter === 'in_review',
                3 => in_array($statusBefore, ['pending', 'in_review'], true)
                    && $statusAfter === 'awaiting_payment',
                4 => $statusBefore === 'awaiting_payment'
                    && in_array($statusAfter, ['awaiting_receipt', 'approved'], true),
                default => false,
            };

            $this->validateTransitionShape(
                $valid && ($target === null || $target === $derivedNextStage),
                $properties,
                $missingReasons,
                $completeness,
            );

            return [$stage, $derivedNextStage];
        }

        if ($eventType === MeasurementCycleEventType::StageRejected) {
            $valid = match ($stage) {
                1 => $target === null
                    && in_array($statusBefore, ['pending', 'in_review'], true)
                    && $statusAfter === 'rejected',
                2 => $target === 1
                    && in_array($statusBefore, ['pending', 'in_review'], true)
                    && $statusAfter === 'in_review',
                3 => $target === 2
                    && in_array($statusBefore, ['pending', 'in_review'], true)
                    && $statusAfter === 'in_review',
                4 => $target === 3
                    && $statusBefore === 'awaiting_payment'
                    && $statusAfter === 'in_review',
                default => false,
            };

            $this->validateTransitionShape(
                $valid,
                $properties,
                $missingReasons,
                $completeness,
            );

            return [$stage, $stage === 1 ? null : $target];
        }

        if ($eventType === MeasurementCycleEventType::FinalizationReturned) {
            $expectedStatus = $target === 4 ? 'awaiting_payment' : 'in_review';

            $this->validateTransitionShape(
                $stage === 5
                    && in_array($target, [1, 2, 3, 4], true)
                    && in_array($statusBefore, ['awaiting_receipt', 'approved'], true)
                    && $statusAfter === $expectedStatus,
                $properties,
                $missingReasons,
                $completeness,
            );

            return [$stage, $target];
        }

        if ($eventType === MeasurementCycleEventType::Finalized) {
            $this->validateTransitionShape(
                $stage === 5
                    && $target === null
                    && $statusBefore === 'approved'
                    && $statusAfter === 'finalized',
                $properties,
                $missingReasons,
                $completeness,
            );

            return [$stage, null];
        }

        if ($eventType === MeasurementCycleEventType::PaymentRegistered) {
            $this->validateTransitionShape(
                $stage === 4
                    && $target === null
                    && $statusBefore === 'awaiting_payment'
                    && $statusAfter === 'awaiting_payment',
                $properties,
                $missingReasons,
                $completeness,
            );

            return [$stage, $stage];
        }

        if ($eventType === MeasurementCycleEventType::ReceiptAttached) {
            $this->validateTransitionShape(
                $stage === 5
                    && $target === null
                    && in_array([$statusBefore, $statusAfter], [
                        ['awaiting_receipt', 'awaiting_receipt'],
                        ['awaiting_receipt', 'approved'],
                        ['approved', 'approved'],
                    ], true),
                $properties,
                $missingReasons,
                $completeness,
            );

            return [$stage, $stage];
        }

        if ($eventType === MeasurementCycleEventType::ReceiptDeleted) {
            $this->validateTransitionShape(
                $stage === 5
                    && $target === null
                    && in_array([$statusBefore, $statusAfter], [
                        ['awaiting_receipt', 'awaiting_receipt'],
                        ['approved', 'awaiting_receipt'],
                    ], true),
                $properties,
                $missingReasons,
                $completeness,
            );

            return [$stage, $stage];
        }

        if ($eventType === MeasurementCycleEventType::StagePaused) {
            $this->validateTransitionShape(
                $target === null && match ($stage) {
                    1, 2, 3 => in_array($statusBefore, ['pending', 'in_review'], true)
                        && $statusAfter === 'paused',
                    4 => $statusBefore === 'awaiting_payment' && $statusAfter === 'paused',
                    default => false,
                },
                $properties,
                $missingReasons,
                $completeness,
            );

            return [$stage, $stage];
        }

        if ($eventType === MeasurementCycleEventType::StageResumed) {
            $this->validateTransitionShape(
                $target === null && match ($stage) {
                    1, 2, 3 => $statusBefore === 'paused' && $statusAfter === 'in_review',
                    4 => $statusBefore === 'paused' && $statusAfter === 'awaiting_payment',
                    default => false,
                },
                $properties,
                $missingReasons,
                $completeness,
            );

            return [$stage, $stage];
        }

        return [null, null];
    }

    /** @param list<string> $missingReasons */
    private function validateTransitionShape(
        bool $valid,
        ActivityPropertyReader $properties,
        array &$missingReasons,
        MeasurementHistoryCompleteness &$completeness,
    ): void {
        $criticalPropertyIssues = [
            'invalid_property_shape:stage',
            'invalid_property_shape:target_stage',
            'invalid_property_shape:from_status',
            'invalid_property_shape:to_status',
        ];

        if ($valid && array_intersect($properties->issues(), $criticalPropertyIssues) === []) {
            return;
        }

        $this->degrade(
            $missingReasons,
            $completeness,
            'invalid_transition_shape',
            MeasurementHistoryCompleteness::Insufficient,
        );
    }

    /**
     * @return array{0: MeasurementCycleEventType, 1: ?int, 2: ?int}|null
     */
    private function legacyTransition(
        ?int $stageBefore,
        ?int $stageAfter,
        ?string $statusBefore,
        ?string $statusAfter,
    ): ?array {
        return match ([$stageBefore, $stageAfter, $statusBefore, $statusAfter]) {
            [1, 1, 'pending', 'in_review'] => [MeasurementCycleEventType::Submitted, null, 1],
            [1, 2, 'pending', 'in_review'],
            [1, 2, 'in_review', 'in_review'],
            [2, 3, 'pending', 'in_review'],
            [2, 3, 'in_review', 'in_review'],
            [3, 4, 'pending', 'awaiting_payment'],
            [3, 4, 'in_review', 'awaiting_payment'],
            [4, 5, 'awaiting_payment', 'awaiting_receipt'],
            [4, 5, 'awaiting_payment', 'approved'] => [
                MeasurementCycleEventType::StageApproved,
                $stageBefore,
                $stageAfter,
            ],
            [1, 1, 'pending', 'rejected'],
            [1, 1, 'in_review', 'rejected'] => [MeasurementCycleEventType::StageRejected, 1, null],
            [2, 1, 'pending', 'in_review'],
            [2, 1, 'in_review', 'in_review'],
            [3, 2, 'pending', 'in_review'],
            [3, 2, 'in_review', 'in_review'],
            [4, 3, 'awaiting_payment', 'in_review'] => [
                MeasurementCycleEventType::StageRejected,
                $stageBefore,
                $stageAfter,
            ],
            [5, 1, 'awaiting_receipt', 'in_review'],
            [5, 2, 'awaiting_receipt', 'in_review'],
            [5, 3, 'awaiting_receipt', 'in_review'],
            [5, 4, 'awaiting_receipt', 'awaiting_payment'],
            [5, 1, 'approved', 'in_review'],
            [5, 2, 'approved', 'in_review'],
            [5, 3, 'approved', 'in_review'],
            [5, 4, 'approved', 'awaiting_payment'] => [
                MeasurementCycleEventType::FinalizationReturned,
                5,
                $stageAfter,
            ],
            [5, 5, 'approved', 'finalized'] => [MeasurementCycleEventType::Finalized, 5, null],
            default => null,
        };
    }

    /** @param list<string> $missingReasons */
    private function responsibility(
        ?string $value,
        array &$missingReasons,
        MeasurementHistoryCompleteness &$completeness,
    ): ?MeasurementResponsibility {
        if ($value === null) {
            return null;
        }

        $responsibility = MeasurementResponsibility::fromOperationColumn($value)
            ?? MeasurementResponsibility::tryFrom($value);

        if (! $responsibility instanceof MeasurementResponsibility) {
            $this->degrade($missingReasons, $completeness, 'responsibility_shape_unknown');
        }

        return $responsibility;
    }

    /**
     * @param  list<string>  $missingReasons
     * @return array{type: ?string, operation_id: ?int, stage: ?int, responsibility: ?string}|null
     */
    private function delegationScope(
        ActivityPropertyReader $properties,
        array &$missingReasons,
        MeasurementHistoryCompleteness &$completeness,
    ): ?array {
        if (! $properties->has('delegation_scope')) {
            return null;
        }

        $scope = $properties->nested('delegation_scope');
        $sanitized = [
            'type' => $scope->nullableString('type'),
            'operation_id' => $scope->nullableInt('operation_id'),
            'stage' => $scope->nullableInt('stage'),
            'responsibility' => $scope->nullableString('responsibility'),
        ];

        foreach ($scope->issues() as $issue) {
            $this->degrade($missingReasons, $completeness, 'delegation_scope_'.$issue);
        }

        return array_filter($sanitized, fn (mixed $value): bool => $value !== null) === []
            ? null
            : $sanitized;
    }

    /** @param list<string> $missingReasons */
    private function actorId(
        Activity $activity,
        ActivityPropertyReader $properties,
        MeasurementCycleEventType $eventType,
        ?int $stage,
        ?Measurement $measurement,
        array &$missingReasons,
        MeasurementHistoryCompleteness &$completeness,
    ): ?int {
        $actualActorId = $properties->nullableInt('actual_actor_user_id');
        $causerId = $this->causerUserId($activity);

        if ($actualActorId !== null && $causerId !== null && $actualActorId !== $causerId) {
            $this->degrade($missingReasons, $completeness, 'actual_actor_causer_mismatch');
        }

        if ($actualActorId !== null) {
            return $actualActorId;
        }

        if ($causerId !== null) {
            return $causerId;
        }

        $fallback = $this->durableActorFallback($activity, $eventType, $stage, $properties, $measurement);

        if ($fallback !== null) {
            $this->degrade($missingReasons, $completeness, 'actor_from_durable_fallback');
        }

        return $fallback;
    }

    private function causerUserId(Activity $activity): ?int
    {
        if ($activity->causer_id === null
            || $activity->causer_type !== (new User)->getMorphClass()) {
            return null;
        }

        return (int) $activity->causer_id;
    }

    private function durableActorFallback(
        Activity $activity,
        MeasurementCycleEventType $eventType,
        ?int $stage,
        ActivityPropertyReader $properties,
        ?Measurement $measurement,
    ): ?int {
        if (! $measurement instanceof Measurement || ! $activity->created_at) {
            return null;
        }

        if ($eventType === MeasurementCycleEventType::PaymentRegistered
            && $stage === 4
            && $properties->nullableString('from_status') === 'awaiting_payment'
            && $properties->nullableString('to_status') === 'awaiting_payment'
            && $measurement->relationLoaded('payments')) {
            $paymentIds = $properties->intList('payment_ids');
            $actors = $measurement->payments
                ->whereIn('id', $paymentIds)
                ->filter(fn (MeasurementPayment $payment): bool => $payment->created_by !== null
                    && $this->timestampsEqual($payment->created_at, $activity->created_at))
                ->pluck('created_by')
                ->unique()
                ->values();

            if ($paymentIds !== [] && $actors->count() === 1) {
                return (int) $actors->first();
            }
        }

        if ($eventType === MeasurementCycleEventType::ReceiptAttached
            && $stage === 5
            && in_array([
                $properties->nullableString('from_status'),
                $properties->nullableString('to_status'),
            ], [
                ['awaiting_receipt', 'awaiting_receipt'],
                ['awaiting_receipt', 'approved'],
                ['approved', 'approved'],
            ], true)
            && $measurement->relationLoaded('payments')) {
            $paymentId = $properties->nullableInt('payment_id');
            $payment = $paymentId !== null ? $measurement->payments->firstWhere('id', $paymentId) : null;

            if ($payment instanceof MeasurementPayment
                && $payment->receipt_uploaded_by !== null
                && $payment->receipt_uploaded_at !== null
                && $this->timestampsEqual($payment->receipt_uploaded_at, $activity->created_at)) {
                return (int) $payment->receipt_uploaded_by;
            }
        }

        $isTerminalEvent = ($eventType === MeasurementCycleEventType::Finalized
                && $stage === 5
                && $properties->nullableString('from_status') === 'approved'
                && $properties->nullableString('to_status') === 'finalized')
            || ($eventType === MeasurementCycleEventType::StageRejected
                && $stage === 1
                && $properties->nullableInt('target_stage') === null
                && in_array($properties->nullableString('from_status'), ['pending', 'in_review'], true)
                && $properties->nullableString('to_status') === 'rejected');

        if ($isTerminalEvent
            && $measurement->analyzed_by !== null
            && $measurement->analyzed_at !== null
            && $this->timestampsEqual($measurement->analyzed_at, $activity->created_at)) {
            return (int) $measurement->analyzed_by;
        }

        return null;
    }

    private function timestampsClose(mixed $first, mixed $second): bool
    {
        $firstAt = $this->immutableDate($first);
        $secondAt = $this->immutableDate($second);

        return $firstAt instanceof CarbonImmutable
            && $secondAt instanceof CarbonImmutable
            && abs($firstAt->getTimestamp() - $secondAt->getTimestamp()) <= self::DEDUPLICATION_WINDOW_SECONDS;
    }

    private function timestampsEqual(mixed $first, mixed $second): bool
    {
        $firstAt = $this->immutableDate($first);
        $secondAt = $this->immutableDate($second);

        return $firstAt instanceof CarbonImmutable
            && $secondAt instanceof CarbonImmutable
            && $firstAt->getTimestamp() === $secondAt->getTimestamp();
    }

    /**
     * @param  Collection<int, MeasurementCycleEvent>  $events
     * @return Collection<int, MeasurementCycleEvent>
     */
    private function correlateReviewActors(Collection $events, ?Measurement $measurement): Collection
    {
        if (! $measurement instanceof Measurement || ! $measurement->relationLoaded('reviews')) {
            return $events;
        }

        return $events->map(function (MeasurementCycleEvent $event) use ($events, $measurement): MeasurementCycleEvent {
            if (! $this->canUseReviewActorFallback($event)) {
                return $event;
            }

            $review = $measurement->reviews->first(
                fn (MeasurementReview $candidate): bool => (int) $candidate->stage === $event->stageBefore,
            );

            if (! $review instanceof MeasurementReview || ! $this->reviewMatchesEvent($review, $event)) {
                return $event;
            }

            $matchingEvents = $events->filter(
                fn (MeasurementCycleEvent $candidate): bool => $this->canUseReviewActorFallback($candidate)
                    && $this->reviewMatchesEvent($review, $candidate),
            );

            if ($matchingEvents->count() !== 1
                || $this->hasLaterStageEntryEvidence($event, $events)) {
                return $event;
            }

            return $this->withReviewActor($event, (int) $review->reviewer_user_id);
        })->values();
    }

    private function canUseReviewActorFallback(MeasurementCycleEvent $event): bool
    {
        return $event->sourceType === MeasurementHistorySourceType::WorkflowActivity
            && $event->actorId === null
            && $event->stageBefore !== null
            && $event->completeness !== MeasurementHistoryCompleteness::Insufficient
            && in_array($event->eventType, [
                MeasurementCycleEventType::StageApproved,
                MeasurementCycleEventType::StageRejected,
            ], true);
    }

    private function reviewMatchesEvent(
        MeasurementReview $review,
        MeasurementCycleEvent $event,
    ): bool {
        $expectedReviewStatus = $event->eventType === MeasurementCycleEventType::StageApproved
            ? 'approved'
            : 'rejected';

        return (int) $review->stage === $event->stageBefore
            && $review->reviewer_user_id !== null
            && $review->status === $expectedReviewStatus
            && $review->reviewed_at !== null
            && $event->occurredAt instanceof CarbonImmutable
            && $this->timestampsEqual($review->reviewed_at, $event->occurredAt);
    }

    /** @param Collection<int, MeasurementCycleEvent> $events */
    private function hasLaterStageEntryEvidence(
        MeasurementCycleEvent $event,
        Collection $events,
    ): bool {
        return $events->contains(
            fn (MeasurementCycleEvent $candidate): bool => $candidate->eventType->changesStageVisit()
                && $candidate->stageAfter === $event->stageBefore
                && $this->compareEvents($candidate, $event) > 0,
        );
    }

    private function withReviewActor(MeasurementCycleEvent $event, int $actorId): MeasurementCycleEvent
    {
        $missingReasons = array_values(array_filter(
            $event->missingReasons,
            fn (string $reason): bool => $reason !== 'actor_unknown',
        ));

        return new MeasurementCycleEvent(
            sourceActivityId: $event->sourceActivityId,
            sourceEvent: $event->sourceEvent,
            sourceType: $event->sourceType,
            measurementId: $event->measurementId,
            operationId: $event->operationId,
            occurredAt: $event->occurredAt,
            eventType: $event->eventType,
            stageBefore: $event->stageBefore,
            stageAfter: $event->stageAfter,
            statusBefore: $event->statusBefore,
            statusAfter: $event->statusAfter,
            actorId: $actorId,
            responsibility: $event->responsibility,
            expectedResponsibleId: $event->expectedResponsibleId,
            delegated: $event->delegated,
            delegationId: $event->delegationId,
            delegatorId: $event->delegatorId,
            delegationScope: $event->delegationScope,
            adminOverride: $event->adminOverride,
            workflowRevision: $event->workflowRevision,
            reason: $event->reason,
            paymentIds: $event->paymentIds,
            paymentAmount: $event->paymentAmount,
            completeness: $event->completeness->combine(MeasurementHistoryCompleteness::Partial),
            missingReasons: array_values(array_unique([
                ...$missingReasons,
                'actor_from_durable_fallback',
            ])),
        );
    }

    /**
     * @param  Collection<int, MeasurementCycleEvent>  $events
     * @return Collection<int, MeasurementCycleEvent>
     */
    private function deduplicateModelActivities(Collection $events): Collection
    {
        $workflowEvents = $events
            ->filter(fn (MeasurementCycleEvent $event): bool => $event->sourceType === MeasurementHistorySourceType::WorkflowActivity
                && $event->completeness !== MeasurementHistoryCompleteness::Insufficient)
            ->sort(fn (MeasurementCycleEvent $left, MeasurementCycleEvent $right): int => $this->compareEvents($left, $right));
        $modelEvents = $events->filter(
            fn (MeasurementCycleEvent $event): bool => $event->sourceType === MeasurementHistorySourceType::ModelActivity,
        );
        $consumedModelIndexes = [];
        $ambiguousModelIndexes = [];

        foreach ($workflowEvents as $explicit) {
            $candidates = $modelEvents
                ->reject(fn (MeasurementCycleEvent $fallback, int $index): bool => isset($consumedModelIndexes[$index]))
                ->filter(fn (MeasurementCycleEvent $fallback): bool => $this->deduplicationCompatible($explicit, $fallback))
                ->map(fn (MeasurementCycleEvent $fallback): int => abs(
                    $explicit->occurredAt->getTimestamp() - $fallback->occurredAt->getTimestamp(),
                ));

            if ($candidates->isEmpty()) {
                continue;
            }

            $nearestDistance = $candidates->min();
            $nearestCandidates = $candidates->filter(
                fn (int $distance): bool => $distance === $nearestDistance,
            );

            if ($nearestCandidates->count() !== 1) {
                foreach ($nearestCandidates->keys() as $modelIndex) {
                    $ambiguousModelIndexes[$modelIndex] = true;
                }

                continue;
            }

            $consumedModelIndexes[$nearestCandidates->keys()->first()] = true;
        }

        return $events
            ->reject(fn (MeasurementCycleEvent $event, int $index): bool => $event->sourceType === MeasurementHistorySourceType::ModelActivity
                && isset($consumedModelIndexes[$index]))
            ->map(fn (MeasurementCycleEvent $event, int $index): MeasurementCycleEvent => $event->sourceType === MeasurementHistorySourceType::ModelActivity
                && isset($ambiguousModelIndexes[$index])
                    ? $event->withMissingReason('deduplication_ambiguous')
                    : $event)
            ->values();
    }

    private function deduplicationCompatible(
        MeasurementCycleEvent $explicit,
        MeasurementCycleEvent $fallback,
    ): bool {
        if (! $explicit->occurredAt instanceof CarbonImmutable
            || ! $fallback->occurredAt instanceof CarbonImmutable
            || ! $this->timestampsClose($explicit->occurredAt, $fallback->occurredAt)
            || $explicit->measurementId !== $fallback->measurementId
            || $explicit->eventType !== $fallback->eventType
            || $explicit->stageBefore !== $fallback->stageBefore
            || $explicit->stageAfter !== $fallback->stageAfter
            || $explicit->statusBefore !== $fallback->statusBefore
            || $explicit->statusAfter !== $fallback->statusAfter) {
            return false;
        }

        if ($explicit->operationId !== null
            && $fallback->operationId !== null
            && $explicit->operationId !== $fallback->operationId) {
            return false;
        }

        return $explicit->actorId === null
            || $fallback->actorId === null
            || $explicit->actorId === $fallback->actorId;
    }

    private function compareEvents(MeasurementCycleEvent $left, MeasurementCycleEvent $right): int
    {
        $timeComparison = ($left->occurredAt?->getTimestamp() ?? PHP_INT_MAX)
            <=> ($right->occurredAt?->getTimestamp() ?? PHP_INT_MAX);

        return $timeComparison !== 0
            ? $timeComparison
            : (($left->sourceActivityId ?? PHP_INT_MAX) <=> ($right->sourceActivityId ?? PHP_INT_MAX));
    }

    private function requiresStatusTransition(MeasurementCycleEventType $eventType): bool
    {
        return in_array($eventType, [
            MeasurementCycleEventType::Submitted,
            MeasurementCycleEventType::StageApproved,
            MeasurementCycleEventType::StageRejected,
            MeasurementCycleEventType::FinalizationReturned,
            MeasurementCycleEventType::StagePaused,
            MeasurementCycleEventType::StageResumed,
            MeasurementCycleEventType::PaymentRegistered,
            MeasurementCycleEventType::ReceiptAttached,
            MeasurementCycleEventType::ReceiptDeleted,
            MeasurementCycleEventType::Finalized,
        ], true);
    }

    private function requiresResponsibility(MeasurementCycleEventType $eventType): bool
    {
        return in_array($eventType, [
            MeasurementCycleEventType::Submitted,
            MeasurementCycleEventType::StageApproved,
            MeasurementCycleEventType::StageRejected,
            MeasurementCycleEventType::FinalizationReturned,
            MeasurementCycleEventType::StagePaused,
            MeasurementCycleEventType::StageResumed,
            MeasurementCycleEventType::PaymentRegistered,
            MeasurementCycleEventType::ReceiptAttached,
            MeasurementCycleEventType::ReceiptDeleted,
            MeasurementCycleEventType::Finalized,
        ], true);
    }

    private function requiresExpectedResponsible(MeasurementCycleEventType $eventType): bool
    {
        return in_array($eventType, [
            MeasurementCycleEventType::StageApproved,
            MeasurementCycleEventType::StageRejected,
            MeasurementCycleEventType::FinalizationReturned,
            MeasurementCycleEventType::StagePaused,
            MeasurementCycleEventType::StageResumed,
            MeasurementCycleEventType::PaymentRegistered,
            MeasurementCycleEventType::ReceiptAttached,
            MeasurementCycleEventType::ReceiptDeleted,
            MeasurementCycleEventType::Finalized,
        ], true);
    }

    private function immutableDate(mixed $value): ?CarbonImmutable
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::instance($value);
    }

    /** @param list<string> $missingReasons */
    private function degrade(
        array &$missingReasons,
        MeasurementHistoryCompleteness &$completeness,
        string $reason,
        MeasurementHistoryCompleteness $level = MeasurementHistoryCompleteness::Partial,
    ): void {
        $missingReasons[] = $reason;
        $missingReasons = array_values(array_unique($missingReasons));
        $completeness = $completeness->combine($level);
    }
}

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

        $workflowEvents = $normalized
            ->filter(fn (MeasurementCycleEvent $event): bool => $event->sourceType === MeasurementHistorySourceType::WorkflowActivity)
            ->values();

        return $normalized
            ->reject(fn (MeasurementCycleEvent $event): bool => $event->sourceType === MeasurementHistorySourceType::ModelActivity
                && $this->hasExplicitEquivalent($event, $workflowEvents))
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
        [$stageBefore, $stageAfter] = $this->workflowStages(
            $eventType,
            $stage,
            $properties,
            $missingReasons,
            $completeness,
        );
        $statusBefore = $properties->nullableString('from_status');
        $statusAfter = $properties->nullableString('to_status');

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
            $this->degrade($missingReasons, $completeness, 'payment_reference_missing');
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
        } elseif ($statusAfter === 'finalized' && $statusBefore !== 'finalized' && $stageAfter === 5) {
            $eventType = MeasurementCycleEventType::Finalized;
            $stageBefore = 5;
            $stageAfter = null;
        } elseif ($statusAfter === 'rejected' && $statusBefore !== 'rejected' && $stageBefore === 1) {
            $eventType = MeasurementCycleEventType::StageRejected;
            $stageAfter = null;
        } elseif ($statusAfter === 'in_review'
            && $statusBefore === 'pending'
            && $stageAfter === 1) {
            $eventType = MeasurementCycleEventType::Submitted;
            $stageBefore = null;
        } elseif ($stageBefore !== null && $stageAfter !== null && $stageAfter === $stageBefore + 1) {
            $eventType = MeasurementCycleEventType::StageApproved;
        } elseif ($stageBefore !== null && $stageAfter !== null && $stageAfter < $stageBefore) {
            $eventType = $stageBefore === 5
                ? MeasurementCycleEventType::FinalizationReturned
                : MeasurementCycleEventType::StageRejected;
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
        ActivityPropertyReader $properties,
        array &$missingReasons,
        MeasurementHistoryCompleteness &$completeness,
    ): array {
        if ($eventType === MeasurementCycleEventType::Submitted) {
            if ($stage !== 1 || $properties->nullableString('to_status') !== 'in_review') {
                $this->degrade(
                    $missingReasons,
                    $completeness,
                    'submitted_stage_invalid',
                    MeasurementHistoryCompleteness::Insufficient,
                );
            }

            return [null, $stage];
        }

        if ($eventType === MeasurementCycleEventType::StageApproved) {
            if ($stage === null || $stage < 1 || $stage > 4) {
                $this->degrade(
                    $missingReasons,
                    $completeness,
                    'approved_stage_invalid',
                    MeasurementHistoryCompleteness::Insufficient,
                );

                return [$stage, null];
            }

            $derivedNextStage = $stage + 1;
            $recordedTarget = $properties->nullableInt('target_stage');
            $toStatus = $properties->nullableString('to_status');
            $allowedStatuses = match ($stage) {
                1, 2 => ['in_review'],
                3 => ['awaiting_payment'],
                4 => ['awaiting_receipt', 'approved'],
            };

            if (($recordedTarget !== null && $recordedTarget !== $derivedNextStage)
                || ($toStatus !== null && ! in_array($toStatus, $allowedStatuses, true))) {
                $this->degrade(
                    $missingReasons,
                    $completeness,
                    'stage_approval_transition_contradiction',
                    MeasurementHistoryCompleteness::Insufficient,
                );

                return [$stage, null];
            }

            return [$stage, $derivedNextStage];
        }

        if ($eventType === MeasurementCycleEventType::StageRejected) {
            $target = $properties->nullableInt('target_stage');

            if ($stage === 1) {
                if ($target !== null || $properties->nullableString('to_status') !== 'rejected') {
                    $this->degrade(
                        $missingReasons,
                        $completeness,
                        'terminal_rejection_contradiction',
                        MeasurementHistoryCompleteness::Insufficient,
                    );
                }

                return [1, null];
            }

            if ($stage === null
                || $stage < 2
                || $stage > 4
                || $target !== $stage - 1
                || $properties->nullableString('to_status') !== 'in_review') {
                $this->degrade(
                    $missingReasons,
                    $completeness,
                    'rejection_target_invalid',
                    MeasurementHistoryCompleteness::Insufficient,
                );
            }

            return [$stage, $target];
        }

        if ($eventType === MeasurementCycleEventType::FinalizationReturned) {
            $target = $properties->nullableInt('target_stage');

            $expectedStatus = $target === 4 ? 'awaiting_payment' : 'in_review';

            if ($stage !== 5
                || $target === null
                || $target < 1
                || $target > 4
                || $properties->nullableString('to_status') !== $expectedStatus) {
                $this->degrade(
                    $missingReasons,
                    $completeness,
                    'finalization_return_target_invalid',
                    MeasurementHistoryCompleteness::Insufficient,
                );
            }

            return [$stage, $target];
        }

        if ($eventType === MeasurementCycleEventType::Finalized) {
            if ($stage !== 5 || $properties->nullableString('to_status') !== 'finalized') {
                $this->degrade(
                    $missingReasons,
                    $completeness,
                    'finalization_stage_invalid',
                    MeasurementHistoryCompleteness::Insufficient,
                );
            }

            return [$stage, null];
        }

        if (in_array($eventType, [
            MeasurementCycleEventType::StagePaused,
            MeasurementCycleEventType::StageResumed,
            MeasurementCycleEventType::PaymentRegistered,
            MeasurementCycleEventType::ReceiptAttached,
            MeasurementCycleEventType::ReceiptDeleted,
        ], true)) {
            if ($stage === null || $stage < 1 || $stage > 5) {
                $this->degrade(
                    $missingReasons,
                    $completeness,
                    'event_stage_invalid',
                    MeasurementHistoryCompleteness::Insufficient,
                );
            }

            return [$stage, $stage];
        }

        return [null, null];
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

        if (in_array($eventType, [
            MeasurementCycleEventType::StageApproved,
            MeasurementCycleEventType::StageRejected,
        ], true) && $stage !== null && $measurement->relationLoaded('reviews')) {
            $review = $measurement->reviews->first(
                fn (MeasurementReview $candidate): bool => (int) $candidate->stage === $stage
                    && $candidate->reviewer_user_id !== null
                    && $candidate->reviewed_at !== null
                    && $this->timestampsClose($candidate->reviewed_at, $activity->created_at),
            );

            if ($review instanceof MeasurementReview) {
                return (int) $review->reviewer_user_id;
            }
        }

        if ($eventType === MeasurementCycleEventType::PaymentRegistered
            && $measurement->relationLoaded('payments')) {
            $paymentIds = $properties->intList('payment_ids');
            $actors = $measurement->payments
                ->whereIn('id', $paymentIds)
                ->filter(fn (MeasurementPayment $payment): bool => $payment->created_by !== null
                    && $this->timestampsClose($payment->created_at, $activity->created_at))
                ->pluck('created_by')
                ->unique()
                ->values();

            if ($paymentIds !== [] && $actors->count() === 1) {
                return (int) $actors->first();
            }
        }

        if ($eventType === MeasurementCycleEventType::ReceiptAttached
            && $measurement->relationLoaded('payments')) {
            $paymentId = $properties->nullableInt('payment_id');
            $payment = $paymentId !== null ? $measurement->payments->firstWhere('id', $paymentId) : null;

            if ($payment instanceof MeasurementPayment
                && $payment->receipt_uploaded_by !== null
                && $payment->receipt_uploaded_at !== null
                && $this->timestampsClose($payment->receipt_uploaded_at, $activity->created_at)) {
                return (int) $payment->receipt_uploaded_by;
            }
        }

        if (($eventType === MeasurementCycleEventType::Finalized
                || ($eventType === MeasurementCycleEventType::StageRejected && $stage === 1))
            && $measurement->analyzed_by !== null
            && $measurement->analyzed_at !== null
            && $this->timestampsClose($measurement->analyzed_at, $activity->created_at)) {
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

    private function compareEvents(MeasurementCycleEvent $left, MeasurementCycleEvent $right): int
    {
        $timeComparison = ($left->occurredAt?->getTimestamp() ?? PHP_INT_MAX)
            <=> ($right->occurredAt?->getTimestamp() ?? PHP_INT_MAX);

        return $timeComparison !== 0
            ? $timeComparison
            : (($left->sourceActivityId ?? PHP_INT_MAX) <=> ($right->sourceActivityId ?? PHP_INT_MAX));
    }

    /** @param Collection<int, MeasurementCycleEvent> $workflowEvents */
    private function hasExplicitEquivalent(
        MeasurementCycleEvent $fallback,
        Collection $workflowEvents,
    ): bool {
        if (! $fallback->occurredAt instanceof CarbonImmutable) {
            return false;
        }

        return $workflowEvents->contains(function (MeasurementCycleEvent $explicit) use ($fallback): bool {
            if (! $explicit->occurredAt instanceof CarbonImmutable
                || $explicit->measurementId !== $fallback->measurementId
                || $explicit->eventType !== $fallback->eventType
                || $explicit->stageBefore !== $fallback->stageBefore
                || $explicit->stageAfter !== $fallback->stageAfter) {
                return false;
            }

            return abs($explicit->occurredAt->getTimestamp() - $fallback->occurredAt->getTimestamp())
                <= self::DEDUPLICATION_WINDOW_SECONDS;
        });
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

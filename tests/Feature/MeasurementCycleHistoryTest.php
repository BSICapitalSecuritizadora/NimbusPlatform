<?php

use App\DTOs\Measurements\MeasurementCycleEvent;
use App\DTOs\Measurements\MeasurementPauseInterval;
use App\Enums\AccessPermission;
use App\Enums\MeasurementCycleEventType;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementHistorySourceType;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementCycleHistoryReadModel;
use App\Services\MeasurementStageVisitBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @param list<string> $permissions */
function p3bCycleActor(array $permissions = []): User
{
    $actor = User::factory()->create();

    if ($permissions !== []) {
        $actor->givePermissionTo($permissions);
    }

    return $actor;
}

/** @return array{actor: User, operation: Operation, measurement: Measurement} */
function p3bCycleScenario(array $measurementState = []): array
{
    $actor = p3bCycleActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
        AccessPermission::MeasurementsReview->value,
    ]);
    $operation = Operation::factory()->create([
        'responsible_user_id' => $actor->getKey(),
        'stage2_reviewer_user_id' => $actor->getKey(),
        'stage3_reviewer_user_id' => $actor->getKey(),
        'payment_manager_user_id' => $actor->getKey(),
        'payment_receipt_uploader_user_id' => $actor->getKey(),
        'payment_finalizer_user_id' => $actor->getKey(),
    ]);
    $measurement = Measurement::factory()->create(array_merge([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 1,
    ], $measurementState));

    return compact('actor', 'operation', 'measurement');
}

/** @param array<string, mixed> $properties */
function p3bCycleWorkflowActivity(
    Measurement $measurement,
    User $actor,
    string $description,
    string $occurredAt,
    array $properties,
    int $revision,
): Activity {
    return Activity::query()->create([
        'log_name' => 'measurement_workflow',
        'description' => $description,
        'subject_type' => $measurement->getMorphClass(),
        'subject_id' => $measurement->getKey(),
        'causer_type' => $actor->getMorphClass(),
        'causer_id' => $actor->getKey(),
        'properties' => array_merge([
            'operation_id' => $measurement->operation_id,
            'measurement_id' => $measurement->getKey(),
            'delegated' => false,
            'delegation_id' => null,
            'delegator_user_id' => null,
            'delegation_scope' => null,
            'admin_override' => false,
            'actual_actor_user_id' => $actor->getKey(),
            'workflow_revision' => $revision,
        ], $properties),
        'created_at' => CarbonImmutable::parse($occurredAt),
        'updated_at' => CarbonImmutable::parse($occurredAt),
    ]);
}

function p3bVisitEvent(
    MeasurementCycleEventType $type,
    string $occurredAt,
    ?int $stageBefore,
    ?int $stageAfter,
    ?int $revision = null,
    ?string $statusAfter = null,
): MeasurementCycleEvent {
    static $sourceId = 1000;
    $sourceId++;

    return new MeasurementCycleEvent(
        sourceActivityId: $sourceId,
        sourceEvent: $type->value,
        sourceType: MeasurementHistorySourceType::WorkflowActivity,
        measurementId: 500,
        operationId: 600,
        occurredAt: CarbonImmutable::parse($occurredAt),
        eventType: $type,
        stageBefore: $stageBefore,
        stageAfter: $stageAfter,
        statusBefore: null,
        statusAfter: $statusAfter,
        actorId: 700,
        responsibility: $stageBefore !== null ? MeasurementResponsibility::primaryForStage($stageBefore) : null,
        expectedResponsibleId: 701,
        delegated: false,
        delegationId: null,
        delegatorId: null,
        delegationScope: null,
        adminOverride: false,
        workflowRevision: $revision,
        reason: null,
        paymentIds: [],
        paymentAmount: null,
        completeness: MeasurementHistoryCompleteness::Complete,
        missingReasons: [],
    );
}

function p3bPause(
    string $pausedAt,
    ?string $resumedAt,
    int $stage = 1,
    int $id = 1,
): MeasurementPauseInterval {
    return new MeasurementPauseInterval(
        sourcePauseId: $id,
        sourceType: MeasurementHistorySourceType::TableFallback,
        measurementId: 500,
        stage: $stage,
        pausedAt: CarbonImmutable::parse($pausedAt),
        resumedAt: $resumedAt !== null ? CarbonImmutable::parse($resumedAt) : null,
        pausedById: 700,
        resumedById: $resumedAt !== null ? 700 : null,
        reason: 'Pausa controlada',
        completeness: MeasurementHistoryCompleteness::Complete,
        missingReasons: [],
    );
}

it('reconstructs the complete current flow as independent visits from stages one through five', function () {
    $scenario = p3bCycleScenario([
        'status' => 'finalized',
        'current_stage' => 5,
        'analyzed_by' => null,
        'analyzed_at' => null,
    ]);
    $payment = MeasurementPayment::factory()->create([
        'operation_id' => $scenario['operation']->getKey(),
        'measurement_id' => $scenario['measurement']->getKey(),
        'amount' => '1500.25',
        'created_by' => $scenario['actor']->getKey(),
    ]);
    $actorId = $scenario['actor']->getKey();
    $events = [
        ['measurement_submitted', '2026-08-28 09:00:00', ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id']],
        ['measurement_stage_approved', '2026-08-28 10:00:00', ['stage' => 1, 'from_status' => 'in_review', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_stage_approved', '2026-08-28 11:00:00', ['stage' => 2, 'from_status' => 'in_review', 'to_status' => 'in_review', 'responsibility' => 'stage2_reviewer_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_stage_approved', '2026-08-28 12:00:00', ['stage' => 3, 'from_status' => 'in_review', 'to_status' => 'awaiting_payment', 'responsibility' => 'stage3_reviewer_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_payment_registered', '2026-08-28 12:30:00', ['stage' => 4, 'from_status' => 'awaiting_payment', 'to_status' => 'awaiting_payment', 'payment_ids' => [$payment->getKey()], 'amount' => '1500.25', 'responsibility' => 'payment_manager_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_stage_approved', '2026-08-28 13:00:00', ['stage' => 4, 'from_status' => 'awaiting_payment', 'to_status' => 'awaiting_receipt', 'responsibility' => 'payment_manager_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_receipt_attached', '2026-08-28 13:30:00', ['stage' => 5, 'payment_id' => $payment->getKey(), 'from_status' => 'awaiting_receipt', 'to_status' => 'approved', 'responsibility' => 'payment_receipt_uploader_user_id', 'expected_responsible_user_id' => $actorId]],
        ['measurement_finalized', '2026-08-28 14:00:00', ['stage' => 5, 'from_status' => 'approved', 'to_status' => 'finalized', 'responsibility' => 'payment_finalizer_user_id', 'expected_responsible_user_id' => $actorId]],
    ];

    foreach ($events as $revision => [$description, $at, $properties]) {
        p3bCycleWorkflowActivity(
            $scenario['measurement'],
            $scenario['actor'],
            $description,
            $at,
            $properties,
            $revision + 1,
        );
    }

    $history = app(MeasurementCycleHistoryReadModel::class)->for(
        $scenario['actor'],
        $scenario['measurement']->getKey(),
    );

    expect(array_column($history->stageVisits, 'stage'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($history->stageVisits, 'sequence'))->toBe([1, 1, 1, 1, 1])
        ->and($history->cycleStart?->toDateTimeString())->toBe('2026-08-28 09:00:00')
        ->and($history->cycleEnd?->toDateTimeString())->toBe('2026-08-28 14:00:00')
        ->and($history->terminalReason)->toBe(MeasurementStageExitReason::Finalized)
        ->and($history->completeness)->toBe(MeasurementHistoryCompleteness::Complete)
        ->and($history->payments)->toHaveCount(1)
        ->and($history->payments[0]->amount)->toBe('1500.25')
        ->and($history->stageVisits[0]->pausedDuration)->toBe(0)
        ->and(collect($history->events)->contains(
            fn (MeasurementCycleEvent $event): bool => $event->eventType === MeasurementCycleEventType::ReceiptAttached,
        ))->toBeTrue();
});

it('closes a terminal stage one rejection without opening another visit', function () {
    $result = app(MeasurementStageVisitBuilder::class)->build(collect([
        p3bVisitEvent(MeasurementCycleEventType::Submitted, '2026-08-28 09:00:00', null, 1, 1),
        p3bVisitEvent(MeasurementCycleEventType::StageRejected, '2026-08-28 10:00:00', 1, null, 2, 'rejected'),
    ]), collect());

    expect($result->visits)->toHaveCount(1)
        ->and($result->visits[0]->exitReason)->toBe(MeasurementStageExitReason::RejectedTerminal)
        ->and($result->visits[0]->exitedAt?->toDateTimeString())->toBe('2026-08-28 10:00:00');
});

it('creates new stage sequences for rejection and finalization return reentry without using revision', function () {
    $events = collect([
        p3bVisitEvent(MeasurementCycleEventType::Submitted, '2026-08-28 09:00:00', null, 1, 80),
        p3bVisitEvent(MeasurementCycleEventType::StageApproved, '2026-08-28 10:00:00', 1, 2, 80),
        p3bVisitEvent(MeasurementCycleEventType::StageApproved, '2026-08-28 11:00:00', 2, 3, null),
        p3bVisitEvent(MeasurementCycleEventType::StageRejected, '2026-08-28 12:00:00', 3, 2, 3),
        p3bVisitEvent(MeasurementCycleEventType::StageApproved, '2026-08-28 13:00:00', 2, 3, 3),
        p3bVisitEvent(MeasurementCycleEventType::StageApproved, '2026-08-28 14:00:00', 3, 4, 1),
        p3bVisitEvent(MeasurementCycleEventType::StageApproved, '2026-08-28 15:00:00', 4, 5, 1),
        p3bVisitEvent(MeasurementCycleEventType::FinalizationReturned, '2026-08-28 16:00:00', 5, 2, null),
    ]);
    $result = app(MeasurementStageVisitBuilder::class)->build($events, collect());
    $stageTwo = collect($result->visits)->where('stage', 2)->values();
    $stageThree = collect($result->visits)->where('stage', 3)->values();
    $stageFive = collect($result->visits)->firstWhere('stage', 5);

    expect($stageTwo->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($stageThree->pluck('sequence')->all())->toBe([1, 2])
        ->and($stageFive?->exitReason)->toBe(MeasurementStageExitReason::ReturnedFromFinalization)
        ->and($stageTwo->last()?->exitReason)->toBe(MeasurementStageExitReason::Open);
});

it('uses interval union for pause duration and keeps calendar and active durations separate', function () {
    $events = collect([
        p3bVisitEvent(MeasurementCycleEventType::Submitted, '2026-08-28 10:00:00', null, 1, 1),
        p3bVisitEvent(MeasurementCycleEventType::StageApproved, '2026-08-28 12:00:00', 1, 2, 2),
    ]);
    $pauses = collect([
        p3bPause('2026-08-28 10:15:00', '2026-08-28 10:30:00', 1, 1),
        p3bPause('2026-08-28 10:25:00', '2026-08-28 10:45:00', 1, 2),
        p3bPause('2026-08-28 11:00:00', '2026-08-28 11:10:00', 1, 3),
    ]);
    $visit = app(MeasurementStageVisitBuilder::class)->build($events, $pauses)->visits[0];

    expect($visit->calendarDuration)->toBe(7200)
        ->and($visit->pausedDuration)->toBe(2400)
        ->and($visit->activeDuration)->toBe(4800)
        ->and($visit->completeness)->toBe(MeasurementHistoryCompleteness::Complete);
});

it('uses measurement pauses as the primary duration source', function () {
    $scenario = p3bCycleScenario(['status' => 'in_review', 'current_stage' => 2]);
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_submitted',
        '2026-08-28 09:00:00',
        ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id'],
        1,
    );
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_stage_approved',
        '2026-08-28 10:00:00',
        ['stage' => 1, 'from_status' => 'in_review', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()],
        2,
    );
    $scenario['measurement']->pauses()->create([
        'stage' => 1,
        'paused_by' => $scenario['actor']->getKey(),
        'pause_reason' => 'Documento pendente',
        'paused_at' => CarbonImmutable::parse('2026-08-28 09:15:00'),
        'resumed_at' => CarbonImmutable::parse('2026-08-28 09:30:00'),
        'resumed_by' => $scenario['actor']->getKey(),
    ]);

    $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $scenario['measurement']);

    expect($history->pauses)->toHaveCount(1)
        ->and($history->pauses[0]->sourceType)->toBe(MeasurementHistorySourceType::TableFallback)
        ->and($history->stageVisits[0]->calendarDuration)->toBe(3600)
        ->and($history->stageVisits[0]->pausedDuration)->toBe(900)
        ->and($history->stageVisits[0]->activeDuration)->toBe(2700);
});

it('marks open and crossing pauses honestly without forcing an exact net duration', function () {
    $events = collect([
        p3bVisitEvent(MeasurementCycleEventType::Submitted, '2026-08-28 10:00:00', null, 1, 1),
        p3bVisitEvent(MeasurementCycleEventType::StageApproved, '2026-08-28 12:00:00', 1, 2, 2),
    ]);
    $openPauseVisit = app(MeasurementStageVisitBuilder::class)->build(
        $events,
        collect([p3bPause('2026-08-28 11:00:00', null)]),
    )->visits[0];
    $crossingVisit = app(MeasurementStageVisitBuilder::class)->build(
        $events,
        collect([p3bPause('2026-08-28 11:50:00', '2026-08-28 12:10:00')]),
    )->visits[0];

    expect($openPauseVisit->calendarDuration)->toBe(7200)
        ->and($openPauseVisit->pausedDuration)->toBeNull()
        ->and($openPauseVisit->activeDuration)->toBeNull()
        ->and($openPauseVisit->completeness)->toBe(MeasurementHistoryCompleteness::Partial)
        ->and($crossingVisit->pausedDuration)->toBe(600)
        ->and($crossingVisit->activeDuration)->toBe(6600)
        ->and($crossingVisit->completeness)->toBe(MeasurementHistoryCompleteness::Partial);
});

it('keeps a reliably entered current visit complete with null final durations', function () {
    $result = app(MeasurementStageVisitBuilder::class)->build(collect([
        p3bVisitEvent(MeasurementCycleEventType::Submitted, '2026-08-28 10:00:00', null, 1, null),
    ]), collect());
    $visit = $result->visits[0];

    expect($visit->exitReason)->toBe(MeasurementStageExitReason::Open)
        ->and($visit->exitedAt)->toBeNull()
        ->and($visit->calendarDuration)->toBeNull()
        ->and($visit->pausedDuration)->toBeNull()
        ->and($visit->activeDuration)->toBeNull()
        ->and($visit->completeness)->toBe(MeasurementHistoryCompleteness::Complete);
});

it('does not turn an unbounded legacy decision into zero duration', function () {
    $result = app(MeasurementStageVisitBuilder::class)->build(collect([
        p3bVisitEvent(MeasurementCycleEventType::StageApproved, '2026-08-28 10:00:00', 3, 4, null),
    ]), collect());
    $visit = collect($result->visits)->firstWhere('stage', 3);

    expect($visit?->enteredAt)->toBeNull()
        ->and($visit?->calendarDuration)->toBeNull()
        ->and($visit?->pausedDuration)->toBeNull()
        ->and($visit?->activeDuration)->toBeNull()
        ->and($visit?->completeness)->toBe(MeasurementHistoryCompleteness::Insufficient);
});

it('reports a current measurement without a historical chain as insufficient instead of fabricating visits', function () {
    $scenario = p3bCycleScenario(['status' => 'in_review', 'current_stage' => 3]);
    Activity::query()
        ->where('subject_type', $scenario['measurement']->getMorphClass())
        ->where('subject_id', $scenario['measurement']->getKey())
        ->delete();

    $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $scenario['measurement']);

    expect($history->stageVisits)->toBe([])
        ->and($history->cycleStart)->toBeNull()
        ->and($history->cycleEnd)->toBeNull()
        ->and($history->completeness)->toBe(MeasurementHistoryCompleteness::Insufficient)
        ->and($history->warnings)->toContain('cycle_start_unknown');
});

it('uses current terminal state only as an explicitly partial cycle end fallback', function () {
    $scenario = p3bCycleScenario([
        'status' => 'finalized',
        'current_stage' => 5,
        'analyzed_by' => null,
        'analyzed_at' => CarbonImmutable::parse('2026-08-28 12:00:00'),
    ]);
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_submitted',
        '2026-08-28 09:00:00',
        ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id'],
        1,
    );
    $approvals = [
        [1, '2026-08-28 09:30:00', 'in_review', 'responsible_user_id'],
        [2, '2026-08-28 10:00:00', 'in_review', 'stage2_reviewer_user_id'],
        [3, '2026-08-28 10:30:00', 'awaiting_payment', 'stage3_reviewer_user_id'],
        [4, '2026-08-28 11:00:00', 'approved', 'payment_manager_user_id'],
    ];

    foreach ($approvals as $index => [$stage, $at, $toStatus, $responsibility]) {
        p3bCycleWorkflowActivity(
            $scenario['measurement'],
            $scenario['actor'],
            'measurement_stage_approved',
            $at,
            [
                'stage' => $stage,
                'from_status' => $stage === 4 ? 'awaiting_payment' : 'in_review',
                'to_status' => $toStatus,
                'responsibility' => $responsibility,
                'expected_responsible_user_id' => $scenario['actor']->getKey(),
            ],
            $index + 2,
        );
    }

    $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $scenario['measurement']);

    expect($history->cycleEnd?->toDateTimeString())->toBe('2026-08-28 12:00:00')
        ->and($history->terminalReason)->toBe(MeasurementStageExitReason::Finalized)
        ->and($history->completeness)->toBe(MeasurementHistoryCompleteness::Partial)
        ->and($history->warnings)->toContain(
            'cycle_end_from_current_state_fallback',
            'stage_visit_exit_from_current_state_fallback',
        );
});

it('does not choose between contradictory terminal events', function () {
    $scenario = p3bCycleScenario(['status' => 'finalized', 'current_stage' => 5]);
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_submitted',
        '2026-08-28 09:00:00',
        ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id'],
        1,
    );
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_stage_rejected',
        '2026-08-28 10:00:00',
        ['stage' => 1, 'target_stage' => null, 'from_status' => 'in_review', 'to_status' => 'rejected', 'notes' => 'Recusa', 'responsibility' => 'responsible_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()],
        2,
    );
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_finalized',
        '2026-08-28 11:00:00',
        ['stage' => 5, 'from_status' => 'approved', 'to_status' => 'finalized', 'responsibility' => 'payment_finalizer_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()],
        3,
    );

    $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $scenario['measurement']);

    expect($history->cycleEnd)->toBeNull()
        ->and($history->terminalReason)->toBeNull()
        ->and($history->completeness)->toBe(MeasurementHistoryCompleteness::Insufficient)
        ->and($history->warnings)->toContain('contradictory_terminal_events');
});

it('keeps payment context when rows exist and reports missing event rows without breaking history', function () {
    $scenario = p3bCycleScenario();
    $payment = MeasurementPayment::factory()->create([
        'operation_id' => $scenario['operation']->getKey(),
        'measurement_id' => $scenario['measurement']->getKey(),
        'created_by' => $scenario['actor']->getKey(),
    ]);
    $secondPayment = MeasurementPayment::factory()->create([
        'operation_id' => $scenario['operation']->getKey(),
        'measurement_id' => $scenario['measurement']->getKey(),
        'created_by' => $scenario['actor']->getKey(),
    ]);
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_submitted',
        '2026-08-28 09:00:00',
        ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id'],
        1,
    );
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_payment_registered',
        '2026-08-28 10:00:00',
        ['stage' => 4, 'from_status' => 'awaiting_payment', 'to_status' => 'awaiting_payment', 'payment_ids' => [$payment->getKey(), $secondPayment->getKey(), 999999], 'amount' => '1000', 'responsibility' => 'payment_manager_user_id', 'expected_responsible_user_id' => $scenario['actor']->getKey()],
        2,
    );

    $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $scenario['measurement']);
    $paymentEvent = collect($history->events)->first(
        fn (MeasurementCycleEvent $event): bool => $event->eventType === MeasurementCycleEventType::PaymentRegistered,
    );

    expect($history->payments)->toHaveCount(2)
        ->and($paymentEvent?->paymentIds)->toBe([$payment->getKey(), $secondPayment->getKey(), 999999])
        ->and($paymentEvent?->missingReasons)->toContain('payment_row_missing:999999')
        ->and($history->warnings)->toContain('payment_row_missing:999999');
});

it('requires both read permissions and applies visibility before resolving a measurement id', function () {
    $scenario = p3bCycleScenario();
    $withoutCyclePermission = p3bCycleActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsReview->value,
    ]);
    $withoutMeasurementPermission = p3bCycleActor([
        AccessPermission::MeasurementsCycleReportsView->value,
        AccessPermission::MeasurementsReview->value,
    ]);
    $invisible = p3bCycleActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
        AccessPermission::MeasurementsReview->value,
    ]);
    $readModel = app(MeasurementCycleHistoryReadModel::class);

    expect(fn () => $readModel->for($withoutCyclePermission, $scenario['measurement']->getKey()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $readModel->for($withoutMeasurementPermission, $scenario['measurement']->getKey()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $readModel->for($invisible, $scenario['measurement']->getKey()))
        ->toThrow(ModelNotFoundException::class)
        ->and($readModel->for($scenario['actor'], $scenario['measurement']->getKey())->measurementId)
        ->toBe($scenario['measurement']->getKey());
});

it('preserves delegated visibility and the existing administrator override', function () {
    $direct = p3bCycleActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
        AccessPermission::MeasurementsReview->value,
    ]);
    $delegate = p3bCycleActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
        AccessPermission::MeasurementsReview->value,
    ]);
    $operation = Operation::factory()->create(['responsible_user_id' => $direct->getKey()]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->getKey()]);
    ResponsibilityDelegation::factory()->active()->forOperation($operation)->create([
        'delegator_user_id' => $direct->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $readModel = app(MeasurementCycleHistoryReadModel::class);

    expect($readModel->for($delegate, $measurement)->measurementId)->toBe($measurement->getKey())
        ->and($readModel->for($admin, $measurement)->measurementId)->toBe($measurement->getKey());
});

it('grants the cycle report view permission only to the intended default roles', function () {
    foreach (['super-admin', 'admin', 'editor'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);

        expect($user->can(AccessPermission::MeasurementsCycleReportsView->value))->toBeTrue();
    }

    $customRoleUser = User::factory()->create();
    $customRoleUser->assignRole(Role::create(['name' => 'p3b-custom-role']));

    expect($customRoleUser->can(AccessPermission::MeasurementsCycleReportsView->value))->toBeFalse()
        ->and(AccessPermission::tryFrom('measurements.cycle-reports.export'))->toBeNull();
});

it('keeps query growth constant for a rich timeline with 205 activity rows', function () {
    $scenario = p3bCycleScenario();
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_submitted',
        '2026-08-28 09:00:00',
        ['stage' => 1, 'from_status' => 'pending', 'to_status' => 'in_review', 'responsibility' => 'responsible_user_id'],
        1,
    );
    $common = [
        'operation_id' => $scenario['operation']->getKey(),
        'measurement_id' => $scenario['measurement']->getKey(),
        'delegated' => false,
        'delegation_id' => null,
        'delegator_user_id' => null,
        'delegation_scope' => null,
        'admin_override' => false,
        'actual_actor_user_id' => $scenario['actor']->getKey(),
        'workflow_revision' => 1,
    ];
    $rows = [];

    for ($index = 0; $index < 204; $index++) {
        $at = CarbonImmutable::parse('2026-08-28 09:01:00')->addSeconds($index);
        $rows[] = [
            'log_name' => 'measurement_workflow',
            'description' => $index % 2 === 0
                ? 'measurement_engineering_snapshot_created'
                : 'measurement_engineering_snapshot_invalidated',
            'subject_type' => $scenario['measurement']->getMorphClass(),
            'subject_id' => $scenario['measurement']->getKey(),
            'causer_type' => $scenario['actor']->getMorphClass(),
            'causer_id' => $scenario['actor']->getKey(),
            'properties' => json_encode($common, JSON_THROW_ON_ERROR),
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    Activity::query()->insert(array_slice($rows, 0, 4));
    $readModel = app(MeasurementCycleHistoryReadModel::class);
    $scenario['actor']->can(AccessPermission::MeasurementsCycleReportsView->value);
    $readModel->for($scenario['actor'], $scenario['measurement']);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $readModel->for($scenario['actor'], $scenario['measurement']);
    $fiveEventQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    Activity::query()->insert(array_slice($rows, 4));
    DB::flushQueryLog();
    DB::enableQueryLog();
    $history = $readModel->for($scenario['actor'], $scenario['measurement']);
    $twoHundredAndFiveEventQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($history->events)->toHaveCount(206)
        ->and($twoHundredAndFiveEventQueries)->toBe($fiveEventQueries)
        ->and($twoHundredAndFiveEventQueries)->toBeLessThanOrEqual(7);
});

it('serializes reporting DTOs without storage, hash, snapshot or raw properties metadata', function () {
    $scenario = p3bCycleScenario();
    p3bCycleWorkflowActivity(
        $scenario['measurement'],
        $scenario['actor'],
        'measurement_submitted',
        '2026-08-28 09:00:00',
        [
            'stage' => 1,
            'from_status' => 'pending',
            'to_status' => 'in_review',
            'responsibility' => 'responsible_user_id',
            'storage_path' => 'private/measurement.pdf',
            'hash' => 'private-hash',
            'engineering_snapshot' => ['raw' => 'private-snapshot'],
        ],
        1,
    );
    $serialized = json_encode(
        app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $scenario['measurement'])->toArray(),
        JSON_THROW_ON_ERROR,
    );

    expect($serialized)->not->toContain(
        'private/measurement.pdf',
        'private-hash',
        'private-snapshot',
        'storage_path',
        'engineering_snapshot',
        'raw_properties',
    );
});

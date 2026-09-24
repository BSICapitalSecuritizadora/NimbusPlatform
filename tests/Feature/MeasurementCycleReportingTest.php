<?php

use App\DTOs\Measurements\AuthorizedMeasurementCycleHistory;
use App\DTOs\Measurements\MeasurementCycleHistory;
use App\DTOs\Measurements\MeasurementCycleReportFilters;
use App\DTOs\Measurements\MeasurementStageVisit;
use App\Enums\AccessPermission;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementCycleHistoryBatchReader;
use App\Services\MeasurementCycleHistoryReadModel;
use App\Services\MeasurementCycleReportingService;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @param list<string> $permissions */
function p3b2ReportingActor(array $permissions = []): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

function p3b2ReportingOperation(User $visibleActor, array $attributes = []): Operation
{
    return Operation::factory()->create(array_merge([
        'responsible_user_id' => $visibleActor->getKey(),
        'stage2_reviewer_user_id' => $visibleActor->getKey(),
        'stage3_reviewer_user_id' => $visibleActor->getKey(),
        'payment_manager_user_id' => $visibleActor->getKey(),
        'payment_receipt_uploader_user_id' => $visibleActor->getKey(),
        'payment_finalizer_user_id' => $visibleActor->getKey(),
    ], $attributes));
}

function p3b2ReportingVisit(
    Measurement $measurement,
    MeasurementStageExitReason $reason,
    int $stage = 1,
    int $sequence = 1,
    MeasurementHistoryCompleteness $completeness = MeasurementHistoryCompleteness::Complete,
    int $calendarDuration = 3600,
    ?int $activeDuration = 3000,
    int $pausedDuration = 600,
    ?int $actorId = null,
    ?int $expectedResponsibleId = null,
    string $enteredAt = '2026-08-01 09:00:00',
    string $exitedAt = '2026-08-01 10:00:00',
): MeasurementStageVisit {
    return new MeasurementStageVisit(
        measurementId: (int) $measurement->getKey(),
        stage: $stage,
        sequence: $sequence,
        enteredAt: CarbonImmutable::parse($enteredAt),
        exitedAt: CarbonImmutable::parse($exitedAt),
        exitReason: $reason,
        decisionEvent: null,
        exitActorId: $actorId,
        responsibility: MeasurementResponsibility::primaryForStage($stage),
        expectedResponsibleId: $expectedResponsibleId,
        delegated: false,
        delegationId: null,
        delegatorId: null,
        delegationScope: null,
        adminOverride: false,
        revisionStart: 1,
        revisionEnd: 2,
        calendarDuration: $calendarDuration,
        pausedDuration: $pausedDuration,
        activeDuration: $activeDuration,
        completeness: $completeness,
        missingReasons: $completeness === MeasurementHistoryCompleteness::Complete
            ? []
            : ['actor_unknown'],
    );
}

/** @param list<MeasurementStageVisit> $visits */
function p3b2ReportingHistory(
    Measurement $measurement,
    array $visits,
    MeasurementHistoryCompleteness $completeness = MeasurementHistoryCompleteness::Complete,
    int $cycleDuration = 86400,
): MeasurementCycleHistory {
    $cycleStart = CarbonImmutable::parse('2026-08-01 00:00:00');

    return new MeasurementCycleHistory(
        measurementId: (int) $measurement->getKey(),
        operationId: (int) $measurement->operation_id,
        referenceMonth: $measurement->reference_month?->toDateString(),
        measurementLabel: $measurement->filename,
        currentStatus: (string) $measurement->status,
        currentStage: (int) $measurement->current_stage,
        events: [],
        stageVisits: $visits,
        pauses: [],
        payments: [],
        cycleStart: $cycleStart,
        cycleEnd: $cycleStart->addSeconds($cycleDuration),
        terminalReason: MeasurementStageExitReason::Finalized,
        completeness: $completeness,
        warnings: $completeness === MeasurementHistoryCompleteness::Complete
            ? []
            : ['actor_unknown'],
    );
}

/** @param Collection<int, AuthorizedMeasurementCycleHistory> $records */
function p3b2ReportingServiceFor(Collection $records, ?int &$batchCalls = null): MeasurementCycleReportingService
{
    $byId = $records->keyBy(fn (AuthorizedMeasurementCycleHistory $record): int => (int) $record->measurement->getKey());
    $batchReader = Mockery::mock(MeasurementCycleHistoryBatchReader::class);
    $batchReader->shouldReceive('read')
        ->andReturnUsing(function (User $actor, iterable $ids) use ($byId, &$batchCalls): Collection {
            $batchCalls = ($batchCalls ?? 0) + 1;

            return collect($ids)
                ->map(fn (mixed $id): ?AuthorizedMeasurementCycleHistory => $byId->get((int) $id))
                ->filter()
                ->values();
        });

    return new MeasurementCycleReportingService(
        $batchReader,
        Mockery::mock(MeasurementCycleHistoryReadModel::class),
        Mockery::mock(MeasurementSlaService::class),
        Mockery::mock(MeasurementWorkflow::class),
    );
}

/** @param array<string, mixed> $properties */
function p3b2ReportingActivity(
    Measurement $measurement,
    User $actor,
    string $description,
    string $occurredAt,
    int $revision,
    array $properties,
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
            'actual_actor_user_id' => $actor->getKey(),
            'expected_responsible_user_id' => $actor->getKey(),
            'delegated' => false,
            'delegation_id' => null,
            'delegator_user_id' => null,
            'delegation_scope' => null,
            'admin_override' => false,
            'workflow_revision' => $revision,
        ], $properties),
        'created_at' => CarbonImmutable::parse($occurredAt),
        'updated_at' => CarbonImmutable::parse($occurredAt),
    ]);
}

it('requires both view permissions and never turns report permission into global visibility', function () {
    $withoutMeasurementView = p3b2ReportingActor([
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $withoutReportView = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
    ]);

    expect(fn () => p3b2ReportingServiceFor(collect())->report(
        $withoutMeasurementView,
        new MeasurementCycleReportFilters,
    ))->toThrow(AuthorizationException::class)
        ->and(fn () => p3b2ReportingServiceFor(collect())->report(
            $withoutReportView,
            new MeasurementCycleReportFilters,
        ))->toThrow(AuthorizationException::class);

    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $visibleOperation = p3b2ReportingOperation($actor);
    $hiddenOperation = p3b2ReportingOperation(User::factory()->create());
    $visible = Measurement::factory()->create([
        'operation_id' => $visibleOperation->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->load('operation.emission');
    $hidden = Measurement::factory()->create([
        'operation_id' => $hiddenOperation->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->load('operation.emission');
    $records = collect([$visible, $hidden])->map(fn (Measurement $measurement): AuthorizedMeasurementCycleHistory => new AuthorizedMeasurementCycleHistory(
        measurement: $measurement,
        history: p3b2ReportingHistory($measurement, [p3b2ReportingVisit($measurement, MeasurementStageExitReason::Approved)]),
    ));

    $result = p3b2ReportingServiceFor($records)->report($actor, new MeasurementCycleReportFilters);

    expect($result->rows)->toHaveCount(1)
        ->and($result->rows[0]->measurementId)->toBe($visible->getKey())
        ->and(collect($result->rows)->pluck('measurementId'))->not->toContain($hidden->getKey());
});

it('preserves current delegated visibility through the canonical visible scope', function () {
    $owner = p3b2ReportingActor([
        AccessPermission::MeasurementsReview->value,
    ]);
    $delegate = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
        AccessPermission::MeasurementsReview->value,
    ]);
    $operation = p3b2ReportingOperation($owner);
    ResponsibilityDelegation::factory()->active()->forOperation($operation)->create([
        'delegator_user_id' => $owner->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'created_by' => $owner->getKey(),
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->load('operation.emission');
    $record = new AuthorizedMeasurementCycleHistory(
        measurement: $measurement,
        history: p3b2ReportingHistory($measurement, [p3b2ReportingVisit($measurement, MeasurementStageExitReason::Approved)]),
    );
    $isVisible = Measurement::query()
        ->visibleTo($delegate)
        ->whereKey($measurement->getKey())
        ->exists();
    $result = p3b2ReportingServiceFor(collect([$record]))
        ->report($delegate, new MeasurementCycleReportFilters);

    expect($isVisible)->toBeTrue()
        ->and($result->totalRows)->toBe(1)
        ->and($result->rows)->toHaveCount(1)
        ->and($result->rows[0]->measurementId)->toBe($measurement->getKey());
});

it('preserves administrative visibility and hides an inaccessible detail as not found', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('admin');
    $outsider = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $operation = p3b2ReportingOperation(User::factory()->create());
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->load('operation.emission');
    $record = new AuthorizedMeasurementCycleHistory(
        measurement: $measurement,
        history: p3b2ReportingHistory($measurement, [p3b2ReportingVisit($measurement, MeasurementStageExitReason::Approved)]),
    );

    expect(p3b2ReportingServiceFor(collect([$record]))
        ->report($administrator, new MeasurementCycleReportFilters)
        ->totalRows)->toBe(1)
        ->and(fn () => p3b2ReportingServiceFor(collect())->detail(
            $outsider,
            (int) $measurement->getKey(),
            new MeasurementCycleReportFilters,
        ))->toThrow(ModelNotFoundException::class);
});

it('computes decisions rates exact averages medians and explicit coverage only from eligible visits', function () {
    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $operation = p3b2ReportingOperation($actor);
    $records = collect();
    $specifications = [
        ...array_fill(0, 8, [MeasurementStageExitReason::Approved, 1, MeasurementHistoryCompleteness::Complete]),
        [MeasurementStageExitReason::RejectedTerminal, 1, MeasurementHistoryCompleteness::Complete],
        [MeasurementStageExitReason::ReturnedByRejection, 2, MeasurementHistoryCompleteness::Complete],
        ...array_fill(0, 9, [MeasurementStageExitReason::Finalized, 5, MeasurementHistoryCompleteness::Complete]),
        [MeasurementStageExitReason::ReturnedFromFinalization, 5, MeasurementHistoryCompleteness::Complete],
        [MeasurementStageExitReason::Approved, 1, MeasurementHistoryCompleteness::Partial],
        [MeasurementStageExitReason::Approved, 1, MeasurementHistoryCompleteness::Insufficient],
    ];

    foreach ($specifications as $index => [$reason, $stage, $completeness]) {
        $measurement = Measurement::factory()->create([
            'operation_id' => $operation->getKey(),
            'status' => 'finalized',
            'current_stage' => 5,
        ])->load('operation.emission');
        $duration = ($index + 1) * 100;
        $visit = p3b2ReportingVisit(
            $measurement,
            $reason,
            stage: $stage,
            completeness: $completeness,
            calendarDuration: $duration,
            activeDuration: $duration - 10,
            pausedDuration: 10,
            actorId: $actor->getKey(),
            expectedResponsibleId: $actor->getKey(),
        );
        $records->push(new AuthorizedMeasurementCycleHistory(
            measurement: $measurement,
            history: p3b2ReportingHistory($measurement, [$visit], $completeness, $duration * 10),
        ));
    }

    $result = p3b2ReportingServiceFor($records)->report($actor, new MeasurementCycleReportFilters, perPage: 100);
    $approvalPopulation = p3b2ReportingServiceFor($records)->report(
        $actor,
        MeasurementCycleReportFilters::fromArray([
            'decision_type' => MeasurementStageExitReason::Approved->value,
            'completeness' => MeasurementHistoryCompleteness::Complete->value,
        ]),
        perPage: 100,
    );
    $stageOne = collect($result->stageMetrics)->firstWhere('stage', 1);
    $stageFive = collect($result->stageMetrics)->firstWhere('stage', 5);

    expect($result->summary->approvals)->toBe(8)
        ->and($result->summary->rejections)->toBe(2)
        ->and($result->summary->rejectionRate)->toBe(0.2)
        ->and($result->summary->finalizations)->toBe(9)
        ->and($result->summary->finalizationReturns)->toBe(1)
        ->and($result->summary->finalizationReturnRate)->toBe(0.1)
        ->and($stageOne->averageCalendarDuration)->toBe(500.0)
        ->and($stageOne->medianCalendarDuration)->toBe(500.0)
        ->and($stageFive->finalizationReturns)->toBe(1)
        ->and($result->coverage->complete)->toBe(20)
        ->and($result->coverage->partial)->toBe(1)
        ->and($result->coverage->insufficient)->toBe(1)
        ->and($stageOne->partialExcluded)->toBe(1)
        ->and($stageOne->insufficientExcluded)->toBe(1)
        ->and($approvalPopulation->summary->approvals)->toBe(8)
        ->and($approvalPopulation->totalRows)->toBe(8);
});

it('limits stage decisions to approvals and rejections from stages one through four', function () {
    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $operation = p3b2ReportingOperation($actor);
    $records = collect();
    $addVisitRecord = function (
        int $stage,
        MeasurementStageExitReason $exitReason,
        MeasurementHistoryCompleteness $completeness = MeasurementHistoryCompleteness::Complete,
    ) use ($actor, $operation, $records): void {
        $measurement = Measurement::factory()->create([
            'operation_id' => $operation->getKey(),
            'status' => 'finalized',
            'current_stage' => 5,
        ])->load('operation.emission');
        $visit = p3b2ReportingVisit(
            $measurement,
            $exitReason,
            stage: $stage,
            completeness: $completeness,
            actorId: $actor->getKey(),
            expectedResponsibleId: $actor->getKey(),
        );
        $records->push(new AuthorizedMeasurementCycleHistory(
            measurement: $measurement,
            history: p3b2ReportingHistory($measurement, [$visit], $completeness),
        ));
    };

    foreach (range(1, 4) as $stage) {
        for ($approval = 0; $approval < 8; $approval++) {
            $addVisitRecord($stage, MeasurementStageExitReason::Approved);
        }

        $rejectionReason = $stage === 1
            ? MeasurementStageExitReason::RejectedTerminal
            : MeasurementStageExitReason::ReturnedByRejection;

        for ($rejection = 0; $rejection < 2; $rejection++) {
            $addVisitRecord($stage, $rejectionReason);
        }
    }

    for ($finalization = 0; $finalization < 9; $finalization++) {
        $addVisitRecord(5, MeasurementStageExitReason::Finalized);
    }

    $addVisitRecord(5, MeasurementStageExitReason::ReturnedFromFinalization);
    $addVisitRecord(2, MeasurementStageExitReason::Approved, MeasurementHistoryCompleteness::Partial);
    $addVisitRecord(3, MeasurementStageExitReason::ReturnedByRejection, MeasurementHistoryCompleteness::Insufficient);

    $result = p3b2ReportingServiceFor($records)->report(
        $actor,
        new MeasurementCycleReportFilters,
        perPage: 100,
    );
    $stageMetrics = collect($result->stageMetrics)->keyBy('stage');

    foreach (range(1, 4) as $stage) {
        $metrics = $stageMetrics->get($stage);

        expect($metrics->decisions)->toBe(10)
            ->and($metrics->approvals)->toBe(8)
            ->and($metrics->rejections)->toBe(2)
            ->and($metrics->rejectionRate)->toBe(0.2);
    }

    $stageFive = $stageMetrics->get(5);

    expect($stageFive->decisions)->toBe(0)
        ->and($stageFive->finalizations)->toBe(9)
        ->and($stageFive->finalizationReturns)->toBe(1)
        ->and($stageFive->finalizationReturnRate)->toBe(0.1)
        ->and($stageMetrics->get(2)->partialExcluded)->toBe(1)
        ->and($stageMetrics->get(3)->insufficientExcluded)->toBe(1)
        ->and($result->summary->decisions)->toBe(40)
        ->and($result->summary->approvals)->toBe(32)
        ->and($result->summary->rejections)->toBe(8)
        ->and($result->summary->rejectionRate)->toBe(0.2)
        ->and($result->summary->finalizations)->toBe(9)
        ->and($result->summary->finalizationReturns)->toBe(1)
        ->and($result->summary->finalizationReturnRate)->toBe(0.1);
});

it('counts reentries as independent decisions and consumes canonical active duration without recalculation', function () {
    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => p3b2ReportingOperation($actor)->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->load('operation.emission');
    $visits = [
        p3b2ReportingVisit($measurement, MeasurementStageExitReason::ReturnedByRejection, stage: 2, sequence: 1, calendarDuration: 7200, activeDuration: 5400, pausedDuration: 1800),
        p3b2ReportingVisit($measurement, MeasurementStageExitReason::Approved, stage: 2, sequence: 2, calendarDuration: 3600, activeDuration: 3000, pausedDuration: 600, enteredAt: '2026-08-02 09:00:00', exitedAt: '2026-08-02 10:00:00'),
    ];
    $record = new AuthorizedMeasurementCycleHistory(
        measurement: $measurement,
        history: p3b2ReportingHistory($measurement, $visits),
    );

    $stageTwo = collect(p3b2ReportingServiceFor(collect([$record]))
        ->report($actor, new MeasurementCycleReportFilters)
        ->stageMetrics)->firstWhere('stage', 2);

    expect($stageTwo->decisions)->toBe(2)
        ->and($stageTwo->averageActiveDuration)->toBe(4200.0)
        ->and($stageTwo->pausedDurationTotal)->toBe(2400);
});

it('intersects every filter and retains the full duration when a visit enters before the period', function () {
    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $operation = p3b2ReportingOperation($actor);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->load('operation.emission');
    $visit = p3b2ReportingVisit(
        $measurement,
        MeasurementStageExitReason::Approved,
        stage: 3,
        calendarDuration: 172800,
        activeDuration: 160000,
        pausedDuration: 12800,
        actorId: $actor->getKey(),
        expectedResponsibleId: $actor->getKey(),
        enteredAt: '2026-07-30 10:00:00',
        exitedAt: '2026-08-01 10:00:00',
    );
    $record = new AuthorizedMeasurementCycleHistory(
        measurement: $measurement,
        history: p3b2ReportingHistory($measurement, [$visit]),
    );
    $otherActor = User::factory()->create();
    $actorMismatch = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->load('operation.emission');
    $actorMismatchRecord = new AuthorizedMeasurementCycleHistory(
        measurement: $actorMismatch,
        history: p3b2ReportingHistory($actorMismatch, [p3b2ReportingVisit(
            $actorMismatch,
            MeasurementStageExitReason::Approved,
            stage: 3,
            actorId: $otherActor->getKey(),
            expectedResponsibleId: $actor->getKey(),
            enteredAt: '2026-07-30 10:00:00',
            exitedAt: '2026-08-01 10:00:00',
        )]),
    );
    $otherOperation = p3b2ReportingOperation($actor);
    $operationMismatch = Measurement::factory()->create([
        'operation_id' => $otherOperation->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->load('operation.emission');
    $operationMismatchRecord = new AuthorizedMeasurementCycleHistory(
        measurement: $operationMismatch,
        history: p3b2ReportingHistory($operationMismatch, [p3b2ReportingVisit(
            $operationMismatch,
            MeasurementStageExitReason::Approved,
            stage: 3,
            actorId: $actor->getKey(),
            expectedResponsibleId: $actor->getKey(),
            enteredAt: '2026-07-30 10:00:00',
            exitedAt: '2026-08-01 10:00:00',
        )]),
    );
    $records = collect([$record, $actorMismatchRecord, $operationMismatchRecord]);
    $filters = MeasurementCycleReportFilters::fromArray([
        'period_from' => '2026-08-01',
        'period_to' => '2026-08-01',
        'operation_id' => $operation->getKey(),
        'emission_id' => $operation->emission_id,
        'stage' => 3,
        'decision_type' => 'approved',
        'actor_id' => $actor->getKey(),
        'expected_responsible_id' => $actor->getKey(),
        'completeness' => 'complete',
    ]);

    $result = p3b2ReportingServiceFor($records)->report($actor, $filters);
    $measurementResult = p3b2ReportingServiceFor($records)->report(
        $actor,
        MeasurementCycleReportFilters::fromArray([
            'measurement_id' => $measurement->getKey(),
        ]),
    );

    expect($result->totalRows)->toBe(1)
        ->and($result->rows[0]->measurementId)->toBe($measurement->getKey())
        ->and($result->rows[0]->enteredAt?->toDateTimeString())->toBe('2026-07-30 10:00:00')
        ->and($result->rows[0]->calendarDuration)->toBe(172800)
        ->and($result->rows[0]->activeDuration)->toBe(160000)
        ->and($measurementResult->totalRows)->toBe(1)
        ->and($measurementResult->rows[0]->measurementId)->toBe($measurement->getKey());
});

it('filters cycle duration by cycle end independently from stage visit exit time', function () {
    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => p3b2ReportingOperation($actor)->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->load('operation.emission');
    $visit = p3b2ReportingVisit(
        $measurement,
        MeasurementStageExitReason::Approved,
        enteredAt: '2026-08-01 09:00:00',
        exitedAt: '2026-08-01 10:00:00',
    );
    $record = new AuthorizedMeasurementCycleHistory(
        measurement: $measurement,
        history: p3b2ReportingHistory($measurement, [$visit], cycleDuration: 86400),
    );
    $filters = MeasurementCycleReportFilters::fromArray([
        'period_from' => '2026-08-02',
        'period_to' => '2026-08-02',
    ]);

    $result = p3b2ReportingServiceFor(collect([$record]))->report($actor, $filters);

    expect($result->totalRows)->toBe(0)
        ->and($result->summary->eligibleCycles)->toBe(1)
        ->and($result->summary->averageCycleDuration)->toBe(86400.0);
});

it('keeps current workload separate and groups each pending measurement once by permanent responsibility', function () {
    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $operation = p3b2ReportingOperation($actor);
    $measurements = Measurement::factory()->count(2)->create([
        'operation_id' => $operation->getKey(),
        'status' => 'pending',
        'current_stage' => 1,
    ])->each->load('operation.emission');
    $records = $measurements->map(fn (Measurement $measurement): AuthorizedMeasurementCycleHistory => new AuthorizedMeasurementCycleHistory(
        measurement: $measurement,
        history: p3b2ReportingHistory($measurement, []),
    ));
    $byId = $records->keyBy(fn (AuthorizedMeasurementCycleHistory $record): int => (int) $record->measurement->getKey());
    $batchReader = Mockery::mock(MeasurementCycleHistoryBatchReader::class);
    $batchReader->shouldReceive('read')->andReturnUsing(
        fn (User $user, iterable $ids): Collection => collect($ids)
            ->map(fn (mixed $id): AuthorizedMeasurementCycleHistory => $byId->get((int) $id))
            ->values(),
    );
    $sla = Mockery::mock(MeasurementSlaService::class);
    $sla->shouldReceive('evaluate')->twice()->andReturn(
        ['status' => MeasurementSlaService::STATUS_OVERDUE],
        ['status' => MeasurementSlaService::STATUS_ON_TIME],
    );
    $service = new MeasurementCycleReportingService(
        $batchReader,
        Mockery::mock(MeasurementCycleHistoryReadModel::class),
        $sla,
        Mockery::mock(MeasurementWorkflow::class),
    );

    $result = $service->report($actor, new MeasurementCycleReportFilters);

    expect($result->summary->decisions)->toBe(0)
        ->and($result->workload)->toHaveCount(1)
        ->and($result->workload[0]->responsibleId)->toBe($actor->getKey())
        ->and($result->workload[0]->pendingCount)->toBe(2)
        ->and($result->workload[0]->overdueCount)->toBe(1)
        ->and($result->workload[0]->delegatedCount)->toBeNull();
});

it('keeps the batch projection semantically identical to the individual canonical history', function () {
    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $operation = p3b2ReportingOperation($actor);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'in_review',
        'current_stage' => 2,
    ]);
    p3b2ReportingActivity($measurement, $actor, 'measurement_submitted', '2026-08-01 09:00:00', 1, [
        'stage' => 1,
        'from_status' => 'pending',
        'to_status' => 'in_review',
        'responsibility' => 'responsible_user_id',
    ]);
    p3b2ReportingActivity($measurement, $actor, 'measurement_stage_approved', '2026-08-01 10:00:00', 2, [
        'stage' => 1,
        'from_status' => 'in_review',
        'to_status' => 'in_review',
        'responsibility' => 'responsible_user_id',
    ]);

    $individual = app(MeasurementCycleHistoryReadModel::class)->for($actor, $measurement);
    $batch = app(MeasurementCycleHistoryBatchReader::class)->read($actor, [$measurement->getKey()]);

    expect($batch)->toHaveCount(1)
        ->and($batch->first()->history->toArray())->toBe($individual->toArray());
});

it('crosses a 205 measurement portfolio in bounded chunks without loss duplication or per-measurement query growth', function () {
    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $operation = p3b2ReportingOperation($actor);
    $measurements = Measurement::factory()->count(205)->create([
        'operation_id' => $operation->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->each->load('operation.emission');
    $records = $measurements->map(fn (Measurement $measurement): AuthorizedMeasurementCycleHistory => new AuthorizedMeasurementCycleHistory(
        measurement: $measurement,
        history: p3b2ReportingHistory($measurement, [p3b2ReportingVisit(
            $measurement,
            MeasurementStageExitReason::Approved,
        )]),
    ));
    $batchCalls = 0;
    $service = p3b2ReportingServiceFor($records, $batchCalls);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $rows = collect($service->stageVisitRows($actor, new MeasurementCycleReportFilters));
    $queryCount = count(DB::getQueryLog());

    expect($rows)->toHaveCount(205)
        ->and($rows->pluck('measurementId')->unique())->toHaveCount(205)
        ->and($batchCalls)->toBe(3)
        ->and($queryCount)->toBeLessThan(30);
});

it('paginates historical visit rows five at a time without changing order filters or metrics', function () {
    $actor = p3b2ReportingActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $operation = p3b2ReportingOperation($actor);
    $measurements = Measurement::factory()->count(7)->create([
        'operation_id' => $operation->getKey(),
        'status' => 'finalized',
        'current_stage' => 5,
    ])->each->load('operation.emission');
    $records = $measurements->map(fn (Measurement $measurement): AuthorizedMeasurementCycleHistory => new AuthorizedMeasurementCycleHistory(
        measurement: $measurement,
        history: p3b2ReportingHistory($measurement, [p3b2ReportingVisit(
            $measurement,
            MeasurementStageExitReason::Approved,
        )]),
    ));
    $service = p3b2ReportingServiceFor($records);

    $page1 = $service->report($actor, new MeasurementCycleReportFilters, page: 1, perPage: 5);
    $page2 = $service->report($actor, new MeasurementCycleReportFilters, page: 2, perPage: 5);
    $all = $service->report($actor, new MeasurementCycleReportFilters, page: 1, perPage: 10);

    expect($page1->rows)->toHaveCount(5)
        ->and($page1->totalRows)->toBe(7)
        ->and($page1->perPage)->toBe(5)
        ->and($page1->currentPage)->toBe(1)
        ->and($page2->rows)->toHaveCount(2)
        ->and($page2->totalRows)->toBe(7)
        ->and($page2->perPage)->toBe(5)
        ->and($page2->currentPage)->toBe(2)
        ->and($all->rows)->toHaveCount(7)
        ->and(collect($page1->rows)->merge($page2->rows)->pluck('measurementId')->all())
        ->toBe(collect($all->rows)->pluck('measurementId')->all())
        ->and($page2->summary)->toEqual($page1->summary)
        ->and($page2->coverage)->toEqual($page1->coverage)
        ->and($page2->stageMetrics)->toEqual($page1->stageMetrics);

    $filtered = $service->report(
        $actor,
        new MeasurementCycleReportFilters(measurementId: (int) $measurements->first()->getKey()),
        page: 1,
        perPage: 5,
    );

    expect($filtered->totalRows)->toBe(1)
        ->and($filtered->rows)->toHaveCount(1)
        ->and($filtered->rows[0]->measurementId)->toBe((int) $measurements->first()->getKey());
});

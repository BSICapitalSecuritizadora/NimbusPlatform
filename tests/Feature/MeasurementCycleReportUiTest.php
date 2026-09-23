<?php

use App\DTOs\Measurements\MeasurementCurrentWorkload;
use App\DTOs\Measurements\MeasurementCycleEvent;
use App\DTOs\Measurements\MeasurementCycleHistory;
use App\DTOs\Measurements\MeasurementCyclePayment;
use App\DTOs\Measurements\MeasurementCycleReportResult;
use App\DTOs\Measurements\MeasurementCycleReportRow;
use App\DTOs\Measurements\MeasurementCycleReportSummary;
use App\DTOs\Measurements\MeasurementHistoricalCoverage;
use App\DTOs\Measurements\MeasurementStageMetrics;
use App\DTOs\Measurements\MeasurementStageVisit;
use App\Enums\AccessPermission;
use App\Enums\MeasurementCycleEventType;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementHistorySourceType;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use App\Filament\Pages\MeasurementCycleReport;
use App\Models\User;
use App\Services\MeasurementCycleReportingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function p3b2UiActor(array $permissions): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

function p3b2UiResult(): MeasurementCycleReportResult
{
    $row = new MeasurementCycleReportRow(
        measurementId: 10,
        measurementLabel: 'Medição Gerencial',
        operationId: 20,
        operationLabel: 'OP-20 — Operação Visível',
        emissionId: 30,
        emissionLabel: 'Emissão Visível',
        referenceMonth: '2026-08-01',
        stage: 2,
        sequence: 1,
        enteredAt: CarbonImmutable::parse('2026-08-01 09:00:00'),
        exitedAt: CarbonImmutable::parse('2026-08-01 10:00:00'),
        exitReason: MeasurementStageExitReason::Approved,
        calendarDuration: null,
        pausedDuration: null,
        activeDuration: null,
        actorId: null,
        actorName: null,
        responsibility: MeasurementResponsibility::ManagementReviewer,
        expectedResponsibleId: null,
        expectedResponsibleName: null,
        delegated: null,
        delegatorId: null,
        delegatorName: null,
        adminOverride: null,
        completeness: MeasurementHistoryCompleteness::Partial,
        missingReasons: ['actor_unknown'],
    );
    $stageMetrics = collect(range(1, 5))->map(fn (int $stage): MeasurementStageMetrics => new MeasurementStageMetrics(
        stage: $stage,
        decisions: $stage === 2 ? 1 : 0,
        approvals: $stage === 2 ? 1 : 0,
        rejections: 0,
        finalizations: 0,
        finalizationReturns: 0,
        rejectionRate: $stage === 2 ? 0.0 : null,
        finalizationReturnRate: null,
        averageCalendarDuration: null,
        medianCalendarDuration: null,
        averageActiveDuration: null,
        medianActiveDuration: null,
        pausedDurationTotal: 0,
        averagePausedDuration: null,
        completeCohort: $stage === 2 ? 1 : 0,
        partialExcluded: $stage === 2 ? 1 : 0,
        insufficientExcluded: 0,
    ))->all();

    return new MeasurementCycleReportResult(
        summary: new MeasurementCycleReportSummary(
            decisions: 1,
            approvals: 1,
            rejections: 0,
            rejectionRate: 0.0,
            finalizationReturns: 0,
            finalizations: 0,
            finalizationReturnRate: null,
            pausedDurationTotal: 0,
            averageCycleDuration: null,
            medianCycleDuration: null,
            eligibleStageVisits: 1,
            eligibleCycles: 0,
        ),
        coverage: new MeasurementHistoricalCoverage(complete: 1, partial: 1, insufficient: 0),
        stageMetrics: $stageMetrics,
        rows: [$row],
        totalRows: 1,
        currentPage: 1,
        perPage: 25,
        workload: [new MeasurementCurrentWorkload(
            responsibleId: null,
            responsibleName: 'Não configurado',
            responsibility: MeasurementResponsibility::ManagementReviewer,
            stage: 2,
            pendingCount: 4,
            overdueCount: 1,
            delegatedCount: null,
        )],
        operationOptions: [20 => 'OP-20 — Operação Visível'],
        emissionOptions: [30 => 'Emissão Visível'],
        measurementOptions: [10 => 'Medição Gerencial'],
        actorOptions: [],
        responsibleOptions: [],
    );
}

function p3b2UiVisit(
    int $sequence,
    MeasurementStageExitReason $exitReason,
    MeasurementHistoryCompleteness $completeness,
    ?int $actorId,
): MeasurementStageVisit {
    $enteredAt = CarbonImmutable::parse('2026-08-01 08:00:00')->addDays($sequence - 1);

    return new MeasurementStageVisit(
        measurementId: 10,
        stage: 2,
        sequence: $sequence,
        enteredAt: $enteredAt,
        exitedAt: $enteredAt->addHour(),
        exitReason: $exitReason,
        decisionEvent: null,
        exitActorId: $actorId,
        responsibility: MeasurementResponsibility::ManagementReviewer,
        expectedResponsibleId: $actorId,
        delegated: $actorId !== null ? true : null,
        delegationId: null,
        delegatorId: $actorId,
        delegationScope: null,
        adminOverride: $actorId !== null ? false : null,
        revisionStart: 1,
        revisionEnd: 2,
        calendarDuration: 3600,
        pausedDuration: 0,
        activeDuration: 3600,
        completeness: $completeness,
        missingReasons: $completeness === MeasurementHistoryCompleteness::Complete ? [] : ['actor_unknown'],
    );
}

function p3b2UiDetail(
    MeasurementHistoryCompleteness $completeness = MeasurementHistoryCompleteness::Partial,
    ?int $actorId = null,
): MeasurementCycleHistory {
    $event = new MeasurementCycleEvent(
        sourceActivityId: 99,
        sourceEvent: 'measurement_receipt_attached',
        sourceType: MeasurementHistorySourceType::WorkflowActivity,
        measurementId: 10,
        operationId: 20,
        occurredAt: CarbonImmutable::parse('2026-08-01 10:00:00'),
        eventType: MeasurementCycleEventType::ReceiptAttached,
        stageBefore: 5,
        stageAfter: 5,
        statusBefore: 'awaiting_receipt',
        statusAfter: 'approved',
        actorId: $actorId,
        responsibility: MeasurementResponsibility::ReceiptUploader,
        expectedResponsibleId: $actorId,
        delegated: $actorId !== null ? true : null,
        delegationId: null,
        delegatorId: $actorId,
        delegationScope: null,
        adminOverride: $actorId !== null ? false : null,
        workflowRevision: 8,
        reason: null,
        paymentIds: [50],
        paymentAmount: null,
        completeness: $completeness,
        missingReasons: $completeness === MeasurementHistoryCompleteness::Complete ? [] : ['actor_unknown'],
    );

    return new MeasurementCycleHistory(
        measurementId: 10,
        operationId: 20,
        referenceMonth: '2026-08-01',
        measurementLabel: 'Medição Gerencial',
        currentStatus: 'approved',
        currentStage: 5,
        events: [$event],
        stageVisits: [
            p3b2UiVisit(1, MeasurementStageExitReason::ReturnedByRejection, $completeness, $actorId),
            p3b2UiVisit(2, MeasurementStageExitReason::Approved, $completeness, $actorId),
        ],
        pauses: [],
        payments: [new MeasurementCyclePayment(
            id: 50,
            measurementId: 10,
            amount: '1250.75',
            payDate: CarbonImmutable::parse('2026-08-02'),
            method: 'TED',
            createdById: null,
            createdAt: CarbonImmutable::parse('2026-08-01 09:30:00'),
        )],
        cycleStart: CarbonImmutable::parse('2026-08-01 08:00:00'),
        cycleEnd: null,
        terminalReason: null,
        completeness: $completeness,
        warnings: $completeness === MeasurementHistoryCompleteness::Complete ? [] : ['actor_unknown'],
    );
}

it('requires measurements view together with cycle report view for page access', function () {
    $reportOnly = p3b2UiActor([AccessPermission::MeasurementsCycleReportsView->value]);
    $measurementOnly = p3b2UiActor([AccessPermission::MeasurementsView->value]);
    $authorized = p3b2UiActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);

    $this->actingAs($reportOnly);
    expect(MeasurementCycleReport::canAccess())->toBeFalse();

    $this->actingAs($measurementOnly);
    expect(MeasurementCycleReport::canAccess())->toBeFalse();

    $this->actingAs($authorized);
    expect(MeasurementCycleReport::canAccess())->toBeTrue();
});

it('renders the dedicated historical report with explicit coverage N D and separate current workload', function () {
    $actor = p3b2UiActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $this->actingAs($actor);
    $this->mock(MeasurementCycleReportingService::class, function ($mock): void {
        $mock->shouldReceive('report')->andReturn(p3b2UiResult());
    });

    Livewire::test(MeasurementCycleReport::class)
        ->assertSee('Relatório do Ciclo de Medições')
        ->assertSee('Cobertura histórica')
        ->assertSee('Carga operacional atual')
        ->assertSee('Medição Gerencial')
        ->assertSee('N/D')
        ->assertDontSee('SLA histórico')
        ->assertDontSee('storage_path')
        ->assertDontSee('sha256');
});

it('never grants export controls without the independent export permission', function () {
    $viewer = p3b2UiActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $exporter = p3b2UiActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
        AccessPermission::MeasurementsCycleReportsExport->value,
    ]);
    $this->mock(MeasurementCycleReportingService::class, function ($mock): void {
        $mock->shouldReceive('report')->andReturn(p3b2UiResult());
    });

    $this->actingAs($viewer);
    Livewire::test(MeasurementCycleReport::class)->assertDontSee('Exportar XLSX');

    $this->actingAs($exporter);
    Livewire::test(MeasurementCycleReport::class)->assertSee('Exportar XLSX');
});

it('lets metric drilldown refine compatible filters but never replace a conflicting base filter', function () {
    $actor = p3b2UiActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $this->actingAs($actor);
    $this->mock(MeasurementCycleReportingService::class, function ($mock): void {
        $mock->shouldReceive('report')->andReturn(p3b2UiResult());
    });

    Livewire::test(MeasurementCycleReport::class)
        ->set('decisionType', MeasurementStageExitReason::RejectedTerminal->value)
        ->set('completeness', MeasurementHistoryCompleteness::Partial->value)
        ->call('applyMetricDrilldown', MeasurementStageExitReason::Approved->value)
        ->assertSet('decisionType', MeasurementStageExitReason::RejectedTerminal->value)
        ->assertSet('completeness', MeasurementHistoryCompleteness::Partial->value);

    Livewire::test(MeasurementCycleReport::class)
        ->call('applyMetricDrilldown', MeasurementStageExitReason::Approved->value)
        ->assertSet('decisionType', MeasurementStageExitReason::Approved->value)
        ->assertSet('completeness', MeasurementHistoryCompleteness::Complete->value);
});

it('renders canonical detail with translated receipt partial tri state payment and sanitized audit metadata', function () {
    $actor = p3b2UiActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $this->actingAs($actor);
    $this->mock(MeasurementCycleReportingService::class, function ($mock): void {
        $mock->shouldReceive('report')->andReturn(p3b2UiResult());
        $mock->shouldReceive('detail')->withArgs(fn (User $user, int $id): bool => $id === 10)
            ->andReturn(p3b2UiDetail());
    });

    Livewire::test(MeasurementCycleReport::class)
        ->call('showDetail', 10)
        ->assertSee('Comprovante anexado')
        ->assertSee('Parte do histórico está disponível')
        ->assertSee('O actor da época não pôde ser comprovado')
        ->assertSee('Activity de workflow')
        ->assertSee('visita #1')
        ->assertSee('visita #2')
        ->assertSee('TED')
        ->assertSee('N/D')
        ->assertDontSee('properties')
        ->assertDontSee('receipt_path')
        ->assertDontSee('receipt_disk')
        ->assertDontSee('sha256');
});

it('explains every canonical detail coverage state without converting unknown values to zero', function (
    MeasurementHistoryCompleteness $completeness,
    string $expectedExplanation,
) {
    $actor = p3b2UiActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $this->actingAs($actor);
    $this->mock(MeasurementCycleReportingService::class, function ($mock) use ($actor, $completeness): void {
        $mock->shouldReceive('report')->andReturn(p3b2UiResult());
        $detailActorId = $completeness === MeasurementHistoryCompleteness::Complete
            ? (int) $actor->getKey()
            : null;
        $mock->shouldReceive('detail')->andReturn(p3b2UiDetail($completeness, $detailActorId));
    });

    $component = Livewire::test(MeasurementCycleReport::class)
        ->call('showDetail', 10)
        ->assertSee($expectedExplanation)
        ->assertSee('N/D')
        ->assertDontSee('0 min');

    if ($completeness === MeasurementHistoryCompleteness::Complete) {
        $component
            ->assertSee($actor->name)
            ->assertSee('Sim')
            ->assertSee('Não');
    }
})->with([
    'complete' => [MeasurementHistoryCompleteness::Complete, 'A evidência necessária para reconstruir o ciclo está disponível'],
    'partial' => [MeasurementHistoryCompleteness::Partial, 'Parte do histórico está disponível'],
    'insufficient' => [MeasurementHistoryCompleteness::Insufficient, 'Não há evidência histórica suficiente'],
]);

it('renders institutional date picker fields for historical filters and preserves date filtering', function () {
    $actor = p3b2UiActor([
        AccessPermission::MeasurementsView->value,
        AccessPermission::MeasurementsCycleReportsView->value,
    ]);
    $this->actingAs($actor);
    $this->mock(MeasurementCycleReportingService::class, function ($mock): void {
        $mock->shouldReceive('report')->andReturn(p3b2UiResult());
    });

    Livewire::test(MeasurementCycleReport::class)
        ->assertSee('Saída desde')
        ->assertSee('Saída até')
        ->assertSee('mcrDatePicker', false)
        ->assertSee('mcr-datepicker-panel', false)
        ->assertSee('mcr-datepicker-input', false)
        ->set('periodFrom', '2026-08-01')
        ->set('periodTo', '2026-08-10')
        ->assertSet('periodFrom', '2026-08-01')
        ->assertSet('periodTo', '2026-08-10')
        ->call('clearFilters')
        ->assertSet('periodFrom', '')
        ->assertSet('periodTo', '');
});

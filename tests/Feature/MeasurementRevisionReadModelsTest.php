<?php

use App\DTOs\Measurements\MeasurementCycleReportFilters;
use App\Enums\AccessPermission;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Models\User;
use App\Services\MeasurementCockpitService;
use App\Services\MeasurementCycleHistoryReadModel;
use App\Services\MeasurementCycleReportingService;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementPendingService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementStageActivityService;
use App\Services\MeasurementTimeline;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementRevisionScenario as Scenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

it('takes the suspended measurement out of every work queue while its revision is under review', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = Scenario::submit($scenario, Scenario::revise($scenario, $may));
    $pendings = app(MeasurementPendingService::class)->summaryFor($scenario['actor'], previewLimit: 10);

    expect(collect($pendings['items'])->pluck('measurement_id')->all())->toBe([$revision->id])
        ->and($pendings['items'][0]['revision_label'])->toBe('Revisão R1')
        ->and(app(MeasurementSlaService::class)->evaluate($may->fresh())['status'])->toBe(MeasurementSlaService::STATUS_SUSPENDED_BY_REVISION)
        ->and(collect(app(MeasurementStageActivityService::class)->for($may->fresh()))->last()['status_label'])->toBe('Suspensa pela revisão em análise');

    $this->actingAs($scenario['actor']);

    Livewire::test(ListPaymentWorkspace::class)
        ->assertCanSeeTableRecords([$may])
        ->filterTable('operational_pending', 'awaiting_registration')
        ->assertCanNotSeeTableRecords([$may]);
});

it('closes the payment visit of the replaced measurement in the cycle history without counting it as a finished cycle', function () {
    $scenario = Scenario::plan();
    $scenario['actor']->givePermissionTo([AccessPermission::MeasurementsCycleReportsView->value]);
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 8);
    $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $may->fresh());
    $lastVisit = collect($history->stageVisits)->last();

    expect($history->completeness)->toBe(MeasurementHistoryCompleteness::Complete)
        ->and($history->terminalReason)->toBe(MeasurementStageExitReason::ClosedByRevision)
        ->and($lastVisit->stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
        ->and($lastVisit->exitReason)->toBe(MeasurementStageExitReason::ClosedByRevision)
        ->and($lastVisit->exitActorId)->toBe($scenario['actor']->id)
        // A autoridade é a da Compliance que fez a revisão valer, não a do
        // Gestor de Pagamento.
        ->and($lastVisit->responsibility)->toBe(MeasurementResponsibility::ComplianceReviewer);

    $report = app(MeasurementCycleReportingService::class)->report($scenario['actor'], new MeasurementCycleReportFilters);

    expect($report->summary->eligibleCycles)->toBe(0)
        ->and($report->measurementOptions)->toHaveKey($revision->id)
        ->and($report->measurementOptions[$revision->id])->toContain('· R1');
});

it('leaves never submitted revisions out of the cycle report', function () {
    $scenario = Scenario::plan();
    $scenario['actor']->givePermissionTo([AccessPermission::MeasurementsCycleReportsView->value]);
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $reporting = app(MeasurementCycleReportingService::class);

    expect($reporting->report($scenario['actor'], new MeasurementCycleReportFilters)->measurementOptions)->not->toHaveKey($draft->id)
        ->and(fn () => $reporting->detail($scenario['actor'], $draft->id, new MeasurementCycleReportFilters))
        ->toThrow(ModelNotFoundException::class);
});

it('counts the current revisions in the cockpit, leaves the replaced ones out and keeps every payment in the totals', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $cockpit = app(MeasurementCockpitService::class);

    // Revisão em andamento é trabalho próprio: conta ao lado da vigente.
    expect($cockpit->summaryFor($scenario['actor'])['total'])->toBe(2)
        ->and($cockpit->summaryFor($scenario['actor'])['finalized'])->toBe(1);

    app(MeasurementRevisionService::class)->cancel($draft->fresh(), $scenario['actor'], 'Desnecessária.');
    Scenario::effective($scenario, $may, 12);
    $summary = $cockpit->summaryFor($scenario['actor']);

    expect($summary['total'])->toBe(1)
        ->and($summary['finalized'])->toBe(0)
        ->and($summary['stages'][MeasurementWorkflow::STAGE_PAYMENT])->toBe(1)
        ->and($summary['payment_count'])->toBe(1)
        ->and($summary['recorded_amount'])->toBe(100000.0);
});

it('tells the story of a revision in its timeline', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12, 'Percentual de maio subestimado.');
    $titles = app(MeasurementTimeline::class)->for($revision->fresh())->pluck('title')->all();

    expect($titles[0])->toBe('Revisão R1 criada como rascunho')
        ->and($titles)->toContain('Revisão R1 enviada para análise')
        ->and($titles)->toContain('Aprovada na Compliance — aguardando pagamento')
        ->and($titles)->not->toContain('Etapa 0 aprovada — avançou para Etapa 1');
});

it('names who submitted the revision, which may differ from who created it', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);
    $submitter = User::factory()->withTwoFactor()->create(['name' => 'Quem envia']);
    $submitter->givePermissionTo(['measurements.view', AccessPermission::MeasurementsRevise->value]);
    $scenario['operation']->forceFill(['assigned_user_id' => $submitter->id])->save();

    app(MeasurementRevisionService::class)->submit($draft->fresh(), $submitter->fresh());
    $submitted = app(MeasurementTimeline::class)->for($draft->fresh())->firstWhere('title', 'Revisão R1 enviada para análise');

    expect($draft->fresh()->uploaded_by)->toBe($submitter->id)
        ->and($draft->fresh()->revision_created_by)->toBe($scenario['actor']->id)
        ->and($submitted['actor'])->toBe('Quem envia');
});

it('shows a revision whose family already paid as waiting for approval in the payment workspace', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 10);
    $this->actingAs($scenario['actor']);

    expect(app(MeasurementOperationalReadModel::class)->pendingLabel(
        MeasurementResource::getEloquentQuery()->withCount('payments')->findOrFail($revision->id),
    ))->toBe('Aguardando aprovação');

    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('operational_pending', 'awaiting_approval')
        ->assertCanSeeTableRecords([$revision])
        ->filterTable('operational_pending', 'awaiting_registration')
        ->assertCanNotSeeTableRecords([$revision]);
});

it('gives a draft revision no deadline', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $draft = Scenario::revise($scenario, $may);

    expect(app(MeasurementSlaService::class)->evaluate($draft->fresh())['status'])->toBe(MeasurementSlaService::STATUS_NOT_APPLICABLE);
});

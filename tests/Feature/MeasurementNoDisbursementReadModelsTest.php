<?php

use App\DTOs\Measurements\MeasurementCycleEvent;
use App\DTOs\Measurements\MeasurementCycleReportFilters;
use App\DTOs\Measurements\MeasurementStageMetrics;
use App\DTOs\Measurements\MeasurementStageVisit;
use App\Enums\AccessPermission;
use App\Enums\MeasurementCycleEventType;
use App\Enums\MeasurementHistoryCompleteness;
use App\Enums\MeasurementHistorySourceType;
use App\Enums\MeasurementOperationalExceptionType;
use App\Enums\MeasurementResponsibility;
use App\Enums\MeasurementStageExitReason;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Filament\Widgets\Dashboard\MyPendingsWidget;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementCockpitService;
use App\Services\MeasurementCycleHistoryReadModel;
use App\Services\MeasurementCycleReportingService;
use App\Services\MeasurementOperationalExceptionReadModel;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementPaymentExportService;
use App\Services\MeasurementPendingService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementStageActivityService;
use App\Services\MeasurementTimeline;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\MeasurementNoDisbursementScenario as Scenario;
use Tests\Support\MeasurementRevisionScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Finalizador próprio da operação, separado de quem decide a etapa Pagamento:
 * a pendência da Finalização e o aviso da decisão sem desembolso precisam
 * chegar a quem finaliza, e não ao Gestor de Pagamento. Atribuído antes de a
 * medição existir, como numa operação configurada desde o início.
 *
 * @param  array{actor: User, operation: Operation, planSets: list<MeasurementPlanSet>, lines: list<array<string, MeasurementPlanLine>>}  $scenario
 */
function p4aReadFinalizer(array $scenario): User
{
    $finalizer = User::factory()->withTwoFactor()->create(['name' => 'Finalizadora da Operação']);
    $finalizer->givePermissionTo(['measurements.view', 'measurements.finalize']);
    $scenario['operation']->forceFill(['payment_finalizer_user_id' => $finalizer->id])->save();

    return $finalizer;
}

/**
 * As pendências de My Pendings como pares [medição, ação], na ordem do
 * serviço -- pares, e não um mapa, para uma segunda ação da mesma medição não
 * sumir por cima da primeira.
 *
 * @param  array{items: list<array<string, mixed>>, count: int}  $summary
 * @return list<array{0: int, 1: string}>
 */
function p4aReadPendingActions(array $summary): array
{
    expect($summary['count'])->toBe(count($summary['items']));

    return collect($summary['items'])
        ->map(fn (array $item): array => [(int) $item['measurement_id'], (string) $item['action_label']])
        ->all();
}

/**
 * As linhas da exportação CSV da área de pagamentos; o arquivo temporário é
 * apagado depois da leitura.
 *
 * @return Collection<int, array<string, mixed>>
 */
function p4aReadExportRows(BinaryFileResponse $response): Collection
{
    $path = $response->getFile()->getPathname();
    $reader = SimpleExcelReader::create($path, 'csv');
    $reader->useDelimiter(';');

    $rows = collect($reader->getRows()->all());
    $reader->close();
    unlink($path);

    return $rows;
}

/**
 * A linha da decisão de uma etapa no histórico de etapas da medição.
 *
 * @param  Collection<int, array<string, mixed>>  $rows
 * @return array<string, mixed>|null
 */
function p4aReadStageRow(Collection $rows, int $stage, string $statusKey = 'approved'): ?array
{
    return $rows->first(fn (array $row): bool => $row['stage'] === $stage && $row['status_key'] === $statusKey);
}

// ── Área de pagamentos e cockpit ─────────────────────────────────────────────

it('routes the zero-due original to the no-disbursement decision in the payment workspace and keeps the payable one waiting for the registration', function () {
    $scenario = Scenario::plan();
    $zeroDue = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $payable = Scenario::awaitingPayment($scenario, '2026-06', [10]);
    $readModel = app(MeasurementOperationalReadModel::class);
    $this->actingAs($scenario['actor']);

    expect($readModel->awaitingNoDisbursementDecisionIds($readModel->paymentQueryFor($scenario['actor'])))->toBe([$zeroDue->id]);

    // A original de maio não espera registro: espera a decisão sem desembolso,
    // e a pendência, a ação da linha e o filtro dizem a mesma coisa. A de
    // junho tem R$ 100.000,00 a pagar e continua esperando o registro.
    Livewire::test(ListPaymentWorkspace::class)
        ->assertCanSeeTableRecords([$zeroDue, $payable])
        ->assertTableColumnStateSet('operational_pending', 'Nenhum pagamento devido · concluir sem desembolso', $zeroDue)
        ->assertTableColumnStateSet('operational_pending', 'Aguardando registro', $payable)
        ->assertTableActionHasLabel('approve_payment_stage', 'Concluir etapa sem desembolso', $zeroDue)
        ->assertTableActionHasLabel('approve_payment_stage', 'Aprovar etapa Pagamento', $payable)
        ->filterTable('operational_pending', 'awaiting_approval')
        ->assertCanSeeTableRecords([$zeroDue])
        ->assertCanNotSeeTableRecords([$payable])
        ->filterTable('operational_pending', 'awaiting_registration')
        ->assertCanSeeTableRecords([$payable])
        ->assertCanNotSeeTableRecords([$zeroDue]);

    // Ler as filas não grava nada: nenhum pagamento, nem de valor zero.
    expect(MeasurementPayment::query()->count())->toBe(0)
        ->and($zeroDue->fresh()->only(['status', 'current_stage']))->toBe(['status' => 'awaiting_payment', 'current_stage' => MeasurementWorkflow::STAGE_PAYMENT]);
});

it('never routes an original whose amount due cannot be determined to the no-disbursement decision', function (array $developments, array $percents) {
    $scenario = Scenario::plan($developments);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', $percents);
    $readModel = app(MeasurementOperationalReadModel::class);
    $this->actingAs($scenario['actor']);

    // Sem Fundo de Obra o valor devido é indeterminado, nunca zero: a medição
    // segue esperando o registro do pagamento em todas as leituras.
    expect($readModel->awaitingNoDisbursementDecisionIds($readModel->paymentQueryFor($scenario['actor'])))->toBe([])
        ->and(p4aReadPendingActions(app(MeasurementPendingService::class)->summaryFor($scenario['actor'], previewLimit: 10)))
        ->toBe([[$measurement->id, 'Registrar e aprovar pagamento']]);

    Livewire::test(ListPaymentWorkspace::class)
        ->assertTableColumnStateSet('operational_pending', 'Aguardando registro', $measurement)
        ->assertTableActionHasLabel('approve_payment_stage', 'Aprovar etapa Pagamento', $measurement)
        ->filterTable('operational_pending', 'awaiting_registration')
        ->assertCanSeeTableRecords([$measurement])
        ->filterTable('operational_pending', 'awaiting_approval')
        ->assertCanNotSeeTableRecords([$measurement]);
})->with([
    'single construction without Construction Fund' => [[['fund' => null]], [0]],
    'zero-due construction next to one without Construction Fund' => [[['fund' => '1000000.00'], ['fund' => null]], [0, 0]],
]);

it('shows the zero-disbursement original as ready to finalize with the acknowledgment and then as concluded', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $cockpit = app(MeasurementCockpitService::class);
    $this->actingAs($scenario['actor']);

    expect($cockpit->summaryFor($scenario['actor'])['ready_to_finalize'])->toBe(0);

    $measurement = Scenario::concludeWithoutDisbursement($scenario, $measurement);

    // Na Finalização não há comprovante a esperar -- não houve pagamento --, e
    // a pendência já diz que finalizar pede a ciência da decisão.
    Livewire::test(ListPaymentWorkspace::class)
        ->assertTableColumnStateSet('operational_pending', 'Finalizar com ciência da ausência de pagamento', $measurement)
        ->assertTableActionHidden('approve_payment_stage', $measurement)
        // Fase 4B-1: a Finalização é do Finalizador, não de quem decidiu sem desembolso.
        ->assertTableActionHidden('finalize', $measurement)
        ->filterTable('operational_pending', 'ready_to_finalize')
        ->assertCanSeeTableRecords([$measurement])
        ->filterTable('operational_pending', 'awaiting_approval')
        ->assertCanNotSeeTableRecords([$measurement])
        ->filterTable('operational_pending', 'awaiting_receipt')
        ->assertCanNotSeeTableRecords([$measurement]);

    $this->actingAs($scenario['finalizer']);
    Livewire::test(ListPaymentWorkspace::class)
        ->assertTableActionVisible('finalize', $measurement);
    $this->actingAs($scenario['actor']);

    Livewire::test(ListMeasurements::class)
        ->assertTableColumnHasDescription('status', 'Finalizar com ciência da ausência de pagamento', $measurement);

    expect($cockpit->summaryFor($scenario['actor'])['ready_to_finalize'])->toBe(1);

    $measurement = Scenario::finalizeAcknowledging($scenario, $measurement);

    Livewire::test(ListPaymentWorkspace::class)
        ->assertTableColumnStateSet('operational_pending', 'Concluída', $measurement)
        ->filterTable('operational_pending', 'ready_to_finalize')
        ->assertCanNotSeeTableRecords([$measurement]);

    expect($cockpit->summaryFor($scenario['actor']))->toMatchArray(['ready_to_finalize' => 0, 'finalized' => 1, 'payment_count' => 0]);
});

// ── My Pendings ──────────────────────────────────────────────────────────────

it('asks the Payment Manager for the no-disbursement decision and the Finalizer for the acknowledgment in My Pendings', function () {
    $scenario = Scenario::plan();
    $finalizer = p4aReadFinalizer($scenario);
    $zeroDue = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $payable = Scenario::awaitingPayment($scenario, '2026-06', [10]);
    $pendings = app(MeasurementPendingService::class);

    expect(p4aReadPendingActions($pendings->summaryFor($scenario['actor'], previewLimit: 10)))->toBe([
        [$zeroDue->id, 'Concluir etapa sem desembolso'],
        [$payable->id, 'Registrar e aprovar pagamento'],
    ])
        ->and($pendings->summaryFor($finalizer)['count'])->toBe(0);

    $this->actingAs($scenario['actor']);
    Livewire::test(MyPendingsWidget::class)
        ->assertSuccessful()
        ->assertSee('Concluir etapa sem desembolso')
        ->assertSee('Registrar e aprovar pagamento');

    $zeroDue = Scenario::concludeWithoutDisbursement($scenario, $zeroDue);
    $finalizerSummary = $pendings->summaryFor($finalizer, previewLimit: 10);

    // A pendência passa ao Finalizador, e ninguém fica com comprovante a
    // enviar: não houve pagamento.
    expect(p4aReadPendingActions($finalizerSummary))->toBe([[$zeroDue->id, 'Finalizar com ciência da ausência de pagamento']])
        ->and($finalizerSummary['items'][0]['responsibility'])->toBe(MeasurementResponsibility::Finalizer->value)
        ->and(p4aReadPendingActions($pendings->summaryFor($scenario['actor'], previewLimit: 10)))->toBe([[$payable->id, 'Registrar e aprovar pagamento']]);

    $this->actingAs($finalizer);
    Livewire::test(MyPendingsWidget::class)
        ->assertSuccessful()
        ->assertSee('Finalizar com ciência da ausência de pagamento');

    Scenario::finalizeAcknowledging($scenario, $zeroDue, $finalizer);

    expect($pendings->summaryFor($finalizer)['count'])->toBe(0)
        ->and(p4aReadPendingActions($pendings->summaryFor($scenario['actor'], previewLimit: 10)))->toBe([[$payable->id, 'Registrar e aprovar pagamento']]);
});

// ── Linha do tempo e histórico de etapas ─────────────────────────────────────

it('tells in the timeline that the Payment stage ended without disbursement, and keeps the approval title of a paid original', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::finalizeAcknowledging($scenario, Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0])));
    $titles = app(MeasurementTimeline::class)->for($measurement->fresh())->pluck('title');

    expect($titles->filter(fn (string $title): bool => str_starts_with($title, 'Etapa 4'))->values()->all())
        ->toBe(['Etapa 4 concluída sem desembolso — avançou para Finalização'])
        ->and($titles)->toContain('Medição finalizada')
        ->and($titles->contains(fn (string $title): bool => str_starts_with($title, 'Pagamento registrado')))->toBeFalse();

    $paidScenario = MeasurementRevisionScenario::plan();
    $paid = MeasurementRevisionScenario::finalized($paidScenario, '2026-05', 10);
    $paidTitles = app(MeasurementTimeline::class)->for($paid->fresh())->pluck('title');

    expect($paidTitles->filter(fn (string $title): bool => str_starts_with($title, 'Etapa 4'))->values()->all())
        ->toBe(['Etapa 4 aprovada — avançou para Finalização'])
        ->and($paidTitles->contains(fn (string $title): bool => str_starts_with($title, 'Pagamento registrado')))->toBeTrue();
});

it('shows the no-disbursement justification and the acknowledgment in the stage history', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));
    $stages = app(MeasurementStageActivityService::class);

    // A nota da etapa Pagamento é a justificativa da decisão, não observação.
    expect(p4aReadStageRow($stages->for($measurement->fresh()), MeasurementWorkflow::STAGE_PAYMENT))->toMatchArray([
        'stage_label' => MeasurementWorkflow::STAGE_LABELS[MeasurementWorkflow::STAGE_PAYMENT],
        'reviewer_name' => $scenario['actor']->name,
        'status_label' => 'Concluída sem desembolso',
        'notes' => Scenario::JUSTIFICATION,
        'note_label' => 'Justificativa',
    ]);

    $measurement = Scenario::finalizeAcknowledging($scenario, $measurement);
    $rows = $stages->for($measurement->fresh());

    expect(p4aReadStageRow($rows, MeasurementWorkflow::STAGE_FINALIZATION))->toMatchArray([
        'status_label' => 'Finalizada',
        'note_label' => 'Ciência',
    ])
        ->and(p4aReadStageRow($rows, MeasurementWorkflow::STAGE_FINALIZATION)['notes'])->toStartWith('Ciência da ausência de pagamento confirmada')
        ->and(p4aReadStageRow($rows, MeasurementWorkflow::STAGE_PAYMENT))->toMatchArray([
            'status_label' => 'Concluída sem desembolso',
            'note_label' => 'Justificativa',
        ]);

    // A original paga segue com a aprovação e a observação de sempre.
    $paidScenario = MeasurementRevisionScenario::plan();
    $paidRows = $stages->for(MeasurementRevisionScenario::finalized($paidScenario, '2026-05', 10)->fresh());

    expect(p4aReadStageRow($paidRows, MeasurementWorkflow::STAGE_PAYMENT))->toMatchArray(['status_label' => 'Aprovada', 'note_label' => 'Observação'])
        ->and(p4aReadStageRow($paidRows, MeasurementWorkflow::STAGE_FINALIZATION))->toMatchArray(['status_label' => 'Finalizada', 'note_label' => 'Observação']);
});

// ── Exportação ───────────────────────────────────────────────────────────────

it('exports the zero-disbursement original as without disbursement, not as a payment that was never registered', function () {
    $scenario = Scenario::plan();
    $scenario['actor']->givePermissionTo(AccessPermission::MeasurementsExport->value);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $export = fn (): Collection => p4aReadExportRows(app(MeasurementPaymentExportService::class)->download(
        app(MeasurementOperationalReadModel::class)->paymentQueryFor($scenario['actor']),
        $scenario['actor'],
        'csv',
    ));

    // Antes da decisão a linha é a de qualquer medição sem pagamento.
    expect($export()->pluck('Status do comprovante')->all())->toBe(['Pagamento não registrado']);

    Scenario::concludeWithoutDisbursement($scenario, $measurement);
    $rows = $export();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()['Medição'])->toBe($measurement->id)
        ->and($rows->first()['Status do comprovante'])->toBe('Sem desembolso — nenhum pagamento devido')
        ->and($rows->first()['Valor'])->toBeEmpty()
        ->and($rows->first()['Aprovado por'])->toBe($scenario['actor']->name)
        ->and($rows->first()['Revisão'])->toBe('R0 · Original');

    Scenario::finalizeAcknowledging($scenario, $measurement);

    expect($export()->pluck('Status do comprovante')->all())->toBe(['Sem desembolso — nenhum pagamento devido']);
});

// ── Histórico de ciclo ───────────────────────────────────────────────────────

it('normalizes the no-disbursement decision and the acknowledged finalization as complete cycle events', function () {
    $scenario = Scenario::plan();
    $scenario['actor']->givePermissionTo(AccessPermission::MeasurementsCycleReportsView->value);
    $measurement = Scenario::finalizeAcknowledging($scenario, Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0])));
    $history = app(MeasurementCycleHistoryReadModel::class)->for($scenario['actor'], $measurement);
    $events = collect($history->events);
    $paymentDecisions = $events
        ->filter(fn (MeasurementCycleEvent $event): bool => $event->eventType === MeasurementCycleEventType::StageApproved
            && $event->stageBefore === MeasurementWorkflow::STAGE_PAYMENT)
        ->values();
    $finalizations = $events
        ->filter(fn (MeasurementCycleEvent $event): bool => $event->eventType === MeasurementCycleEventType::Finalized)
        ->values();

    // Etapa Pagamento: awaiting_payment -> approved, sem pagamento algum -- um
    // formato de transição válido, e não "Insufficient".
    expect($paymentDecisions)->toHaveCount(1)
        ->and($paymentDecisions[0]->sourceType)->toBe(MeasurementHistorySourceType::WorkflowActivity)
        ->and([$paymentDecisions[0]->statusBefore, $paymentDecisions[0]->statusAfter])->toBe(['awaiting_payment', 'approved'])
        ->and($paymentDecisions[0]->stageAfter)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($paymentDecisions[0]->responsibility)->toBe(MeasurementResponsibility::PaymentManager)
        ->and($paymentDecisions[0]->actorId)->toBe($scenario['actor']->id)
        ->and($paymentDecisions[0]->reason)->toBe(Scenario::JUSTIFICATION)
        ->and($paymentDecisions[0]->paymentIds)->toBe([])
        ->and($paymentDecisions[0]->completeness)->toBe(MeasurementHistoryCompleteness::Complete)
        ->and($paymentDecisions[0]->missingReasons)->toBe([])
        // Finalização: approved -> finalized, com a ciência.
        ->and($finalizations)->toHaveCount(1)
        ->and($finalizations[0]->sourceType)->toBe(MeasurementHistorySourceType::WorkflowActivity)
        ->and([$finalizations[0]->statusBefore, $finalizations[0]->statusAfter])->toBe(['approved', 'finalized'])
        ->and($finalizations[0]->responsibility)->toBe(MeasurementResponsibility::Finalizer)
        ->and($finalizations[0]->completeness)->toBe(MeasurementHistoryCompleteness::Complete)
        ->and($finalizations[0]->missingReasons)->toBe([]);

    $paymentVisit = collect($history->stageVisits)->first(fn (MeasurementStageVisit $visit): bool => $visit->stage === MeasurementWorkflow::STAGE_PAYMENT);

    expect(array_column($history->stageVisits, 'stage'))->toBe([1, 2, 3, 4, 5])
        ->and($paymentVisit->exitReason)->toBe(MeasurementStageExitReason::Approved)
        ->and($paymentVisit->completeness)->toBe(MeasurementHistoryCompleteness::Complete)
        ->and($history->payments)->toBe([])
        ->and($history->terminalReason)->toBe(MeasurementStageExitReason::Finalized)
        ->and($history->completeness)->toBe(MeasurementHistoryCompleteness::Complete);

    // O ciclo sem desembolso conta como ciclo concluído e a decisão da etapa
    // Pagamento, como aprovação.
    $report = app(MeasurementCycleReportingService::class)->report($scenario['actor'], new MeasurementCycleReportFilters);
    $paymentMetrics = collect($report->stageMetrics)->first(fn (MeasurementStageMetrics $metrics): bool => $metrics->stage === MeasurementWorkflow::STAGE_PAYMENT);

    expect($report->summary->eligibleCycles)->toBe(1)
        ->and($report->summary->finalizations)->toBe(1)
        ->and($paymentMetrics->approvals)->toBe(1);
});

// ── Aviso, revisão e exceções operacionais ───────────────────────────────────

it('notifies the Finalizer that the Payment stage ended without disbursement and that the acknowledgment is required', function () {
    $scenario = Scenario::plan();
    $finalizer = p4aReadFinalizer($scenario);
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));
    $sent = Notification::sent($finalizer, MeasurementWorkflowNotification::class)
        ->filter(fn (MeasurementWorkflowNotification $notification): bool => (int) $notification->measurement->getKey() === $measurement->id)
        ->values();

    expect($sent->map(fn (MeasurementWorkflowNotification $notification): string => $notification->event)->all())
        ->toBe(['ready_to_finalize_without_disbursement'])
        ->and($sent->first()->toArray($finalizer))->toMatchArray([
            'event' => 'ready_to_finalize_without_disbursement',
            'title' => 'Etapa Pagamento concluída sem desembolso — ciência necessária na Finalização',
        ])
        ->and((int) $sent->first()->toArray($finalizer)['measurement_id'])->toBe($measurement->id);

    // Nenhum aviso de pagamento aprovado ou de comprovante: não houve pagamento.
    Notification::assertNotSentTo(
        [$scenario['actor'], $finalizer],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => in_array($notification->event, ['ready_to_finalize', 'payment_approved'], true),
    );
    Notification::assertNotSentTo(
        $scenario['actor'],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'ready_to_finalize_without_disbursement',
    );
});

it('explains why a zero-disbursement original at Finalization cannot be revised yet, and allows it once finalized', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));
    $revisions = app(MeasurementRevisionService::class);

    expect($measurement->only(['status', 'current_stage']))->toBe(['status' => 'approved', 'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION])
        ->and($revisions->creationBlockReason($measurement->fresh()))->toBe(MeasurementRevisionService::UNPAID_ORIGINAL_FINALIZATION_REFUSAL);

    // A recusa promete o caminho: finalizada, a medição pode ser revisada.
    expect($revisions->creationBlockReason(Scenario::finalizeAcknowledging($scenario, $measurement)->fresh()))->toBeNull();

    // A revisão na Finalização sem pagamento próprio mantém a recusa dela.
    $revisionScenario = MeasurementRevisionScenario::plan();
    $may = MeasurementRevisionScenario::paidAwaitingFinalization($revisionScenario, '2026-05', 10);
    $first = MeasurementRevisionScenario::effective($revisionScenario, $may, 10, 'Laudo de maio substituído; o percentual não muda.');
    app(MeasurementWorkflow::class)->approve($first->fresh(), $revisionScenario['actor']);

    expect($revisions->creationBlockReason($first->fresh()))->toBe(MeasurementRevisionService::UNPAID_FINALIZATION_REFUSAL);
});

it('reports the missing Finalizer of a zero-disbursement original waiting at Finalization', function () {
    $scenario = Scenario::plan();
    $scenario['actor']->givePermissionTo(AccessPermission::MeasurementsExceptionsView->value);
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));
    $exceptions = app(MeasurementOperationalExceptionReadModel::class);

    // SLA configurado, para a varredura só acusar a responsabilidade.
    config(['measurements.sla.calendar_code' => null]);

    foreach (range(1, 5) as $stage) {
        SlaConfiguration::factory()->forStage($stage, 5)->create();
    }

    expect($exceptions->scanFor($scenario['actor'])->total)->toBe(0);

    // Sem desembolso, a única pendência da Finalização é a do Finalizador --
    // e sem Finalizador válido ninguém pode dar a ciência.
    $scenario['operation']->forceFill(['payment_finalizer_user_id' => null])->save();
    $result = $exceptions->scanFor($scenario['actor']);

    expect($result->total)->toBe(1)
        ->and($result->items[0]->type)->toBe(MeasurementOperationalExceptionType::MissingFinalizer)
        ->and($result->items[0]->expectedResponsibility)->toBe(MeasurementResponsibility::Finalizer)
        ->and($result->items[0]->stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and((int) $result->items[0]->measurement->getKey())->toBe($measurement->id);
});

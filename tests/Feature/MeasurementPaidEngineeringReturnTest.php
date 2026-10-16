<?php

use App\Enums\MeasurementReceiptReviewStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Measurement;
use App\Models\MeasurementAsset;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ForgedLivewireRequest;
use Tests\Support\MeasurementPhysicalProgressScenario as PhysicalScenario;
use Tests\Support\MeasurementPlanVersionFixture;
use Tests\Support\MeasurementRevisionScenario as Scenario;

/*
 * O registro aprovado pela Engenharia de uma medição paga é a base do
 * pagamento e não volta à Engenharia para ser reescrito: a recusa da Gestão e
 * a devolução da Finalização à Engenharia são recusadas, na tela e no domínio,
 * sem gravar nada -- a correção é uma revisão formal. Continuam abertas as
 * devoluções às etapas Gestão, Compliance e Pagamento, a reaprovação da linha
 * legada paga (sem registro verificável) e a reanálise da revisão vigente sem
 * pagamento próprio, mesmo que a família tenha pago. A medição paga também não
 * recebe arquivo novo, nem pelo item forjado do formulário de edição.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * A recusa do fluxo, lida inteira da exceção -- para comparar com o texto
 * exato, que tem dois-pontos.
 */
function paidEngineeringReturnRefusal(callable $attempt): string
{
    try {
        $attempt();
    } catch (MeasurementWorkflowException $exception) {
        return $exception->getMessage();
    }

    throw new RuntimeException('A tentativa não foi recusada pelo fluxo.');
}

/**
 * Paga a medição parada na etapa Pagamento e a devolve à Gestão: a etapa
 * Pagamento recusa (volta à Compliance) e a Compliance recusa (volta à
 * Gestão) -- as duas recusas que não tocam a Engenharia.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function paidEngineeringReturnBackToManagement(array $scenario, Measurement $awaitingPayment, string $amount): Measurement
{
    $workflow = app(MeasurementWorkflow::class);
    Scenario::pay($scenario, $awaitingPayment, $amount);
    $workflow->reject($awaitingPayment->fresh(), $scenario['actor'], 'Rever o enquadramento do pagamento.');
    $workflow->reject($awaitingPayment->fresh(), $scenario['actor'], 'Rever a documentação da Compliance.');

    return $awaitingPayment->fresh();
}

/**
 * A medição original de maio, aprovada a 10% e paga pelo valor esperado, de
 * volta à Gestão.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function paidEngineeringReturnAtManagement(array $scenario): Measurement
{
    return paidEngineeringReturnBackToManagement($scenario, Scenario::awaitingPayment($scenario, '2026-05', 10), Scenario::expected(10));
}

/**
 * Tudo o que uma recusa ou uma devolução gravaria na medição, como está no
 * banco: a situação e o registro da Engenharia, as análises, os pagamentos,
 * os arquivos e a ocupação das medições previstas, o realizado gravado nas
 * linhas do cronograma e a trilha da medição.
 *
 * @return array<string, mixed>
 */
function paidEngineeringReturnFootprint(Measurement $measurement): array
{
    $rows = fn (string $table, string $order, array $columns): array => DB::table($table)
        ->where('measurement_id', $measurement->getKey())
        ->orderBy($order)
        ->get($columns)
        ->map(fn (object $row): array => (array) $row)
        ->all();

    return [
        'measurement' => (array) DB::table('measurements')->where('id', $measurement->getKey())->first([
            'status', 'current_stage', 'workflow_revision', 'engineering_snapshot', 'reference_month', 'analyzed_by', 'analyzed_at',
        ]),
        'reviews' => $rows('measurement_reviews', 'stage', ['id', 'stage', 'status', 'reviewer_user_id', 'notes', 'reviewed_at', 'paused_at']),
        'payments' => $rows('measurement_payments', 'id', ['id', 'plan_set_id', 'amount', 'pay_date', 'financial_assessment']),
        'assets' => $rows('measurement_assets', 'id', ['id', 'plan_set_id', 'plan_line_id', 'storage_path', 'sha256', 'line_claim_key']),
        'lines' => $rows('measurement_plan_lines', 'id', ['id', 'realized_monthly_percent', 'realized_cumulative_percent']),
        'activities' => Activity::query()
            ->where('subject_type', $measurement->getMorphClass())
            ->where('subject_id', $measurement->getKey())
            ->count(),
    ];
}

/**
 * Avisos do fluxo de medição enviados à pessoa até aqui.
 */
function paidEngineeringReturnWorkflowNotices(User $user): int
{
    return Notification::sent($user, MeasurementWorkflowNotification::class)->count();
}

/**
 * Corpo da notificação do Filament da última requisição, lido sem consumir a
 * sessão -- o `assertNotified` compara só o título.
 */
function paidEngineeringReturnNotificationBody(string $title): ?string
{
    $body = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->firstWhere('title', $title)['body'] ?? null;

    return $body === null ? null : (string) $body;
}

/**
 * Campo do modal da ação montada pelo fim do caminho (`target_stage`). O
 * modal vai na resposta como JSON escapado: o que ele oferece é lido pelo
 * schema montado.
 */
function paidEngineeringReturnModalField(Testable $component, string $name): Field
{
    $page = $component->instance();

    foreach ($page->{$page->getMountedActionSchemaName()}->getFlatComponents() as $key => $item) {
        if ((($key === $name) || str_ends_with((string) $key, ".{$name}")) && ($item instanceof Field)) {
            return $item;
        }
    }

    throw new RuntimeException("O campo {$name} não está no modal montado.");
}

/**
 * O texto de ajuda do campo (o Filament o guarda abaixo do campo), ou `null`
 * quando o campo não mostra nenhum.
 */
function paidEngineeringReturnHelperText(Field $field): ?string
{
    $texts = collect($field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [])
        ->filter(fn (mixed $component): bool => $component instanceof Text)
        ->map(fn (Text $text): string => trim((string) $text->getContent()))
        ->filter()
        ->values();

    return $texts->isEmpty() ? null : $texts->implode(' ');
}

/**
 * As etapas que o Select de destino da devolução aceita.
 *
 * @return list<int>
 */
function paidEngineeringReturnEnabledTargets(Testable $component): array
{
    $select = paidEngineeringReturnModalField($component, 'target_stage');

    expect($select)->toBeInstanceOf(Select::class);

    return array_map(intval(...), array_keys($select->getEnabledOptions()));
}

/**
 * Um segundo empreendimento na operação, a Torre Beta, com medição prevista
 * em maio e em junho, cujo plano consta da trilha como vigente desde antes de
 * qualquer medição (`last_measurement_id_at_activation` nulo, como nas versões
 * ativadas antes de essa marca existir): pela regra da medição sem pagamento,
 * toda medição dessas competências precisa trazer também o arquivo dele.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function paidEngineeringReturnSecondDevelopment(array $scenario): MeasurementPlanSet
{
    $planSet = MeasurementPlanSet::factory()->withConstructionFund('500000.00')->create([
        'operation_id' => $scenario['operation']->id,
        'name' => 'Torre Beta',
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => '0.00',
    ]);

    foreach (['2026-05', '2026-06'] as $index => $month) {
        MeasurementPlanLine::factory()->create([
            'operation_id' => $scenario['operation']->id,
            'plan_set_id' => $planSet->id,
            'sequence_number' => $index + 1,
            'measurement_date' => $month.'-01',
            'planned_monthly_percent' => 10,
            'planned_cumulative_percent' => 10 * ($index + 1),
            'initial_realized_cumulative_percent' => 0,
            'realized_monthly_percent' => 0,
            'realized_cumulative_percent' => 0,
        ]);
    }

    MeasurementPlanVersionFixture::activate($planSet);
    DB::table('measurement_plan_versions')
        ->where('plan_set_id', $planSet->id)
        ->update(['last_measurement_id_at_activation' => null]);

    return $planSet->fresh();
}

/**
 * Linha legada paga de volta à Engenharia: paga na Finalização com os
 * comprovantes aprovados e devolvida à Engenharia antes da Fase 3.1, sem o
 * registro aprovado.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function paidEngineeringReturnLegacyAtEngineering(array $scenario): Measurement
{
    return Scenario::legacyPaidAtEngineering(Scenario::paidAwaitingFinalization($scenario, '2026-05', 10));
}

/**
 * Os arquivos de medição gravados no disco privado.
 *
 * @return list<string>
 */
function paidEngineeringReturnStoredAssetFiles(): array
{
    $files = Storage::disk('local')->allFiles('nimbus_docs/measurements/assets');
    sort($files);

    return $files;
}

// ── A Gestão não devolve à Engenharia o registro de uma medição paga ─────────

it('refuses the Gestão rejection that would send the approved Engineering record of a paid measurement back to be rewritten, writing nothing', function (string $subject) {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);

    if ($subject === 'original measurement') {
        $paid = paidEngineeringReturnAtManagement($scenario);
        $percent = '10.00';
    } else {
        // Caso B: a original parada na etapa Pagamento sem pagamento, e a R1,
        // que passou a valer, paga pelo valor que aprova.
        $revision = Scenario::effective($scenario, Scenario::awaitingPayment($scenario, '2026-05', 10), 8);
        $paid = paidEngineeringReturnBackToManagement($scenario, $revision, Scenario::expected(8));
        $percent = '8.00';
    }

    $before = paidEngineeringReturnFootprint($paid);
    $snapshot = $paid->engineering_snapshot;
    $noticesBefore = paidEngineeringReturnWorkflowNotices($scenario['actor']);

    expect($paid->status)->toBe('in_review')
        ->and($paid->current_stage)->toBe(2)
        ->and($paid->isEffectiveRevision())->toBeTrue()
        ->and($paid->payments()->count())->toBe(1)
        ->and($paid->reviewForStage(2)?->status)->toBe('pending')
        ->and($snapshot['measurement_id'])->toBe($paid->id)
        ->and($snapshot['plan_sets'][0]['realized_monthly_percent'])->toBe($percent)
        ->and($workflow->protectsPaidEngineeringRecord($paid))->toBeTrue()
        ->and($workflow->engineeringReturnBlockReason($paid))->toBe(MeasurementWorkflow::PAID_MANAGEMENT_REJECTION_REFUSAL)
        // A Gestão continua decidindo: aprova, e o botão de recusa fica à
        // vista para mostrar o motivo.
        ->and($workflow->canApprove($paid, $scenario['actor']))->toBeTrue()
        ->and($workflow->canReject($paid, $scenario['actor']))->toBeTrue();

    expect(paidEngineeringReturnRefusal(fn () => $workflow->reject($paid->fresh(), $scenario['actor'], 'Rever o percentual medido.')))
        ->toBe(MeasurementWorkflow::PAID_MANAGEMENT_REJECTION_REFUSAL);

    $kept = $paid->fresh();

    expect(paidEngineeringReturnFootprint($kept))->toBe($before)
        ->and($kept->engineering_snapshot)->toBe($snapshot)
        ->and($kept->reviewForStage(2)?->status)->toBe('pending')
        ->and($kept->reviewForStage(2)?->reviewer_user_id)->toBeNull()
        ->and($kept->reviewForStage(1)?->status)->toBe('approved')
        // Só as duas recusas que trouxeram a medição à Gestão.
        ->and(Activity::query()
            ->where('description', 'measurement_stage_rejected')
            ->where('subject_id', $paid->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Activity $activity): int => (int) $activity->properties['stage'])
            ->all())->toBe([MeasurementWorkflow::STAGE_PAYMENT, 3])
        ->and(Activity::query()
            ->whereIn('description', ['measurement_engineering_snapshot_invalidated', 'measurement_physical_contribution_invalidated'])
            ->exists())->toBeFalse()
        ->and(PhysicalScenario::progress($scenario)->currentPercent())->toBe($percent)
        ->and(paidEngineeringReturnWorkflowNotices($scenario['actor']))->toBe($noticesBefore);
})->with(['original measurement', 'effective revision with its own payment']);

it('takes the paid measurement refused at Gestão to Finalization on the same approved record, where the formal revision is available', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $paid = paidEngineeringReturnAtManagement($scenario);
    $snapshot = $paid->engineering_snapshot;
    $payment = $paid->payments()->sole();

    expect(paidEngineeringReturnRefusal(fn () => $workflow->reject($paid->fresh(), $scenario['actor'], 'Rever o percentual medido.')))
        ->toBe(MeasurementWorkflow::PAID_MANAGEMENT_REJECTION_REFUSAL);

    // O caminho que a recusa aponta: aprovar as etapas até a Finalização, com
    // os comprovantes conferidos, e revisar de lá.
    Scenario::approveManagementAndCompliance($scenario, $paid);
    $workflow->approve($paid->fresh(), $scenario['actor']);
    Scenario::receiptsApproved($scenario, $paid);
    $ready = $paid->fresh();

    expect($ready->status)->toBe('approved')
        ->and($ready->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($ready->engineering_snapshot)->toBe($snapshot)
        ->and($ready->payments()->pluck('id')->all())->toBe([$payment->id])
        ->and($payment->fresh()->amount)->toBe(Scenario::expected(10))
        ->and(app(MeasurementRevisionService::class)->creationBlockReason($ready))->toBeNull()
        ->and($workflow->engineeringReturnBlockReason($ready))->toBe(MeasurementWorkflow::PAID_ENGINEERING_RETURN_REFUSAL);
});

// ── A Finalização não devolve a medição paga à Engenharia ────────────────────

it('refuses to return a paid measurement from Finalization to Engineering, writing nothing', function (string $receipt) {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);

    if ($receipt === 'receipts approved') {
        $paid = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
        $status = 'approved';
    } else {
        $paid = Scenario::awaitingPayment($scenario, '2026-05', 10);
        $payment = Scenario::pay($scenario, $paid, Scenario::expected(10));
        $workflow->approve($paid->fresh(), $scenario['actor']);
        // A v1 do comprovante entra com o registro do pagamento; o Finalizador a rejeita.
        app(MeasurementReceiptEvidenceService::class)->review(
            $payment->fresh()->currentReceiptEvidence,
            $scenario['finalizer'],
            MeasurementReceiptReviewStatus::Rejected,
            true,
            rejectionReason: 'Comprovante sem a autenticação do banco.',
        );
        $paid = $paid->fresh();
        $status = 'awaiting_receipt';
    }

    $before = paidEngineeringReturnFootprint($paid);
    $noticesBefore = paidEngineeringReturnWorkflowNotices($scenario['actor']);

    expect($paid->status)->toBe($status)
        ->and($paid->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and($workflow->canReturnFromFinalization($paid, $scenario['finalizer']))->toBeTrue()
        ->and($workflow->engineeringReturnBlockReason($paid))->toBe(MeasurementWorkflow::PAID_ENGINEERING_RETURN_REFUSAL);

    expect(paidEngineeringReturnRefusal(fn () => $workflow->returnToStage(
        $paid->fresh(),
        $scenario['finalizer'],
        MeasurementWorkflow::STAGE_ENGINEERING,
        'Rever o percentual medido.',
    )))->toBe(MeasurementWorkflow::PAID_ENGINEERING_RETURN_REFUSAL);

    $kept = $paid->fresh();

    expect(paidEngineeringReturnFootprint($kept))->toBe($before)
        ->and($kept->reviewForStage(MeasurementWorkflow::STAGE_FINALIZATION)?->status)->toBe('pending')
        ->and($kept->reviewForStage(MeasurementWorkflow::STAGE_FINALIZATION)?->reviewer_user_id)->toBeNull()
        ->and(Activity::query()
            ->whereIn('description', ['measurement_finalization_returned', 'measurement_engineering_snapshot_invalidated', 'measurement_physical_contribution_invalidated'])
            ->exists())->toBeFalse()
        ->and(paidEngineeringReturnWorkflowNotices($scenario['actor']))->toBe($noticesBefore);
})->with(['receipt rejected', 'receipts approved']);

it('still returns a paid measurement from Finalization to an earlier stage after Engineering, on the same approved record', function (int $target) {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $paid = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $snapshot = $paid->engineering_snapshot;
    $engineeringReview = $paid->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->only(['id', 'status', 'reviewer_user_id']);
    $payments = DB::table('measurement_payments')->where('measurement_id', $paid->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    $workflow->returnToStage($paid->fresh(), $scenario['finalizer'], $target, 'Rever o enquadramento antes de finalizar.');

    $returned = $paid->fresh();
    $returnAudit = Activity::query()->where('description', 'measurement_finalization_returned')->sole();

    expect($returned->current_stage)->toBe($target)
        ->and($returned->status)->toBe($target === MeasurementWorkflow::STAGE_PAYMENT ? 'awaiting_payment' : 'in_review')
        ->and($returned->reviewForStage($target)?->status)->toBe('pending')
        ->and($returned->reviewForStage(MeasurementWorkflow::STAGE_FINALIZATION)?->status)->toBe('rejected')
        ->and($returned->reviewForStage(MeasurementWorkflow::STAGE_FINALIZATION)?->notes)->toBe('Rever o enquadramento antes de finalizar.')
        ->and($returned->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->only(['id', 'status', 'reviewer_user_id']))->toBe($engineeringReview)
        ->and($returned->engineering_snapshot)->toBe($snapshot)
        ->and(DB::table('measurement_payments')->where('measurement_id', $paid->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all())->toBe($payments)
        ->and((int) $returnAudit->subject_id)->toBe($paid->id)
        ->and($returnAudit->properties['target_stage'])->toBe($target)
        ->and(Activity::query()
            ->whereIn('description', ['measurement_engineering_snapshot_invalidated', 'measurement_physical_contribution_invalidated'])
            ->exists())->toBeFalse()
        ->and(PhysicalScenario::progress($scenario)->currentPercent())->toBe('10.00')
        // Na Gestão, a recusa continua sem devolver à Engenharia.
        ->and($workflow->engineeringReturnBlockReason($returned))->toBe($target === 2
            ? MeasurementWorkflow::PAID_MANAGEMENT_REJECTION_REFUSAL
            : MeasurementWorkflow::PAID_ENGINEERING_RETURN_REFUSAL);
})->with(['Pagamento' => 4, 'Compliance' => 3, 'Gestão' => 2]);

// ── A tela ───────────────────────────────────────────────────────────────────

it('shows Recusar disabled at Gestão for a paid measurement, with the reason as tooltip, and refuses a forged submission', function () {
    $scenario = Scenario::plan();
    $paid = paidEngineeringReturnAtManagement($scenario);
    $before = paidEngineeringReturnFootprint($paid);
    $this->actingAs($scenario['actor']);

    // O Filament não monta a ação desabilitada.
    $component = Livewire::test(ViewMeasurement::class, ['record' => $paid->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionEnabled('approve')
        ->assertActionVisible('reject')
        ->assertActionDisabled('reject')
        ->assertActionExists('reject', fn (Action $action): bool => $action->getTooltip() === MeasurementWorkflow::PAID_MANAGEMENT_REJECTION_REFUSAL)
        ->call('mountAction', 'reject')
        ->assertSet('mountedActions', []);

    // O envio forjado, com a ação já montada no estado do componente, também
    // não passa: a página recusa a ação desabilitada antes do domínio.
    $component->set('mountedActions', [[
        'name' => 'reject',
        'arguments' => [],
        'context' => [],
        'data' => ['notes' => 'Rever o percentual medido.', 'expected_stage' => 2, 'expected_revision' => (int) $paid->workflow_revision],
    ]])->call('callMountedAction');

    expect(paidEngineeringReturnNotificationBody('Ação não concluída.'))
        ->toBe('A medição mudou depois que esta janela foi aberta e esta ação não está mais disponível para você. Atualize a página.');

    $component->assertSet('mountedActions', []);

    expect(paidEngineeringReturnFootprint($paid))->toBe($before);
});

it('keeps Recusar enabled where the rejection does not send a paid approved record back to Engineering', function (string $case) {
    $scenario = Scenario::plan();

    if ($case === 'unpaid measurement at Gestão') {
        $measurement = PhysicalScenario::measured($scenario, '2026-05', 10);
        $expectedStage = MeasurementWorkflow::STAGE_ENGINEERING;
    } else {
        $measurement = Scenario::awaitingPayment($scenario, '2026-05', 10);
        Scenario::pay($scenario, $measurement, Scenario::expected(10));
        app(MeasurementWorkflow::class)->reject($measurement->fresh(), $scenario['actor'], 'Rever o enquadramento do pagamento.');
        $measurement = $measurement->fresh();
        $expectedStage = 2;
    }

    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('reject')
        ->assertActionEnabled('reject')
        ->assertActionExists('reject', fn (Action $action): bool => $action->getTooltip() === null)
        ->callAction('reject', data: ['notes' => 'Corrigir antes de seguir.'])
        ->assertHasNoActionErrors();

    expect($measurement->fresh()->current_stage)->toBe($expectedStage)
        ->and($measurement->fresh()->status)->toBe('in_review');

    if ($case === 'paid measurement at Compliance') {
        // De volta à Gestão, a medição paga não desce mais: Recusar fica
        // desabilitado com o motivo.
        Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
            ->assertActionDisabled('reject')
            ->assertActionExists('reject', fn (Action $action): bool => $action->getTooltip() === MeasurementWorkflow::PAID_MANAGEMENT_REJECTION_REFUSAL);
    } else {
        expect($measurement->fresh()->engineering_snapshot)->toBeNull()
            ->and($measurement->fresh()->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->status)->toBe('pending');
    }
})->with(['unpaid measurement at Gestão', 'paid measurement at Compliance']);

it('disables the Engineering option of the return from Finalization of a paid measurement, refuses it in the modal and in the domain, and still returns it to the Payment stage', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $paid = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $snapshot = $paid->engineering_snapshot;
    $before = paidEngineeringReturnFootprint($paid);
    $this->actingAs($scenario['finalizer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $paid->getRouteKey()])
        ->assertActionVisible('returnToStage')
        ->assertActionEnabled('returnToStage')
        ->mountAction('returnToStage')
        ->assertActionMounted('returnToStage');
    $select = paidEngineeringReturnModalField($component, 'target_stage');

    expect(paidEngineeringReturnEnabledTargets($component))->toBe([2, 3, MeasurementWorkflow::STAGE_PAYMENT])
        ->and($select->isOptionDisabled(MeasurementWorkflow::STAGE_ENGINEERING, 'Etapa 1 — Engenharia'))->toBeTrue()
        ->and(paidEngineeringReturnHelperText($select))
        ->toBe('Com pagamento registrado, a devolução não vai até a Engenharia: a correção dos dados aprovados é uma revisão formal (Criar revisão).');

    // A opção desabilitada, escolhida mesmo assim, é recusada pela regra do
    // Select no servidor, com o motivo do domínio.
    $component->setActionData(['target_stage' => MeasurementWorkflow::STAGE_ENGINEERING, 'reason' => 'Rever o percentual medido.'])
        ->callMountedAction();

    expect($component->errors()->get('mountedActions.0.data.target_stage'))->toBe([MeasurementWorkflow::PAID_ENGINEERING_RETURN_REFUSAL])
        ->and(paidEngineeringReturnFootprint($paid))->toBe($before);

    $component->assertActionMounted('returnToStage');

    // A chamada direta ao domínio, que pularia a tela, recusa pelo mesmo
    // motivo.
    expect(paidEngineeringReturnRefusal(fn () => $workflow->returnToStage(
        $paid->fresh(),
        $scenario['finalizer'],
        MeasurementWorkflow::STAGE_ENGINEERING,
        'Rever o percentual medido.',
    )))->toBe(MeasurementWorkflow::PAID_ENGINEERING_RETURN_REFUSAL)
        ->and(paidEngineeringReturnFootprint($paid))->toBe($before);

    // As demais etapas continuam no mesmo modal.
    $component->setActionData(['target_stage' => MeasurementWorkflow::STAGE_PAYMENT, 'reason' => 'Reavaliar o enquadramento do pagamento.'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $returned = $paid->fresh();

    expect($returned->status)->toBe('awaiting_payment')
        ->and($returned->current_stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
        ->and($returned->engineering_snapshot)->toBe($snapshot)
        ->and(Activity::query()->where('description', 'measurement_finalization_returned')->sole()->properties['target_stage'])
        ->toBe(MeasurementWorkflow::STAGE_PAYMENT);
});

// ── O que continua voltando à Engenharia ─────────────────────────────────────

it('lets Engineering approve again a legacy paid measurement back at Engineering, covering only its own files, and protects the new record from then on', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $legacy = paidEngineeringReturnLegacyAtEngineering($scenario);
    $payment = $legacy->payments()->sole();
    $beta = paidEngineeringReturnSecondDevelopment($scenario);

    // Sem pagamento, a mesma operação exige o arquivo da Torre Beta: a medição
    // de junho, que só traz o do plano padrão, não passa na Engenharia.
    $june = PhysicalScenario::measurement($scenario, '2026-06');
    $errors = [];

    try {
        PhysicalScenario::approveEngineering($scenario, $june, 10);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toHaveKeys(['assets.coverage', "assets.{$beta->id}"])
        ->and($errors['assets.coverage'])->toBe(['Envie exatamente um arquivo para cada empreendimento da operação.'])
        ->and($june->fresh()->hasApprovedEngineering())->toBeFalse();

    // A linha legada paga não tem registro verificável a proteger: volta a ser
    // aprovada, conferindo só os empreendimentos dos próprios arquivos -- ela
    // não recebe arquivo novo e não se encerra por recusa.
    expect($legacy->status)->toBe('in_review')
        ->and($legacy->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($legacy->engineering_snapshot)->toBeNull()
        ->and($workflow->protectsPaidEngineeringRecord($legacy))->toBeFalse()
        ->and($workflow->engineeringReturnBlockReason($legacy))->toBeNull()
        ->and($workflow->terminalRejectionBlockReason($legacy))->toBe(MeasurementWorkflow::TERMINAL_REJECTION_WITH_PAYMENTS)
        ->and($workflow->canApprove($legacy, $scenario['actor']))->toBeTrue();

    PhysicalScenario::approveEngineering($scenario, $legacy, 8);
    $approved = $legacy->fresh();

    expect($approved->status)->toBe('in_review')
        ->and($approved->current_stage)->toBe(2)
        ->and($approved->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->status)->toBe('approved')
        ->and($approved->engineering_snapshot['schema_version'])->toBe(MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION)
        ->and($approved->engineering_snapshot['measurement_id'])->toBe($legacy->id)
        ->and(array_column($approved->engineering_snapshot['plan_sets'], 'plan_set_id'))->toBe([$scenario['planSet']->id])
        ->and($approved->engineering_snapshot['plan_sets'][0]['realized_monthly_percent'])->toBe('8.00')
        ->and($approved->payments()->pluck('id')->all())->toBe([$payment->id])
        ->and($payment->fresh()->amount)->toBe(Scenario::expected(10))
        ->and(PhysicalScenario::progress($scenario)->currentPercent())->toBe('8.00')
        // O registro novo é verificável: daqui em diante, protegido.
        ->and($workflow->protectsPaidEngineeringRecord($approved))->toBeTrue()
        ->and($workflow->engineeringReturnBlockReason($approved))->toBe(MeasurementWorkflow::PAID_MANAGEMENT_REJECTION_REFUSAL)
        ->and(paidEngineeringReturnRefusal(fn () => $workflow->reject($approved, $scenario['actor'], 'Rever o percentual de novo.')))
        ->toBe(MeasurementWorkflow::PAID_MANAGEMENT_REJECTION_REFUSAL);
});

it('still returns from Finalization to Engineering a legacy paid measurement without a verifiable Engineering record', function (string $record) {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $paid = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $payment = $paid->payments()->sole();
    $evidence = $payment->currentReceiptEvidence;
    $snapshot = $paid->engineering_snapshot;
    $legacySnapshot = match ($record) {
        'no record' => null,
        'record of an older schema' => array_replace($snapshot, ['schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION - 1]),
        'record of another measurement' => array_replace($snapshot, ['measurement_id' => $paid->id + 1]),
        'record without developments' => array_replace($snapshot, ['plan_sets' => []]),
    };
    // Gravado direto no banco, como as linhas legadas ficaram: o model não
    // deixa reescrever o registro aprovado.
    DB::table('measurements')->where('id', $paid->id)->update([
        'engineering_snapshot' => $legacySnapshot === null ? null : json_encode($legacySnapshot),
    ]);
    $legacy = $paid->fresh();
    $this->actingAs($scenario['finalizer']);

    expect($legacy->status)->toBe('approved')
        ->and($legacy->hasApprovedEngineering())->toBeTrue()
        ->and($workflow->protectsPaidEngineeringRecord($legacy))->toBeFalse()
        ->and($workflow->engineeringReturnBlockReason($legacy))->toBeNull();

    $component = Livewire::test(ViewMeasurement::class, ['record' => $legacy->getRouteKey()])
        ->assertActionVisible('returnToStage')
        ->mountAction('returnToStage');

    expect(paidEngineeringReturnEnabledTargets($component))->toBe([MeasurementWorkflow::STAGE_ENGINEERING, 2, 3, MeasurementWorkflow::STAGE_PAYMENT])
        ->and(paidEngineeringReturnHelperText(paidEngineeringReturnModalField($component, 'target_stage')))->toBeNull();

    $component->setActionData(['target_stage' => MeasurementWorkflow::STAGE_ENGINEERING, 'reason' => 'Registro da Engenharia sem conferência: medir de novo.'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $returned = $paid->fresh();

    expect($returned->status)->toBe('in_review')
        ->and($returned->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($returned->engineering_snapshot)->toBeNull()
        ->and($returned->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->status)->toBe('pending')
        ->and($returned->reviewForStage(MeasurementWorkflow::STAGE_FINALIZATION)?->status)->toBe('rejected')
        ->and(Activity::query()->where('description', 'measurement_finalization_returned')->sole()->properties['target_stage'])
        ->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($returned->payments()->pluck('id')->all())->toBe([$payment->id])
        ->and($payment->fresh()->amount)->toBe(Scenario::expected(10))
        ->and($payment->fresh()->currentReceiptEvidence?->id)->toBe($evidence->id)
        ->and($payment->fresh()->currentReceiptEvidence?->review_status)->toBe(MeasurementReceiptReviewStatus::Approved);

    // A reaprovação grava o registro verificável, que daí em diante fica
    // protegido.
    PhysicalScenario::approveEngineering($scenario, $returned, 10);

    expect($paid->fresh()->current_stage)->toBe(2)
        ->and($workflow->protectsPaidEngineeringRecord($paid->fresh()))->toBeTrue()
        ->and($workflow->engineeringReturnBlockReason($paid->fresh()))->toBe(MeasurementWorkflow::PAID_MANAGEMENT_REJECTION_REFUSAL);
})->with(['no record', 'record of an older schema', 'record of another measurement', 'record without developments']);

it('still sends back to Engineering, and approves again, an effective revision without a payment of its own although the family paid', function (string $route) {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $familyPayment = $may->payments()->sole();
    $familyEvidence = $familyPayment->currentReceiptEvidence;
    $revision = Scenario::effective($scenario, $may, 10, 'Laudo de maio refeito pela construtora.');
    $this->actingAs($scenario['actor']);

    if ($route === 'Gestão rejection') {
        $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever o enquadramento.');
        $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever a documentação.');
        $atStage = $revision->fresh();

        expect($atStage->current_stage)->toBe(2)
            ->and($workflow->protectsPaidEngineeringRecord($atStage))->toBeFalse()
            ->and($workflow->engineeringReturnBlockReason($atStage))->toBeNull();

        Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
            ->assertActionVisible('reject')
            ->assertActionEnabled('reject')
            ->assertActionExists('reject', fn (Action $action): bool => $action->getTooltip() === null)
            ->callAction('reject', data: ['notes' => 'O laudo da revisão não confere com a vistoria.'])
            ->assertHasNoActionErrors();
    } else {
        // A família já pagou o que a R1 aprova: a etapa Pagamento é aprovada
        // sem pagamento próprio, e a R1 chega à Finalização.
        $workflow->approve($revision->fresh(), $scenario['actor']);
        $atStage = $revision->fresh();

        expect($atStage->status)->toBe('approved')
            ->and($atStage->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
            ->and($workflow->protectsPaidEngineeringRecord($atStage))->toBeFalse()
            ->and($workflow->engineeringReturnBlockReason($atStage))->toBeNull();

        // A devolução da Finalização é do Finalizador.
        $this->actingAs($scenario['finalizer']);

        $component = Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
            ->assertActionVisible('returnToStage')
            ->mountAction('returnToStage');

        expect(paidEngineeringReturnEnabledTargets($component))->toBe([MeasurementWorkflow::STAGE_ENGINEERING, 2, 3, MeasurementWorkflow::STAGE_PAYMENT])
            ->and(paidEngineeringReturnHelperText(paidEngineeringReturnModalField($component, 'target_stage')))->toBeNull();

        $component->setActionData(['target_stage' => MeasurementWorkflow::STAGE_ENGINEERING, 'reason' => 'O laudo da revisão não confere com a vistoria.'])
            ->callMountedAction()
            ->assertHasNoActionErrors();
    }

    $returned = $revision->fresh();

    expect($returned->status)->toBe('in_review')
        ->and($returned->current_stage)->toBe(MeasurementWorkflow::STAGE_ENGINEERING)
        ->and($returned->isEffectiveRevision())->toBeTrue()
        ->and($returned->engineering_snapshot)->toBeNull()
        ->and($returned->reviewForStage(MeasurementWorkflow::STAGE_ENGINEERING)?->status)->toBe('pending')
        ->and($returned->payments()->exists())->toBeFalse()
        // O pagamento da família continua na R0, intocado.
        ->and($may->fresh()->status)->toBe('finalized')
        ->and($may->fresh()->payments()->pluck('id')->all())->toBe([$familyPayment->id])
        ->and($familyPayment->fresh()->amount)->toBe(Scenario::expected(10))
        ->and($familyPayment->fresh()->currentReceiptEvidence?->id)->toBe($familyEvidence->id);

    PhysicalScenario::approveEngineering($scenario, $returned, 9);
    $reapproved = $revision->fresh();

    expect($reapproved->current_stage)->toBe(2)
        ->and($reapproved->engineering_snapshot['plan_sets'][0]['realized_monthly_percent'])->toBe('9.00')
        ->and($reapproved->payments()->exists())->toBeFalse()
        ->and(PhysicalScenario::progress($scenario)->currentPercent())->toBe('9.00')
        ->and($workflow->engineeringReturnBlockReason($reapproved))->toBeNull();
})->with(['Gestão rejection', 'return from Finalization']);

// ── Arquivo novo em medição paga ─────────────────────────────────────────────

it('refuses a new file on a legacy paid measurement back at Engineering at the model', function () {
    $scenario = Scenario::plan();
    $legacy = paidEngineeringReturnLegacyAtEngineering($scenario);
    $before = paidEngineeringReturnFootprint($legacy);
    $path = 'nimbus_docs/measurements/assets/'.Str::uuid().'/forjado.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 arquivo forjado');

    expect($legacy->hasApprovedEngineering())->toBeFalse()
        ->and(paidEngineeringReturnRefusal(fn () => MeasurementAsset::query()->create([
            'measurement_id' => $legacy->id,
            'plan_set_id' => $scenario['planSet']->id,
            'plan_line_id' => $scenario['lines']['2026-06']->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ])))->toBe(MeasurementAsset::PAID_NEW_FILE_REFUSAL)
        ->and(paidEngineeringReturnRefusal(fn () => $legacy->assets()->create([
            'plan_set_id' => $scenario['planSet']->id,
            'plan_line_id' => $scenario['lines']['2026-07']->id,
            'storage_path' => $path,
            'storage_disk' => 'local',
        ])))->toBe(MeasurementAsset::PAID_NEW_FILE_REFUSAL)
        ->and(paidEngineeringReturnFootprint($legacy))->toBe($before)
        ->and(MeasurementAsset::query()->count())->toBe(1)
        ->and(Activity::query()->where('description', 'measurement_asset_created')->count())->toBe(1);
});

it('refuses a forged new item of the edit form on a legacy paid measurement without keeping the uploaded file', function () {
    $scenario = Scenario::plan();
    $legacy = paidEngineeringReturnLegacyAtEngineering($scenario);
    $before = paidEngineeringReturnFootprint($legacy);
    $files = paidEngineeringReturnStoredAssetFiles();
    $this->actingAs($scenario['actor']);

    // O Repeater do Editar não acrescenta item (`addable(false)`), mas o estado
    // do Livewire é de quem envia a requisição: o item forjado chega à
    // gravação com um arquivo enviado de verdade.
    Livewire::test(EditMeasurement::class, ['record' => $legacy->getRouteKey()])
        ->fillForm(['assets' => [(string) Str::uuid() => [
            'plan_set_id' => $scenario['planSet']->id,
            'plan_line_id' => $scenario['lines']['2026-06']->id,
            'storage_path' => [UploadedFile::fake()->createWithContent('forjado.pdf', '%PDF-1.7 arquivo forjado')],
        ]]])
        ->call('save');

    expect(paidEngineeringReturnNotificationBody('Medição não atualizada.'))->toBe(MeasurementAsset::PAID_NEW_FILE_REFUSAL)
        ->and(paidEngineeringReturnFootprint($legacy))->toBe($before)
        ->and(MeasurementAsset::query()->count())->toBe(1)
        ->and(paidEngineeringReturnStoredAssetFiles())->toBe($files);
});

it('refuses the forged new item of the edit form through the real Livewire endpoint', function () {
    $scenario = Scenario::plan();
    $legacy = paidEngineeringReturnLegacyAtEngineering($scenario);
    $before = paidEngineeringReturnFootprint($legacy);
    $files = paidEngineeringReturnStoredAssetFiles();
    $this->actingAs($scenario['actor']);

    $html = $this->get(MeasurementResource::getUrl('edit', ['record' => $legacy]))
        ->assertOk()
        ->getContent();
    $snapshot = ForgedLivewireRequest::snapshotOf($html, EditMeasurement::class);

    // O envio do arquivo pela rota assinada do Livewire, como o navegador faz,
    // e a requisição que o põe num item que o formulário não oferece.
    $uploaded = $this->post((new GenerateSignedUploadUrl)->forLocal(), [
        'files' => [UploadedFile::fake()->createWithContent('forjado.pdf', '%PDF-1.7 arquivo forjado')],
    ])->assertOk()->json('paths');
    $item = (string) Str::uuid();

    ForgedLivewireRequest::post($this, $snapshot, [
        ForgedLivewireRequest::call('_finishUpload', ["data.assets.{$item}.storage_path.".Str::uuid(), $uploaded, false]),
        ForgedLivewireRequest::call('save'),
    ], [
        "data.assets.{$item}.plan_set_id" => $scenario['planSet']->id,
        "data.assets.{$item}.plan_line_id" => $scenario['lines']['2026-06']->id,
    ])->assertOk();

    expect(paidEngineeringReturnNotificationBody('Medição não atualizada.'))->toBe(MeasurementAsset::PAID_NEW_FILE_REFUSAL)
        ->and(paidEngineeringReturnFootprint($legacy))->toBe($before)
        ->and(MeasurementAsset::query()->count())->toBe(1)
        ->and(paidEngineeringReturnStoredAssetFiles())->toBe($files);
});

// ── Os dados aprovados da medição paga ───────────────────────────────────────

it('refuses to rewrite in place the approved data of a paid measurement and points to the formal revision', function (string $column) {
    $scenario = Scenario::plan();
    $paid = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $before = paidEngineeringReturnFootprint($paid);
    $stored = (array) DB::table('measurements')->where('id', $paid->id)->first();
    $change = match ($column) {
        'engineering_snapshot' => ['engineering_snapshot' => array_replace($paid->engineering_snapshot, [
            'plan_sets' => [array_replace($paid->engineering_snapshot['plan_sets'][0], ['realized_monthly_percent' => '8.00'])],
        ])],
        'reference_month' => ['reference_month' => '2026-06-01'],
        'filename' => ['filename' => 'medicao-corrigida.pdf'],
        'storage_path' => ['storage_path' => 'nimbus_docs/measurements/assets/medicao-corrigida.pdf'],
        'storage_disk' => ['storage_disk' => 'public'],
        'sha256' => ['sha256' => str_repeat('a', 64)],
        'file_size' => ['file_size' => 2048],
        'mime_type' => ['mime_type' => 'image/png'],
    };

    expect(paidEngineeringReturnRefusal(fn () => $paid->fresh()->forceFill($change)->save()))
        ->toBe(Measurement::PAID_APPROVED_DATA_REFUSAL)
        ->and((array) DB::table('measurements')->where('id', $paid->id)->first())->toBe($stored)
        ->and(paidEngineeringReturnFootprint($paid))->toBe($before);
})->with(['engineering_snapshot', 'reference_month', 'filename', 'storage_path', 'storage_disk', 'sha256', 'file_size', 'mime_type']);

it('points the approved data and files of a legacy paid measurement without a verifiable record to the return to Engineering', function (string $record) {
    $scenario = Scenario::plan();
    $paid = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $snapshot = $paid->engineering_snapshot;
    $legacySnapshot = match ($record) {
        'no record' => null,
        'record of an older schema' => array_replace($snapshot, ['schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION - 1]),
        'record of another measurement' => array_replace($snapshot, ['measurement_id' => $paid->id + 1]),
        'record without developments' => array_replace($snapshot, ['plan_sets' => []]),
    };
    // Gravado direto no banco, como as linhas legadas ficaram.
    DB::table('measurements')->where('id', $paid->id)->update([
        'engineering_snapshot' => $legacySnapshot === null ? null : json_encode($legacySnapshot),
    ]);
    $legacy = $paid->fresh();
    $stored = (array) DB::table('measurements')->where('id', $paid->id)->first();
    $asset = $legacy->assets()->firstOrFail();
    $storedAsset = (array) DB::table('measurement_assets')->where('id', $asset->id)->first();

    // A revisão formal não existe para ela (não há registro congelado a
    // revisar), e a devolução à Engenharia está aberta: é o caminho que o texto
    // indica, não "Criar revisão".
    expect(app(MeasurementRevisionService::class)->creationBlockReason($legacy))->not->toBeNull()
        ->and(app(MeasurementWorkflow::class)->engineeringReturnBlockReason($legacy))->toBeNull()
        ->and(paidEngineeringReturnRefusal(fn () => $legacy->fresh()->forceFill(['filename' => 'medicao-corrigida.pdf'])->save()))
        ->toBe('Os dados aprovados pela Engenharia estão bloqueados. Devolva a medição à Engenharia para corrigi-los.')
        ->and(paidEngineeringReturnRefusal(fn () => $asset->fresh()->forceFill(['filename' => 'arquivo-corrigido.pdf'])->save()))
        ->toBe('Os arquivos aprovados pela Engenharia estão bloqueados. Devolva a medição à Engenharia para alterá-los.')
        ->and(paidEngineeringReturnRefusal(fn () => $asset->fresh()->delete()))
        ->toBe('Um arquivo aprovado pela Engenharia não pode ser removido sem devolver a medição à Engenharia.')
        ->and((array) DB::table('measurements')->where('id', $paid->id)->first())->toBe($stored)
        ->and((array) DB::table('measurement_assets')->where('id', $asset->id)->first())->toBe($storedAsset);
})->with(['no record', 'record of an older schema', 'record of another measurement', 'record without developments']);

it('points the approved files of a paid measurement with a verifiable record to the formal revision', function () {
    $scenario = Scenario::plan();
    $paid = Scenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    $asset = $paid->assets()->firstOrFail();
    $storedAsset = (array) DB::table('measurement_assets')->where('id', $asset->id)->first();

    expect(paidEngineeringReturnRefusal(fn () => $asset->fresh()->forceFill(['filename' => 'arquivo-corrigido.pdf'])->save()))
        ->toBe(MeasurementAsset::PAID_APPROVED_FILE_REFUSAL)
        ->and(paidEngineeringReturnRefusal(fn () => $asset->fresh()->delete()))
        ->toBe(MeasurementAsset::PAID_APPROVED_FILE_REFUSAL)
        ->and((array) DB::table('measurement_assets')->where('id', $asset->id)->first())->toBe($storedAsset);
});

it('still points the approved data of an unpaid measurement to the return to Engineering', function () {
    $scenario = Scenario::plan();
    $unpaid = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $stored = (array) DB::table('measurements')->where('id', $unpaid->id)->first();

    expect($unpaid->payments()->exists())->toBeFalse()
        ->and(paidEngineeringReturnRefusal(fn () => $unpaid->fresh()->forceFill([
            'engineering_snapshot' => array_replace($unpaid->engineering_snapshot, ['plan_sets' => []]),
        ])->save()))->toBe('Os dados aprovados pela Engenharia estão bloqueados. Devolva a medição à Engenharia para corrigi-los.')
        ->and((array) DB::table('measurements')->where('id', $unpaid->id)->first())->toBe($stored);
});

// ── Defesa em profundidade ───────────────────────────────────────────────────

/**
 * A reabertura de etapa é privada e hoje só é chamada pela recusa e pela
 * devolução da Finalização, que conferem a medição paga antes. A trava dela
 * mesma é o que segura um chamador futuro que esqueça a conferência: chamada
 * direto, ela recusa antes de qualquer gravação.
 */
it('keeps the approved record of a paid measurement even when the stage reopening is reached without the callers checks', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $paid = paidEngineeringReturnAtManagement($scenario);
    $before = paidEngineeringReturnFootprint($paid);
    $reopenStage = Closure::bind(
        fn (Measurement $measurement, User $actor, int $target): mixed => $this->reopenStage($measurement, $actor, $target, 'Reabertura direta.', 'stage2_reviewer_user_id'),
        $workflow,
        MeasurementWorkflow::class,
    );

    expect(paidEngineeringReturnRefusal(fn () => DB::transaction(fn () => $reopenStage($paid->fresh(), $scenario['actor'], MeasurementWorkflow::STAGE_ENGINEERING))))
        ->toBe(MeasurementWorkflow::PAID_ENGINEERING_RETURN_REFUSAL)
        ->and(paidEngineeringReturnFootprint($paid))->toBe($before);
});

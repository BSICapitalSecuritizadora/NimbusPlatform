<?php

use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementNoDisbursementScenario as Scenario;
use Tests\Support\MeasurementRevisionScenario;

/**
 * A decisão sem desembolso como a pessoa a vê na página da medição: o modal da
 * etapa Pagamento, a ciência da Finalização, a página depois de finalizada e a
 * data do pagamento no fim do dia em Brasília. As regras do domínio estão em
 * MeasurementNoDisbursementTest; aqui, o que a tela oferece, esconde e recusa.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * O schema do modal montado.
 */
function p4aUiMountedSchema(Testable $component): Schema
{
    $page = $component->instance();

    return $page->{$page->getMountedActionSchemaName()};
}

/**
 * Componente do modal montado pelo fim do caminho (`notes`, `amount_due`...),
 * ou `null` quando ele não aparece. Com `$withHidden`, também o que existe no
 * schema mas está oculto.
 */
function p4aUiModalComponent(Testable $component, string $name, bool $withHidden = false): mixed
{
    foreach (p4aUiMountedSchema($component)->getFlatComponents(withHidden: $withHidden) as $key => $item) {
        if (((string) $key === $name) || str_ends_with((string) $key, ".{$name}")) {
            return $item;
        }
    }

    return null;
}

/**
 * Texto de um Placeholder visível do modal. O modal vai na resposta como JSON
 * escapado, então o conteúdo é lido pelo schema montado; as tags viram espaço,
 * para os blocos não grudarem um no outro.
 */
function p4aUiModalText(Testable $component, string $name): string
{
    $placeholder = p4aUiModalComponent($component, $name);

    if (! $placeholder instanceof Placeholder) {
        return '';
    }

    $text = strip_tags((string) preg_replace('/</', ' <', (string) $placeholder->getContent()));

    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
}

/**
 * Corpo da notificação do Filament da última requisição, lido sem consumir a
 * sessão -- o `assertNotified` compara só o título e a esvazia.
 */
function p4aUiNotificationBody(string $title): ?string
{
    $notifications = session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [];
    $body = collect($notifications)->firstWhere('title', $title)['body'] ?? null;

    return $body === null ? null : (string) $body;
}

function p4aUiActivity(Measurement $measurement, string $description, ?int $stage = null): ?Activity
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('description', $description)
        ->where('subject_type', $measurement->getMorphClass())
        ->where('subject_id', $measurement->getKey())
        ->latest('id')
        ->get()
        ->first(fn (Activity $activity): bool => $stage === null || (int) ($activity->properties['stage'] ?? 0) === $stage);
}

/**
 * O que uma ação recusada não pode ter mudado no fluxo da medição.
 *
 * @return array{status: string, current_stage: int, workflow_revision: int, payment_review: string|null}
 */
function p4aUiWorkflowState(Measurement $measurement): array
{
    $fresh = $measurement->fresh();

    return [
        'status' => (string) $fresh->status,
        'current_stage' => (int) $fresh->current_stage,
        'workflow_revision' => (int) $fresh->workflow_revision,
        'payment_review' => $fresh->reviewForStage(MeasurementWorkflow::STAGE_PAYMENT)?->status,
    ];
}

/**
 * Chave real (UUID) do primeiro item do Repeater de pagamentos do modal montado.
 */
function p4aUiPaymentItemKey(Testable $component): string
{
    return (string) array_key_first($component->get('mountedActions.0.data.payments') ?? []);
}

/**
 * Original de um empreendimento (Fundo de Obra R$ 1.000.000,00) aprovada a 10%
 * em outubro e parada na etapa Pagamento, às 22h30 de 31/10/2026 em Brasília --
 * quando o dia UTC já é 01/11/2026. Quem paga já está autenticado.
 *
 * @return array{scenario: array<string, mixed>, measurement: Measurement}
 */
function p4aUiPayableLateEveningInBrasilia(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-31 22:30:00', 'America/Sao_Paulo'));
    $scenario = Scenario::plan(months: ['2026-09', '2026-10']);
    $measurement = Scenario::awaitingPayment($scenario, '2026-10', [10]);
    test()->actingAs($scenario['actor']);

    return compact('scenario', 'measurement');
}

// ── Etapa Pagamento: nada a pagar ────────────────────────────────────────────

it('offers the no-disbursement decision instead of the stage approval to the payment manager of a zero-due original', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $this->actingAs($scenario['actor']);

    // Quem vê a página decide a etapa: a aprovação some pela regra do nada a
    // pagar, não por falta de autorização.
    expect(app(MeasurementWorkflow::class)->canApprove($measurement, $scenario['actor']))->toBeTrue();

    // A aprovação comum some: a etapa termina pela decisão expressa. O registro
    // de pagamento continua à vista.
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('concludeWithoutDisbursement')
        ->assertActionExists('concludeWithoutDisbursement', fn (Action $action): bool => $action->getLabel() === 'Concluir etapa sem desembolso'
            && $action->getModalHeading() === 'Decisão de pagamento sem desembolso')
        ->assertActionHidden('approve')
        ->assertActionVisible('registerPayment')
        ->mountAction('concludeWithoutDisbursement')
        ->assertActionMounted('concludeWithoutDisbursement');

    // A posição de cada empreendimento, como a pessoa confere antes de decidir.
    expect(p4aUiModalText($component, 'amount_due'))
        ->toContain('Não há desembolso financeiro nesta medição')
        ->toContain('Torre A · Fundo de Obra R$ 1.000.000,00 · realizado no mês 0,00% · Valor devido: R$ 0,00')
        ->and(p4aUiModalComponent($component, 'amount_due')?->getLabel())->toBe('Nenhum pagamento devido')
        ->and(p4aUiModalComponent($component, 'notes'))->toBeInstanceOf(Textarea::class)
        ->and(p4aUiModalComponent($component, 'notes')?->getLabel())->toBe('Justificativa obrigatória')
        ->and(p4aUiModalComponent($component, 'notes')?->isRequired())->toBeTrue();
});

it('requires the justification in the no-disbursement modal and keeps the Payment stage open without it', function (string $notes) {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $before = p4aUiWorkflowState($measurement);
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->callAction('concludeWithoutDisbursement', data: ['notes' => $notes]);

    // A mensagem tem ':' -- o assertHasErrors do Livewire a leria como "regra:parâmetros".
    expect($component->errors()->get('mountedActions.0.data.notes'))->toBe([MeasurementWorkflow::NO_DISBURSEMENT_JUSTIFICATION_REQUIRED])
        ->and(p4aUiWorkflowState($measurement))->toBe($before)
        ->and(p4aUiActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toBeNull()
        ->and(MeasurementPayment::query()->count())->toBe(0);

    $component->assertActionMounted('concludeWithoutDisbursement');
})->with([
    'empty' => [''],
    'only spaces' => ['   '],
]);

it('concludes the Payment stage without disbursement from the modal, with the typed justification and no payment record', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $justification = 'Obra sem avanço físico em maio: nada a pagar nesta competência.';
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->callAction('concludeWithoutDisbursement', data: ['notes' => $justification])
        ->assertHasNoActionErrors()
        ->assertActionNotMounted()
        ->assertActionHidden('concludeWithoutDisbursement')
        ->assertActionHidden('registerPayment')
        // Fase 4B-1: a Finalização é do Finalizador, não de quem decidiu sem desembolso.
        ->assertActionHidden('finalize')
        ->assertNotified('Etapa Pagamento concluída sem desembolso. A Finalização deverá confirmar ciência desta decisão.');

    $this->actingAs($scenario['finalizer']);
    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('finalize');

    $measurement->refresh();
    $decision = p4aUiActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT);

    // Nenhum pagamento -- nem de valor zero; a justificativa digitada é a da
    // decisão na trilha protegida.
    expect($measurement->status)->toBe('approved')
        ->and((int) $measurement->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and($measurement->reviewForStage(MeasurementWorkflow::STAGE_PAYMENT)?->notes)->toBe($justification)
        ->and($decision?->causer_id)->toBe($scenario['actor']->id)
        ->and($decision?->properties['no_disbursement']['decision'] ?? null)->toBe('no_disbursement')
        ->and($decision?->properties['no_disbursement']['justification'] ?? null)->toBe($justification)
        ->and($decision?->properties['no_disbursement']['payments_registered'] ?? null)->toBe(0);

    Notification::assertSentTo(
        $scenario['finalizer'],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'ready_to_finalize_without_disbursement',
    );
});

it('keeps the payment mandatory in the approval modal and tells why the stage cannot end without disbursement', function (array $developments, array $percents, string $refusal, array $absent) {
    $scenario = Scenario::plan($developments);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', $percents);
    $before = p4aUiWorkflowState($measurement);
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionHidden('concludeWithoutDisbursement')
        ->assertActionVisible('approve')
        ->assertActionVisible('registerPayment')
        ->mountAction('approve');

    $refusals = p4aUiModalText($component, 'amount_due_refusals');

    expect(p4aUiModalText($component, 'payment_required'))->toBe('Cadastre ao menos um pagamento antes de aprovar a etapa Pagamento.')
        ->and($refusals)->toContain($refusal)
        ->and(p4aUiModalComponent($component, 'notes'))->toBeNull();

    foreach ($absent as $text) {
        expect($refusals)->not->toContain($text);
    }

    // Aprovar assim mesmo: o domínio recusa, e a pessoa lê o que falta.
    $component->callMountedAction();

    expect(p4aUiNotificationBody('Ação não concluída.'))->toBe('Cadastre ao menos um pagamento válido antes de aprovar a etapa Pagamento.')
        ->and(p4aUiWorkflowState($measurement))->toBe($before)
        ->and(MeasurementPayment::query()->count())->toBe(0);
})->with([
    'payable construction at 10%' => [[['fund' => '1000000.00']], [10], 'Torre A: valor devido R$ 100.000,00.', []],
    'construction without Construction Fund' => [[['fund' => null]], [0], 'Torre A: sem Fundo de Obra', []],
    'zero-due construction next to a payable one' => [[['fund' => '1000000.00'], ['fund' => '1200000.00']], [0, 10], 'Torre B: valor devido R$ 120.000,00.', ['Torre A']],
]);

it('hides the no-disbursement decision from a participant who is not the payment manager and ignores the forged calls', function (string $column) {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $participant = User::factory()->withTwoFactor()->create();
    $participant->givePermissionTo(['measurements.view', 'measurements.review', 'measurements.pay', 'measurements.finalize']);
    // Antes do actingAs: com alguém autenticado, trocar um responsável da
    // operação passa pela conferência de quem pode trocá-lo.
    $scenario['operation']->forceFill([$column => $participant->id])->save();
    $workflow = app(MeasurementWorkflow::class);
    $before = p4aUiWorkflowState($measurement);
    $data = ['notes' => 'Tentativa forjada de concluir sem desembolso.', 'expected_revision' => $before['workflow_revision']];

    // O Gestor de Pagamento poderia decidir: a recusa abaixo é de autorização,
    // não de estado.
    expect($workflow->canConcludeWithoutDisbursement($measurement->fresh(), $scenario['actor']))->toBeTrue()
        ->and($workflow->canConcludeWithoutDisbursement($measurement->fresh(), $participant))->toBeFalse();

    $this->actingAs($participant);

    // O navegador forjando o envio: montar a ação oculta e enviá-la. O
    // Filament não monta ação oculta.
    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('concludeWithoutDisbursement')
        ->assertActionHidden('approve')
        ->assertActionHidden('registerPayment')
        ->call('mountAction', 'concludeWithoutDisbursement')
        ->assertActionNotMounted()
        ->set('mountedActions.0.data', $data)
        ->call('callMountedAction');

    // Ou escrevendo no estado do componente a ação como se já estivesse
    // montada: a página recusa o envio da ação que não é de quem envia.
    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->set('mountedActions', [['name' => 'concludeWithoutDisbursement', 'arguments' => [], 'context' => [], 'data' => $data]])
        ->call('callMountedAction')
        ->assertActionNotMounted();

    expect(p4aUiNotificationBody('Ação não concluída.'))
        ->toBe('A medição mudou depois que esta janela foi aberta e esta ação não está mais disponível para você. Atualize a página.')
        ->and(p4aUiWorkflowState($measurement))->toBe($before)
        ->and(p4aUiActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toBeNull()
        ->and(MeasurementPayment::query()->count())->toBe(0);

    Notification::assertNotSentTo(
        $scenario['actor'],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'ready_to_finalize_without_disbursement',
    );
})->with([
    'operation coordinator' => ['assigned_user_id'],
    'Compliance reviewer' => ['stage3_reviewer_user_id'],
]);

it('ignores a forged ordinary approval of a zero-due original, whose Payment stage only ends by the decision', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $before = p4aUiWorkflowState($measurement);
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionHidden('approve')
        ->set('mountedActions', [[
            'name' => 'approve',
            'arguments' => [],
            'context' => [],
            'data' => [
                'notes' => 'Aprovada sem pagamento.',
                'expected_stage' => MeasurementWorkflow::STAGE_PAYMENT,
                'expected_revision' => $before['workflow_revision'],
            ],
        ]])
        ->call('callMountedAction')
        ->assertActionNotMounted();

    expect(p4aUiNotificationBody('Ação não concluída.'))
        ->toBe('A medição mudou depois que esta janela foi aberta e esta ação não está mais disponível para você. Atualize a página.')
        ->and(p4aUiWorkflowState($measurement))->toBe($before)
        ->and(p4aUiActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toBeNull();
});

// ── Finalização ──────────────────────────────────────────────────────────────

it('shows the no-disbursement decision at the Finalization and requires the acknowledgment of the absence of payment', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));
    // Fase 4B-1: a ciência é do Finalizador, não de quem decidiu sem desembolso.
    $this->actingAs($scenario['finalizer']);

    $page = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('finalize')
        ->mountAction('finalize')
        ->assertSet('mountedActions.0.data.acknowledge_no_disbursement', false);

    // O Finalizador lê a decisão -- a justificativa, quem decidiu e a posição
    // de cada empreendimento -- antes de confirmar a ciência.
    expect(p4aUiModalText($page, 'no_disbursement_decision'))
        ->toContain('Medição sem desembolso financeiro: nenhum pagamento é devido e nenhum pagamento foi registrado.')
        ->toContain('Justificativa: '.Scenario::JUSTIFICATION)
        ->toContain('Decidida por '.$scenario['actor']->name)
        ->toContain('Torre A · Fundo de Obra R$ 1.000.000,00 · realizado no mês 0,00% · Valor devido: R$ 0,00')
        ->and(p4aUiModalComponent($page, 'acknowledge_no_disbursement'))->toBeInstanceOf(Checkbox::class)
        ->and(p4aUiModalComponent($page, 'accept_financial_exceptions'))->toBeNull();

    $page->callMountedAction();

    // A mensagem tem ':' -- lida do error bag, não pelo assertHasErrors.
    expect($page->errors()->get('mountedActions.0.data.acknowledge_no_disbursement'))->toBe([MeasurementWorkflow::NO_DISBURSEMENT_ACKNOWLEDGMENT_REQUIRED])
        ->and($measurement->fresh()->status)->toBe('approved')
        ->and(p4aUiActivity($measurement, 'measurement_finalized'))->toBeNull();

    $page->assertActionMounted('finalize')
        ->set('mountedActions.0.data.acknowledge_no_disbursement', true)
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Medição finalizada.');

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(app(MeasurementWorkflow::class)->noDisbursementAcknowledgment($measurement->fresh()))->toMatchArray([
            'justification' => Scenario::JUSTIFICATION,
            'payments_registered' => 0,
            'acknowledged_by' => $scenario['finalizer']->id,
            'acknowledged' => [
                'no_payment_due' => true,
                'no_bank_payment_registered' => true,
                'payment_stage_justification_reviewed' => true,
            ],
        ]);
});

it('does not ask for the acknowledgment of the absence of payment at the Finalization of a paid original', function () {
    $scenario = MeasurementRevisionScenario::plan();
    $measurement = MeasurementRevisionScenario::paidAwaitingFinalization($scenario, '2026-05', 10);
    // Fase 4B-1: a Finalização é do Finalizador do cenário, separado de quem paga.
    $this->actingAs($scenario['finalizer']);

    $page = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('finalize')
        ->mountAction('finalize');

    // O campo existe no schema, oculto: a medição paga não tem o que reconhecer.
    expect(p4aUiModalComponent($page, 'acknowledge_no_disbursement'))->toBeNull()
        ->and(p4aUiModalComponent($page, 'acknowledge_no_disbursement', withHidden: true))->toBeInstanceOf(Checkbox::class)
        ->and(p4aUiModalComponent($page, 'no_disbursement_decision'))->toBeNull();

    $page->callMountedAction()->assertHasNoActionErrors();

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and(p4aUiActivity($measurement, 'measurement_finalized')?->properties->has('no_disbursement_acknowledged'))->toBeFalse();
});

it('keeps the ordinary approval and Finalization, without decision or acknowledgment, for a revision with nothing to pay', function () {
    $scenario = MeasurementRevisionScenario::plan();
    $revision = MeasurementRevisionScenario::effective($scenario, MeasurementRevisionScenario::awaitingPayment($scenario, '2026-05', 10), 0);
    $this->actingAs($scenario['actor']);

    // A revisão termina a etapa Pagamento pela posição financeira da família:
    // a aprovação de sempre, sem o pagamento exigido e sem a decisão da original.
    $component = Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertActionHidden('concludeWithoutDisbursement')
        ->assertActionVisible('approve')
        ->mountAction('approve')
        ->assertActionMounted('approve');

    expect(p4aUiModalComponent($component, 'payment_required'))->toBeNull()
        ->and(p4aUiModalComponent($component, 'amount_due_refusals'))->toBeNull()
        ->and(p4aUiModalComponent($component, 'notes')?->getLabel())->toBe('Comentário (opcional)');

    $component->callMountedAction()->assertHasNoActionErrors();

    expect($revision->fresh()->status)->toBe('approved')
        ->and(p4aUiActivity($revision, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)?->properties->has('no_disbursement'))->toBeFalse();

    // Fase 4B-1: a Finalização é do Finalizador do cenário, separado de quem aprova o Pagamento.
    $this->actingAs($scenario['finalizer']);

    $page = Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertActionVisible('finalize')
        ->mountAction('finalize');

    expect(p4aUiModalComponent($page, 'acknowledge_no_disbursement'))->toBeNull()
        ->and(p4aUiModalComponent($page, 'no_disbursement_decision'))->toBeNull();

    $page->callMountedAction()->assertHasNoActionErrors();

    expect($revision->fresh()->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

// ── Página da medição finalizada ─────────────────────────────────────────────

it('shows the no-disbursement decision and its acknowledgment on the page of the finalized original', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::finalizeAcknowledging(
        $scenario,
        Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0])),
    );
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertSeeText('Nenhum pagamento devido')
        ->assertSeeText('valor devido R$ 0,00')
        ->assertSeeText('Decisão de pagamento sem desembolso: '.Scenario::JUSTIFICATION)
        ->assertSeeText('Ciência da ausência de pagamento confirmada na Finalização')
        ->assertSeeText('Medição sem desembolso financeiro.')
        ->assertDontSeeText('Nenhum pagamento registrado nesta medição.');
});

it('does not describe a paid original or a revision without own payment as a measurement without disbursement', function (string $case, string $seen) {
    $scenario = MeasurementRevisionScenario::plan();
    $measurement = match ($case) {
        'paid original' => MeasurementRevisionScenario::finalized($scenario, '2026-05', 10),
        // A revisão a 0% da original não paga: nada é devido e nada foi pago,
        // mas a revisão termina pela posição da família, sem a decisão da original.
        'revision without own payment' => MeasurementRevisionScenario::closePaymentAndFinalize(
            $scenario,
            MeasurementRevisionScenario::effective($scenario, MeasurementRevisionScenario::awaitingPayment($scenario, '2026-05', 10), 0),
        ),
    };
    $this->actingAs($scenario['actor']);

    expect($measurement->status)->toBe('finalized');

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSuccessful()
        ->assertSeeText($seen)
        ->assertDontSeeText('Medição sem desembolso financeiro.')
        ->assertDontSeeText('Nenhum pagamento devido')
        ->assertDontSeeText('Decisão de pagamento sem desembolso')
        ->assertDontSeeText('Ciência da ausência de pagamento confirmada na Finalização');
})->with([
    'paid original' => ['paid original', 'R$ 100.000,00'],
    'revision without own payment' => ['revision without own payment', 'Nenhum pagamento registrado nesta medição.'],
]);

// ── Data do pagamento ────────────────────────────────────────────────────────

it('defaults the payment date to the Brasília civil date when the UTC day has already turned', function () {
    ['measurement' => $measurement] = p4aUiPayableLateEveningInBrasilia();

    // Guarda do cenário: no UTC já é 01/11.
    expect(now()->toDateString())->toBe('2026-11-01');

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->assertFormFieldExists('pay_date', fn (DatePicker $field): bool => $field->getMaxDate() === '2026-10-31');

    expect((string) $component->get('mountedActions.0.data.pay_date'))->toStartWith('2026-10-31');

    $component->set('mountedActions.0.data.payments.'.p4aUiPaymentItemKey($component).'.amount', '100.000,00')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $payment = MeasurementPayment::query()->sole();

    expect($payment->pay_date->toDateString())->toBe('2026-10-31')
        ->and($payment->financial_assessment['pay_date'] ?? null)->toBe('2026-10-31')
        ->and($payment->amount)->toBe('100000.00');
});

it('refuses on the payment date field a date after the Brasília civil date and registers nothing', function () {
    ['measurement' => $measurement] = p4aUiPayableLateEveningInBrasilia();
    $before = p4aUiWorkflowState($measurement);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment');

    // 01/11 já é "hoje" no UTC, mas ainda é amanhã em Brasília.
    $component->set('mountedActions.0.data.pay_date', '2026-11-01')
        ->set('mountedActions.0.data.payments.'.p4aUiPaymentItemKey($component).'.amount', '100.000,00')
        ->callMountedAction();

    // A mensagem tem ':' -- lida do error bag, não pelo assertHasErrors.
    expect($component->errors()->get('mountedActions.0.data.pay_date'))->toBe([MeasurementWorkflow::FUTURE_PAY_DATE_REFUSAL])
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(p4aUiWorkflowState($measurement))->toBe($before);

    $component->assertActionMounted('registerPayment');
});

it('warns in the payment registration of a zero-due original that nothing is due, since a payment would close the no-disbursement path for good', function () {
    // Os dois cenários antes de qualquer login: a criação do plano confere a
    // visibilidade da obra para quem está autenticado.
    $scenario = Scenario::plan();
    $zeroDue = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $payableScenario = Scenario::plan();
    $payable = Scenario::awaitingPayment($payableScenario, '2026-05', [10]);
    $this->actingAs($scenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $zeroDue->getRouteKey()])
        ->mountAction('registerPayment')
        ->assertActionMounted('registerPayment');

    expect(p4aUiModalText($component, 'nothing_due'))
        ->toContain('Nenhum pagamento devido')
        ->toContain('Concluir etapa sem desembolso')
        ->toContain('Um pagamento registrado não pode ser alterado nem excluído');

    // Com valor devido, o aviso não aparece: o pagamento é o caminho.
    $this->actingAs($payableScenario['actor']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $payable->getRouteKey()])
        ->mountAction('registerPayment')
        ->assertActionMounted('registerPayment');

    expect(p4aUiModalComponent($component, 'nothing_due'))->toBeNull();
});

<?php

use App\Enums\MeasurementReceiptReviewStatus;
use App\Enums\MeasurementRevisionStatus;
use App\Exceptions\MeasurementSegregationException;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\User;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementSegregationService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementGovernanceScenario as Governance;
use Tests\Support\MeasurementReceiptEvidenceScenario;

/**
 * A governança financeira e a segregação de funções da Fase 4B-1 como a pessoa
 * as vê na página da medição: o excedente que só entra com regra, as regras
 * que quem paga pode escolher e a cobertura delas, o motivo do override
 * administrativo, e as ações desabilitadas -- com o motivo -- para quem
 * conferiria o próprio ato. As regras do domínio estão em
 * MeasurementFinancialPolicyTest e MeasurementSegregationOfDutiesTest.
 */
pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * Componente do modal montado pelo fim do caminho (`override_reason`,
 * `segregation_notice`...); com `$itemKey`, o do item do Repeater de
 * pagamentos. `null` quando ele não aparece -- com `$withHidden`, também o que
 * existe no schema mas está oculto.
 */
function governanceUiModalComponent(Testable $component, string $name, ?string $itemKey = null, bool $withHidden = false): mixed
{
    $page = $component->instance();
    /** @var Schema $schema */
    $schema = $page->{$page->getMountedActionSchemaName()};
    $suffix = $itemKey === null ? ".{$name}" : ".{$itemKey}.{$name}";

    foreach ($schema->getFlatComponents(withHidden: $withHidden) as $key => $item) {
        if ((($itemKey === null) && ((string) $key === $name)) || str_ends_with((string) $key, $suffix)) {
            return $item;
        }
    }

    return null;
}

/**
 * Texto de um Placeholder visível do modal. O modal vai na resposta como JSON
 * escapado, então o conteúdo é lido pelo schema montado; as tags viram espaço.
 */
function governanceUiModalText(Testable $component, string $name, ?string $itemKey = null): string
{
    $placeholder = governanceUiModalComponent($component, $name, $itemKey);

    if (! $placeholder instanceof Placeholder) {
        return '';
    }

    $text = strip_tags((string) preg_replace('/</', ' <', (string) $placeholder->getContent()));

    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
}

/**
 * Chave real (UUID) do item do Repeater de pagamentos do empreendimento.
 */
function governanceUiItemKey(Testable $component, int $planSetId): string
{
    return (string) collect($component->get('mountedActions.0.data.payments') ?? [])
        ->search(fn (array $item): bool => (int) ($item['plan_set_id'] ?? 0) === $planSetId);
}

/**
 * O texto de ajuda do campo (o Filament o guarda abaixo do campo), ou `null`.
 */
function governanceUiHelperText(Field $field): ?string
{
    $texts = collect($field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [])
        ->filter(fn (mixed $component): bool => $component instanceof Text)
        ->map(fn (Text $text): string => trim((string) $text->getContent()))
        ->filter()
        ->values();

    return $texts->isEmpty() ? null : $texts->implode(' ');
}

/**
 * O tooltip da ação do cabeçalho, como a página o configura agora.
 */
function governanceUiTooltip(Testable $component, string $action): ?string
{
    $tooltip = null;

    $component->assertActionExists($action, function (Action $mounted) use (&$tooltip): bool {
        $tooltip = $mounted->getTooltip();

        return true;
    });

    return $tooltip;
}

/**
 * A notificação do Filament da última requisição com este título, lida sem
 * consumir a sessão -- o `assertNotified` compara só o título e a esvazia.
 *
 * @return array<string, mixed>|null
 */
function governanceUiNotification(string $title): ?array
{
    return collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications') ?? [])
        ->firstWhere('title', $title);
}

function governanceUiActivity(Measurement $measurement, string $description, ?int $stage = null): ?Activity
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
 * @return array{status: string, current_stage: int, workflow_revision: int, revision_status: string, payments: int}
 */
function governanceUiState(Measurement $measurement): array
{
    $fresh = $measurement->fresh();

    return [
        'status' => (string) $fresh->status,
        'current_stage' => (int) $fresh->current_stage,
        'workflow_revision' => (int) $fresh->workflow_revision,
        'revision_status' => $fresh->revisionStatus()->value,
        'payments' => $fresh->payments()->count(),
    ];
}

/**
 * Original de maio aprovada a 10% nos dois empreendimentos (Torre Norte:
 * R$ 100.000,00; Torre Sul: R$ 50.000,00), paga pelo Gestor de Pagamento -- a
 * Torre Norte R$ 3.000,00 acima do aprovado, coberta pela regra de quem
 * cadastra regras, e a Torre Sul pelo valor aprovado --, com a etapa
 * Pagamento aprovada por ele e os comprovantes conferidos: pronta para a
 * Finalização.
 *
 * @return array{scenario: array<string, mixed>, measurement: Measurement, excess: MeasurementPayment}
 */
function governanceUiExcessReadyToFinalize(): array
{
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario);
    $payments = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '103000.00', ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]),
        Governance::row($scenario, 1, '50000.00'),
    ]);

    return [
        'scenario' => $scenario,
        'measurement' => Governance::readyToFinalize($scenario, $measurement),
        'excess' => $payments->first(),
    ];
}

/**
 * A medição parada onde a ação do cabeçalho aparece, quem responde por ela e
 * quem só a faria pelo override administrativo.
 *
 * @param  array<string, mixed>  $scenario
 * @return array{0: Measurement, 1: User, 2: User}
 */
function governanceUiAtAction(array $scenario, string $action): array
{
    if ($action === 'submitRevision') {
        // Só o super-admin revisa fora da operação -- pelo override.
        $draft = Governance::revise($scenario, Governance::finalized($scenario, '2026-05', [10, 10]));

        return [$draft, $scenario['coordinator'], $scenario['superAdmin']];
    }

    if ($action === 'resume') {
        $measurement = Governance::measured($scenario, '2026-05', [10, 10]);
        app(MeasurementWorkflow::class)->pause($measurement->fresh(), $scenario['manager'], 'Aguardando o laudo complementar da obra.');

        return [$measurement->fresh(), $scenario['manager'], $scenario['admin']];
    }

    if ($action === 'concludeWithoutDisbursement') {
        return [Governance::awaitingPayment($scenario, '2026-05', [0, 0]), $scenario['payer'], $scenario['admin']];
    }

    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);

    if ($action === 'registerPayment') {
        return [$measurement, $scenario['payer'], $scenario['admin']];
    }

    Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '100000.00'), Governance::row($scenario, 1, '50000.00')]);
    Governance::approvePaymentStage($scenario, $measurement);

    if ($action === 'attachReceipt') {
        return [$measurement->fresh(), $scenario['uploader'], $scenario['admin']];
    }

    Governance::uploadReceipts($scenario, $measurement);

    if ($action === 'reviewReceipt') {
        return [$measurement->fresh(), $scenario['finalizer'], $scenario['admin']];
    }

    Governance::approveReceipts($scenario, $measurement);

    return [$measurement->fresh(), $scenario['finalizer'], $scenario['admin']];
}

// ── Registro de pagamento: excedente e regra financeira ─────────────────────

it('warns in the payment modal that an amount above the remaining balance needs a financial rule, and refuses it without one', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $before = governanceUiState($measurement);
    $this->actingAs($scenario['payer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('registerPayment')
        ->mountAction('registerPayment')
        ->set('mountedActions.0.data.pay_date', Governance::PAY_DATE);
    $item = governanceUiItemKey($component, $scenario['planSets'][0]->id);

    $component->set("mountedActions.0.data.payments.{$item}.amount", '100.000,00');

    expect(governanceUiModalText($component, 'reconciliation_divergence', $item))
        ->toBe('Conciliado — o valor informado corresponde ao saldo esperado.');

    $component->set("mountedActions.0.data.payments.{$item}.amount", '103.000,00');

    // O que passa do saldo é excedente: a frase diz quanto a regra precisa
    // cobrir e que a justificativa sozinha não basta.
    expect(governanceUiModalText($component, 'reconciliation_divergence', $item))
        ->toBe('Excede o saldo a pagar — o excedente acumulado do empreendimento chega a R$ 3.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta.');

    // Fase 4B-2: com o comprovante anexado, a recusa é a do excedente.
    $component
        ->set("mountedActions.0.data.payments.{$item}.financial_justification", Governance::JUSTIFICATION)
        ->set('mountedActions.0.data.receipt', MeasurementReceiptEvidenceScenario::file())
        ->callMountedAction();

    // A mensagem tem ':' -- lida do error bag, não pelo assertHasErrors.
    expect($component->errors()->get("mountedActions.0.data.payments.{$item}.amount"))
        ->toBe([MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL.' Torre Norte: valor aprovado R$ 100.000,00, já registrado R$ 0,00, saldo a pagar R$ 100.000,00; com este pagamento, o excedente acumulado chega a R$ 3.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta.'])
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(governanceUiState($measurement))->toBe($before)
        ->and(governanceUiActivity($measurement, 'measurement_payment_registered'))->toBeNull();

    $component->assertActionMounted('registerPayment');
});

it('offers in the rule select only the rules the payer may apply, with the limits of each one in the label', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // Quem paga também cadastra regras: a regra dele não autoriza o próprio
    // pagamento.
    $scenario['payer']->givePermissionTo(['measurements.financial-rules.view', 'measurements.financial-rules.manage']);
    $ownRule = Governance::rule($scenario, ['name' => 'Adiantamento do gestor'], $scenario['payer']);
    $monetary = Governance::rule($scenario, ['name' => 'Tolerância contratual']);
    $percent = Governance::rule($scenario, ['name' => 'Tolerância percentual', 'maximum_difference_amount' => null, 'maximum_difference_percent' => '5.0000']);
    // O percentual da base anterior (sobre o saldo) não autoriza divergência
    // alguma: nem aparece.
    Governance::rule($scenario, ['name' => 'Tolerância legada', 'maximum_difference_amount' => null, 'maximum_difference_percent' => '5.0000', 'percent_basis' => null]);
    $this->actingAs($scenario['payer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->set('mountedActions.0.data.pay_date', Governance::PAY_DATE);
    $select = governanceUiModalComponent($component, 'financial_rule_id', governanceUiItemKey($component, $scenario['planSets'][0]->id));

    expect($select)->toBeInstanceOf(Select::class)
        ->and($select->getPlaceholder())->toBe('Nenhuma regra')
        ->and($select->getOptions())->toBe([
            $monetary->id => 'Tolerância contratual · versão 1 · até R$ 5.000,00 · para mais',
            $percent->id => 'Tolerância percentual · versão 1 · 5% do valor aprovado · para mais',
        ])
        ->and($select->getOptions())->not->toHaveKey($ownRule->id);

    // A regra não some por estar indisponível: quem não a registrou a vê.
    expect(app(MeasurementPaymentFinancialService::class)
        ->selectableRules($measurement->fresh(), $scenario['planSets'][0]->id, Governance::PAY_DATE, $scenario['admin'])
        ->pluck('id')
        ->all())->toContain($ownRule->id);
});

it('shows whether the chosen rule covers the cumulative excess before sending, and registers the covered payment', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $monetary = Governance::rule($scenario, ['name' => 'Tolerância contratual']);
    $percent = Governance::rule($scenario, ['name' => 'Tolerância percentual', 'maximum_difference_amount' => null, 'maximum_difference_percent' => '5.0000']);
    $this->actingAs($scenario['payer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->set('mountedActions.0.data.pay_date', Governance::PAY_DATE);
    $item = governanceUiItemKey($component, $scenario['planSets'][0]->id);
    $component->set("mountedActions.0.data.payments.{$item}.amount", '103.000,00');

    // Sem regra escolhida, não há cobertura a mostrar.
    expect(governanceUiModalComponent($component, 'financial_rule_coverage', $item))->toBeNull();

    $component->set("mountedActions.0.data.payments.{$item}.financial_rule_id", $monetary->id);

    expect(governanceUiModalComponent($component, 'financial_rule_coverage', $item)?->getLabel())->toBe('Cobertura da regra')
        ->and(governanceUiModalText($component, 'financial_rule_coverage', $item))
        ->toBe('Divergência acumulada do empreendimento: R$ 3.000,00; limite em reais R$ 5.000,00. A regra cobre a divergência.');

    $component->set("mountedActions.0.data.payments.{$item}.amount", '106.000,00');

    expect(governanceUiModalText($component, 'financial_rule_coverage', $item))
        ->toBe('Divergência acumulada do empreendimento: R$ 6.000,00; limite em reais R$ 5.000,00. A divergência acumulada do empreendimento chega a R$ 6.000,00, acima do limite em reais da regra (R$ 5.000,00).');

    // O percentual é do valor aprovado (R$ 100.000,00), não do saldo.
    $component
        ->set("mountedActions.0.data.payments.{$item}.financial_rule_id", $percent->id)
        ->set("mountedActions.0.data.payments.{$item}.amount", '104.000,00');

    expect(governanceUiModalText($component, 'financial_rule_coverage', $item))
        ->toBe('Divergência acumulada do empreendimento: R$ 4.000,00; limite de 5% do valor aprovado (R$ 5.000,00). A regra cobre a divergência.');

    $component
        ->set("mountedActions.0.data.payments.{$item}.financial_rule_id", $monetary->id)
        ->set("mountedActions.0.data.payments.{$item}.amount", '103.000,00')
        ->set("mountedActions.0.data.payments.{$item}.financial_justification", Governance::JUSTIFICATION)
        ->set('mountedActions.0.data.receipt', MeasurementReceiptEvidenceScenario::file())
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Pagamento registrado com o comprovante. O comprovante fica pendente de conferência na Finalização, e a etapa Pagamento ainda precisa ser aprovada.');

    $payment = MeasurementPayment::query()->sole();

    expect($payment->only(['plan_set_id', 'amount', 'financial_rule_id', 'created_by']))->toBe([
        'plan_set_id' => $scenario['planSets'][0]->id,
        'amount' => '103000.00',
        'financial_rule_id' => $monetary->id,
        'created_by' => $scenario['payer']->id,
    ])
        ->and($payment->financial_assessment['requires_acceptance'])->toBeTrue()
        ->and($payment->financial_assessment['cumulative_excess_amount'])->toBe('3000.00')
        ->and($payment->financial_assessment['coverage']['covered'])->toBeTrue()
        ->and($payment->financial_assessment['assessed_by_user_id'])->toBe($scenario['payer']->id);
});

it('refuses a forged choice of a rule the payer registered, and registers nothing', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $scenario['payer']->givePermissionTo(['measurements.financial-rules.view', 'measurements.financial-rules.manage']);
    $ownRule = Governance::rule($scenario, ['name' => 'Adiantamento do gestor'], $scenario['payer']);
    Governance::rule($scenario, ['name' => 'Tolerância contratual']);
    $before = governanceUiState($measurement);
    $this->actingAs($scenario['payer']);

    // O navegador escrevendo no estado do modal a regra que o select não
    // oferece: o próprio campo a recusa.
    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->set('mountedActions.0.data.pay_date', Governance::PAY_DATE);
    $item = governanceUiItemKey($component, $scenario['planSets'][0]->id);

    $component
        ->set("mountedActions.0.data.payments.{$item}.amount", '103.000,00')
        ->set("mountedActions.0.data.payments.{$item}.financial_rule_id", $ownRule->id)
        ->set("mountedActions.0.data.payments.{$item}.financial_justification", Governance::JUSTIFICATION)
        ->callMountedAction();

    expect($component->errors()->get("mountedActions.0.data.payments.{$item}.financial_rule_id"))->toHaveCount(1)
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(governanceUiState($measurement))->toBe($before)
        ->and(governanceUiActivity($measurement, 'measurement_payment_registered'))->toBeNull();

    $component->assertActionMounted('registerPayment');

    // O envio que pulasse a tela é recusado pelo domínio, com o motivo.
    $refusal = null;

    try {
        Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '103000.00', [
            'financial_rule_id' => $ownRule->id,
            'financial_justification' => Governance::JUSTIFICATION,
        ])]);
    } catch (ValidationException $exception) {
        $refusal = $exception->errors()['payments.0.financial_rule_id'][0] ?? null;
    }

    expect($refusal)->toBe(MeasurementPaymentFinancialService::RULE_AUTHOR_SELF_USE_REFUSAL.' Você registrou a versão 1 desta regra.')
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(governanceUiState($measurement))->toBe($before);
});

it('tells in the payment modal that a construction without Construction Fund takes no payment, and refuses one', function () {
    // Plano legado, ativado sem Fundo de Obra antes da exigência na ativação.
    $scenario = Governance::plan([['fund' => '1000000.00', 'name' => 'Torre Norte'], ['fund' => null, 'name' => 'Torre Sul']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $before = governanceUiState($measurement);
    $refusal = sprintf(MeasurementPaymentFinancialService::MISSING_FUND_PAYMENT_REFUSAL, 'Torre Sul');
    $this->actingAs($scenario['payer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->set('mountedActions.0.data.pay_date', Governance::PAY_DATE);
    $withFund = governanceUiItemKey($component, $scenario['planSets'][0]->id);
    $withoutFund = governanceUiItemKey($component, $scenario['planSets'][1]->id);

    // Antes de digitar, a pessoa lê que não há base financeira.
    expect(governanceUiModalText($component, 'reconciliation_reference', $withoutFund))->toBe($refusal)
        ->and(governanceUiModalText($component, 'reconciliation_divergence', $withoutFund))->toBe($refusal)
        ->and(governanceUiModalText($component, 'reconciliation_reference', $withFund))->toContain('Valor esperado R$ 100.000,00');

    $component
        ->set("mountedActions.0.data.payments.{$withFund}.amount", '100.000,00')
        ->set("mountedActions.0.data.payments.{$withoutFund}.amount", '50.000,00')
        ->set("mountedActions.0.data.payments.{$withoutFund}.financial_justification", Governance::JUSTIFICATION)
        ->set('mountedActions.0.data.receipt', MeasurementReceiptEvidenceScenario::file())
        ->callMountedAction();

    // A recusa fica no campo do empreendimento sem Fundo, e o registro é de
    // tudo ou nada: nem a Torre Norte é paga.
    expect($component->errors()->get("mountedActions.0.data.payments.{$withoutFund}.amount"))->toBe([$refusal])
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(governanceUiState($measurement))->toBe($before);

    $component->assertActionMounted('registerPayment');
});

// ── Motivo do override administrativo ───────────────────────────────────────

it('does not ask the direct Gestão reviewer for an override reason', function () {
    $scenario = Governance::plan();
    $measurement = Governance::measured($scenario, '2026-05', [10, 10]);
    $this->actingAs($scenario['manager']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionEnabled('approve')
        ->mountAction('approve');

    // O campo existe no schema, oculto: quem responde pela etapa não age por
    // override.
    expect(governanceUiModalComponent($component, 'override_reason'))->toBeNull()
        ->and(governanceUiModalComponent($component, 'override_reason', withHidden: true))->toBeInstanceOf(Textarea::class);

    $component->callMountedAction()->assertHasNoActionErrors();

    expect((int) $measurement->fresh()->current_stage)->toBe(3)
        ->and(governanceUiActivity($measurement, 'measurement_stage_approved', 2)?->causer_id)->toBe($scenario['manager']->id)
        ->and(governanceUiActivity($measurement, 'measurement_stage_approved', 2)?->properties->has('admin_override_reason'))->toBeFalse();
});

it('requires the override reason from the administrator who approves the Gestão stage by override, and records it', function (string $blank) {
    $scenario = Governance::plan();
    $measurement = Governance::measured($scenario, '2026-05', [10, 10]);
    $before = governanceUiState($measurement);
    $reason = 'Gestor da etapa afastado; aprovação pela diretoria, conforme ata de 02/06.';
    $this->actingAs($scenario['admin']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('approve')
        ->mountAction('approve');
    $field = governanceUiModalComponent($component, 'override_reason');

    expect($field)->toBeInstanceOf(Textarea::class)
        ->and($field->getLabel())->toBe('Motivo do override administrativo')
        ->and($field->isRequired())->toBeTrue();

    $component->set('mountedActions.0.data.override_reason', $blank)->callMountedAction();

    // A mensagem tem ':' -- lida do error bag, não pelo assertHasErrors.
    expect($component->errors()->get('mountedActions.0.data.override_reason'))->toBe([MeasurementSegregationService::OVERRIDE_REASON_REQUIRED])
        ->and(governanceUiState($measurement))->toBe($before)
        ->and(governanceUiActivity($measurement, 'measurement_stage_approved', 2))->toBeNull();

    $component->assertActionMounted('approve')
        ->set('mountedActions.0.data.override_reason', $reason)
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Etapa aprovada.');

    $approval = governanceUiActivity($measurement, 'measurement_stage_approved', 2);

    expect((int) $measurement->fresh()->current_stage)->toBe(3)
        ->and($approval?->causer_id)->toBe($scenario['admin']->id)
        ->and($approval?->properties['admin_override_reason'] ?? null)->toBe($reason);
})->with([
    'empty' => [''],
    // Espaço sem quebra e de largura zero passam pelo `required` do campo: o
    // domínio recusa, e o erro aparece no mesmo campo.
    'invisible characters only' => ["\u{200B}\u{00A0} "],
]);

it('requires the override reason in the reassessment by an administrator and shows on the page who reassessed the framing', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario);
    [$excess, $ordinary] = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '103000.00', ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]),
        Governance::row($scenario, 1, '50000.00'),
    ])->all();
    $reason = 'Gestor de Pagamento afastado; reavaliação pedida pela diretoria.';
    $justification = 'Excedente previsto no aditivo de maio, conferido com o contrato da emissão.';

    // Antes da reavaliação, a página diz só quem registrou cada pagamento.
    $this->actingAs($scenario['payer']);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSeeText("Pagamento #{$excess->id}")
        ->assertSeeText('Registrado por '.$scenario['payer']->name)
        ->assertDontSeeText('enquadramento reavaliado por');

    $this->actingAs($scenario['admin']);

    $before = governanceUiState($measurement);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('reassessPayment')
        ->mountAction('reassessPayment')
        ->set('mountedActions.0.data.payment_id', $excess->id);

    expect(governanceUiModalComponent($component, 'financial_rule_id')?->getPlaceholder())->toBe('Manter a regra atual do pagamento')
        ->and(governanceUiModalComponent($component, 'override_reason')?->isRequired())->toBeTrue();

    $component->set('mountedActions.0.data.financial_justification', $justification)->callMountedAction();

    expect($component->errors()->get('mountedActions.0.data.override_reason'))->toBe([MeasurementSegregationService::OVERRIDE_REASON_REQUIRED])
        ->and($excess->fresh()->financial_assessment['assessed_by_user_id'])->toBe($scenario['payer']->id)
        ->and($excess->fresh()->financial_assessment['justification'])->toBe(Governance::JUSTIFICATION)
        ->and(governanceUiState($measurement))->toBe($before)
        ->and(governanceUiActivity($measurement, 'measurement_payment_reassessed'))->toBeNull();

    $component->set('mountedActions.0.data.override_reason', $reason)
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Enquadramento financeiro atualizado para conferência do Finalizador.');

    // A regra em branco mantém a atual; quem reavaliou passa a responder pelo
    // enquadramento, com o motivo do override na trilha.
    expect($excess->fresh()->financial_rule_id)->toBe($rule->id)
        ->and($excess->fresh()->financial_assessment['assessed_by_user_id'])->toBe($scenario['admin']->id)
        ->and($excess->fresh()->financial_assessment['justification'])->toBe($justification)
        ->and(governanceUiActivity($measurement, 'measurement_payment_reassessed')?->properties['admin_override_reason'] ?? null)->toBe($reason);

    Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertSeeText('Registrado por '.$scenario['payer']->name.' · enquadramento reavaliado por '.$scenario['admin']->name)
        ->assertSeeText("Pagamento #{$ordinary->id}");
});

it('asks only the administrator acting by override for the required override reason, in each workflow action', function (string $action) {
    $scenario = Governance::plan();
    [$measurement, $direct, $override] = governanceUiAtAction($scenario, $action);

    // Quem responde pela ação não age por override: o campo existe, oculto.
    $this->actingAs($direct);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible($action)
        ->assertActionEnabled($action)
        ->mountAction($action)
        ->assertActionMounted($action);

    expect(governanceUiModalComponent($component, 'override_reason'))->toBeNull()
        ->and(governanceUiModalComponent($component, 'override_reason', withHidden: true))->toBeInstanceOf(Textarea::class);

    $this->actingAs($override);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible($action)
        ->assertActionEnabled($action)
        ->mountAction($action)
        ->assertActionMounted($action);
    $field = governanceUiModalComponent($component, 'override_reason');

    expect($field)->toBeInstanceOf(Textarea::class)
        ->and($field->getLabel())->toBe('Motivo do override administrativo')
        ->and($field->isRequired())->toBeTrue();
})->with([
    'payment registration' => ['registerPayment'],
    'conclusion without disbursement' => ['concludeWithoutDisbursement'],
    'resumption of a paused stage' => ['resume'],
    'receipt upload' => ['attachReceipt'],
    'receipt review' => ['reviewReceipt'],
    'finalization' => ['finalize'],
    'revision submission' => ['submitRevision'],
]);

// ── Finalização: aceite da exceção financeira ───────────────────────────────

it('disables the Finalization for the payer who also finalizes, with the reason, and refuses the forged call', function () {
    ['scenario' => $scenario, 'measurement' => $measurement, 'excess' => $excess] = governanceUiExcessReadyToFinalize();
    // A mesma pessoa passa a responder também pela Finalização. Antes do
    // actingAs: com alguém autenticado, trocar um responsável da operação
    // passa pela conferência de quem pode trocá-lo.
    $scenario['payer']->givePermissionTo('measurements.finalize');
    $scenario['operation']->forceFill(['payment_finalizer_user_id' => $scenario['payer']->id])->save();
    $before = governanceUiState($measurement);
    $this->actingAs($scenario['payer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('finalize')
        ->assertActionDisabled('finalize');

    expect(governanceUiTooltip($component, 'finalize'))
        ->toStartWith(MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE)
        ->toContain("Você registrou o pagamento #{$excess->id}")
        ->toContain('aprovou a etapa Pagamento com a exceção');

    // O Filament não monta a ação desabilitada.
    $component->call('mountAction', 'finalize')->assertActionNotMounted();

    // O envio forjado, com a ação já montada no estado do componente, também
    // não passa: a página recusa a ação desabilitada antes do domínio.
    $component->set('mountedActions', [[
        'name' => 'finalize',
        'arguments' => [],
        'context' => [],
        'data' => [
            'expected_revision' => $before['workflow_revision'],
            'expected_status' => $before['status'],
            'accept_financial_exceptions' => true,
        ],
    ]])->call('callMountedAction')->assertActionNotMounted();

    $refusal = governanceUiNotification('Ação não concluída.');

    expect($refusal['body'] ?? null)->toBe('A medição mudou depois que esta janela foi aberta e esta ação não está mais disponível para você. Atualize a página.')
        ->and($refusal['status'] ?? null)->toBe('danger')
        ->and($refusal['duration'] ?? null)->toBe('persistent')
        ->and(governanceUiState($measurement))->toBe($before)
        ->and(governanceUiActivity($measurement, 'measurement_finalized'))->toBeNull();

    // A chamada direta ao domínio, que pularia a tela, recusa pelo mesmo motivo.
    expect(fn () => app(MeasurementWorkflow::class)->finalize($measurement->fresh(), $scenario['payer'], acceptFinancialExceptions: true))
        ->toThrow(MeasurementSegregationException::class, MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE)
        ->and(governanceUiState($measurement))->toBe($before);
});

// O enquadramento do registro é de quem registrou: ninguém lê que reavaliou o
// próprio pagamento.
it('does not say that the payer reassessed a payment nobody reassessed', function () {
    ['scenario' => $scenario, 'measurement' => $measurement, 'excess' => $excess] = governanceUiExcessReadyToFinalize();

    // Ninguém reavaliou o pagamento: o enquadramento é o do registro. O
    // motivo é o do tooltip que o Gestor de Pagamento leria como Finalizador.
    expect(governanceUiActivity($measurement, 'measurement_payment_reassessed'))->toBeNull()
        ->and(app(MeasurementWorkflow::class)->finalizationBlockReason($measurement->fresh(), $scenario['payer']))
        ->toStartWith(MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE)
        ->toContain("Você registrou o pagamento #{$excess->id}")
        ->not->toContain('reavaliou o enquadramento');

    $this->actingAs($scenario['finalizer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('finalize');

    expect(governanceUiModalText($component, 'segregation_notice'))
        ->toContain($scenario['payer']->name." registrou o pagamento #{$excess->id}")
        ->not->toContain('reavaliou o enquadramento');
});

// Fase 4B-2: o pagamento parcial continua legítimo, mas o aceite do saldo que
// ele deixa em aberto pede um Finalizador independente de quem pagou.
it('disables the Finalization for the payer who also finalizes when the only divergence is a partial payment, and finalizes with the independent finalizer', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $partial = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '90000.00', ['financial_justification' => 'Retenção contratual de 10% até a vistoria.']),
        Governance::row($scenario, 1, '50000.00'),
    ])->first();
    Governance::readyToFinalize($scenario, $measurement);
    $independentFinalizerId = $scenario['operation']->payment_finalizer_user_id;
    $scenario['payer']->givePermissionTo('measurements.finalize');
    $scenario['operation']->forceFill(['payment_finalizer_user_id' => $scenario['payer']->id])->save();
    $before = governanceUiState($measurement);
    $this->actingAs($scenario['payer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('finalize')
        ->assertActionDisabled('finalize');

    expect(governanceUiTooltip($component, 'finalize'))
        ->toStartWith(MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE)
        ->toContain("Você registrou o pagamento #{$partial->id}");

    // A chamada direta ao domínio, que pularia a tela, recusa pelo mesmo motivo.
    expect(fn () => app(MeasurementWorkflow::class)->finalize($measurement->fresh(), $scenario['payer'], acceptFinancialExceptions: true))
        ->toThrow(MeasurementSegregationException::class, MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE)
        ->and(governanceUiState($measurement))->toBe($before);

    // O Finalizador independente aceita o saldo em aberto. A troca é direta
    // no banco: quem está logado não altera os responsáveis da operação.
    DB::table('operations')->where('id', $scenario['operation']->id)->update(['payment_finalizer_user_id' => $independentFinalizerId]);
    $this->actingAs($scenario['finalizer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('finalize')
        ->assertActionEnabled('finalize');

    expect(governanceUiTooltip($component, 'finalize'))->toBeNull();

    $component->mountAction('finalize');

    expect(governanceUiModalText($component, 'segregation_notice'))
        ->toContain($scenario['payer']->name." registrou o pagamento #{$partial->id}")
        ->and(governanceUiModalComponent($component, 'accept_financial_exceptions'))->toBeInstanceOf(Checkbox::class);

    $component->set('mountedActions.0.data.accept_financial_exceptions', true)
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $segregation = governanceUiActivity($measurement, 'measurement_finalized')?->properties['segregation']['financial_exceptions'] ?? [];

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and($segregation['independent'] ?? null)->toBeTrue()
        ->and($segregation['checker_user_id'] ?? null)->toBe($scenario['finalizer']->id)
        ->and($segregation['required_because'] ?? null)->toBe(['partial_payment_outstanding_balance'])
        ->and(collect($segregation['makers'] ?? [])->pluck('user_id')->all())->toContain($scenario['payer']->id);
});

it('shows the independent finalizer who made the exception it accepts, and finalizes with the express acceptance', function () {
    ['scenario' => $scenario, 'measurement' => $measurement, 'excess' => $excess] = governanceUiExcessReadyToFinalize();
    $this->actingAs($scenario['finalizer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('finalize')
        ->assertActionEnabled('finalize');

    expect(governanceUiTooltip($component, 'finalize'))->toBeNull();

    $component->mountAction('finalize')->assertActionMounted('finalize');
    $notice = governanceUiModalText($component, 'segregation_notice');

    expect(governanceUiModalComponent($component, 'segregation_notice')?->getLabel())->toBe('Conferência independente')
        ->and($notice)->toStartWith('O aceite e a ciência desta Finalização são de um responsável diferente de quem fez o que eles conferem:')
        ->toContain($scenario['payer']->name." registrou o pagamento #{$excess->id}")
        ->toContain($scenario['ruleAuthor']->name." registrou a regra financeira aplicada ao pagamento #{$excess->id}")
        ->not->toContain($scenario['finalizer']->name)
        // A conferência financeira diz quem registrou o pagamento excepcional.
        ->and(governanceUiModalText($component, 'financial_review'))
        ->toContain("Pagamento #{$excess->id} · R$ 103.000,00")
        ->toContain('Registrado por '.$scenario['payer']->name)
        ->toContain('Cobertura da regra Divergência acumulada do empreendimento R$ 3.000,00 · teto da regra R$ 5.000,00')
        ->not->toContain('enquadramento reavaliado por');

    $component->set('mountedActions.0.data.accept_financial_exceptions', true)
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Medição finalizada.');

    $segregation = governanceUiActivity($measurement, 'measurement_finalized')?->properties['segregation']['financial_exceptions'] ?? [];

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and($segregation['independent'] ?? null)->toBeTrue()
        ->and($segregation['checker_user_id'] ?? null)->toBe($scenario['finalizer']->id)
        ->and($segregation['required_because'] ?? null)->toBe(['excess_payment'])
        ->and(collect($segregation['makers'] ?? [])->pluck('user_id')->unique()->sort()->values()->all())
        ->toBe(collect([$scenario['payer']->id, $scenario['ruleAuthor']->id])->sort()->values()->all());
});

// ── Conferência do comprovante ──────────────────────────────────────────────

it('disables the approval of a receipt for the finalizer who uploaded it, and still lets them reject it', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $payments = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '100000.00'),
        Governance::row($scenario, 1, '50000.00'),
    ]);
    Governance::approvePaymentStage($scenario, $measurement);
    // Fase 4B-2: o comprovante entra com o pagamento, enviado por quem o
    // registrou -- e quem paga passa a responder também pela Finalização.
    $scenario['payer']->givePermissionTo('measurements.finalize');
    $scenario['operation']->forceFill(['payment_finalizer_user_id' => $scenario['payer']->id])->save();
    $evidence = $payments->first()->fresh()->currentReceiptEvidence;
    $conflict = MeasurementSegregationService::RECEIPT_SELF_APPROVAL.' Você enviou a versão v1 do comprovante.';
    $before = governanceUiState($measurement);
    $this->actingAs($scenario['payer']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('reviewReceipt')
        ->mountAction('reviewReceipt');

    expect(governanceUiModalComponent($component, 'evidence_id')?->getOptions()[$evidence->id] ?? null)->toEndWith(' · enviado por você');

    $component->set('mountedActions.0.data.evidence_id', $evidence->id);
    $decision = governanceUiModalComponent($component, 'decision');

    expect($decision)->toBeInstanceOf(Select::class)
        ->and($decision->isOptionDisabled('approved', 'Aprovar comprovante'))->toBeTrue()
        ->and($decision->isOptionDisabled('rejected', 'Rejeitar comprovante'))->toBeFalse()
        ->and(governanceUiHelperText($decision))->toBe($conflict);

    // A opção desabilitada, escolhida mesmo assim, é recusada pelo próprio
    // campo, com o motivo.
    $component
        ->set('mountedActions.0.data.decision', 'approved')
        ->set('mountedActions.0.data.confirmed', true)
        ->callMountedAction();

    expect($component->errors()->get('mountedActions.0.data.decision'))->toBe([$conflict])
        ->and($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($evidence->fresh()->reviewer_user_id)->toBeNull()
        ->and(governanceUiState($measurement))->toBe($before)
        ->and(Activity::query()->where('description', 'receipt_evidence_approved')->exists())->toBeFalse();

    // Rejeitar o próprio envio continua possível.
    $component->assertActionMounted('reviewReceipt')
        ->set('mountedActions.0.data.decision', 'rejected')
        ->set('mountedActions.0.data.rejection_reason', 'Comprovante enviado com a página errada.')
        ->set('mountedActions.0.data.confirmed', true)
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Decisão documental registrada para esta versão.');

    expect($evidence->fresh()->uploaded_by)->toBe($scenario['payer']->id)
        ->and($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Rejected)
        ->and($evidence->fresh()->reviewer_user_id)->toBe($scenario['payer']->id);

    // A aprovação que pulasse a tela é recusada pelo domínio.
    $other = $payments->last()->fresh()->currentReceiptEvidence;

    expect($other)->toBeInstanceOf(MeasurementPaymentReceiptEvidence::class)
        ->and(fn () => app(MeasurementReceiptEvidenceService::class)->review($other, $scenario['payer'], MeasurementReceiptReviewStatus::Approved, true))
        ->toThrow(MeasurementSegregationException::class, MeasurementSegregationService::RECEIPT_SELF_APPROVAL)
        ->and($other->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending);
});

// ── Revisão: Compliance independente ────────────────────────────────────────

it('disables the Compliance approval of a revision for the reviewer who created and submitted it, keeping the rejection available', function () {
    $scenario = Governance::plan();
    $may = Governance::finalized($scenario, '2026-05', [10, 10]);
    // O responsável de Compliance da operação também revisa medições: ele
    // cria e envia a R1 -- o administrador continua independente.
    $scenario['compliance']->givePermissionTo('measurements.revise');
    $revision = Governance::revisionAtCompliance($scenario, $may, [12, 10], $scenario['compliance']);
    $before = governanceUiState($revision);
    $this->actingAs($scenario['compliance']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionDisabled('approve')
        ->assertActionVisible('reject')
        ->assertActionEnabled('reject');

    expect(governanceUiTooltip($component, 'approve'))
        ->toBe(MeasurementSegregationService::REVISION_SELF_APPROVAL.' Você criou a revisão e enviou a revisão.')
        ->and(governanceUiTooltip($component, 'reject'))->toBeNull();

    $component->set('mountedActions', [[
        'name' => 'approve',
        'arguments' => [],
        'context' => [],
        'data' => ['expected_stage' => 3, 'expected_revision' => $before['workflow_revision']],
    ]])->call('callMountedAction')->assertActionNotMounted();

    expect(governanceUiNotification('Ação não concluída.')['body'] ?? null)
        ->toBe('A medição mudou depois que esta janela foi aberta e esta ação não está mais disponível para você. Atualize a página.')
        ->and(governanceUiState($revision))->toBe($before)
        ->and($may->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::Effective)
        ->and(fn () => app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['compliance']))
        ->toThrow(MeasurementSegregationException::class, MeasurementSegregationService::REVISION_SELF_APPROVAL)
        ->and(governanceUiState($revision))->toBe($before);

    // Recusar na Compliance continua com quem fez a revisão: ela volta à Gestão.
    Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->callAction('reject', data: ['notes' => 'Percentual da Torre Norte sem lastro no laudo de maio.'])
        ->assertHasNoActionErrors();

    expect((int) $revision->fresh()->current_stage)->toBe(2)
        ->and($revision->fresh()->status)->toBe('in_review')
        ->and($revision->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview);
});

it('disables the submission of a revision while no Compliance approver independent of its makers exists', function () {
    $scenario = Governance::plan();
    $may = Governance::finalized($scenario, '2026-05', [10, 10]);
    $scenario['compliance']->givePermissionTo('measurements.revise');
    $draft = Governance::revise($scenario, $may, $scenario['compliance']);
    // Os administradores estão inativos: ninguém além de quem fez a revisão a
    // aprovaria na Compliance.
    $scenario['admin']->forceFill(['is_active' => false])->save();
    $scenario['superAdmin']->forceFill(['is_active' => false])->save();
    $before = governanceUiState($draft);
    $this->actingAs($scenario['compliance']);

    $component = Livewire::test(ViewMeasurement::class, ['record' => $draft->getRouteKey()])
        ->assertActionVisible('submitRevision')
        ->assertActionDisabled('submitRevision');

    expect(governanceUiTooltip($component, 'submitRevision'))->toBe(MeasurementSegregationService::NO_INDEPENDENT_COMPLIANCE_APPROVER);

    $component->set('mountedActions', [[
        'name' => 'submitRevision',
        'arguments' => [],
        'context' => [],
        'data' => ['expected_revision' => $before['workflow_revision']],
    ]])->call('callMountedAction')->assertActionNotMounted();

    expect(governanceUiNotification('Ação não concluída.')['body'] ?? null)
        ->toBe('A medição mudou depois que esta janela foi aberta e esta ação não está mais disponível para você. Atualize a página.')
        ->and(governanceUiState($draft))->toBe($before)
        ->and(fn () => app(MeasurementRevisionService::class)->submit($draft->fresh(), $scenario['compliance']))
        ->toThrow(MeasurementSegregationException::class, MeasurementSegregationService::NO_INDEPENDENT_COMPLIANCE_APPROVER)
        ->and(governanceUiState($draft))->toBe($before);

    // Com um administrador ativo, a revisão tem quem a aprove: o envio volta.
    $scenario['admin']->forceFill(['is_active' => true])->save();

    $page = Livewire::test(ViewMeasurement::class, ['record' => $draft->getRouteKey()])
        ->assertActionEnabled('submitRevision');

    expect(governanceUiTooltip($page, 'submitRevision'))->toBeNull();

    $page->callAction('submitRevision', data: ['expected_revision' => $before['workflow_revision']])
        ->assertHasNoActionErrors()
        ->assertNotified('Revisão enviada para a Engenharia.');

    expect($draft->fresh()->revisionStatus())->toBe(MeasurementRevisionStatus::UnderReview);
});

// Na revisão, o que a família já pagou limita o pagamento antes da regra: a
// prévia mostra a recusa da revisão, e não uma cobertura que o domínio
// recusaria.
it('shows the revision refusal instead of the rule coverage when no rule can pay above the open balance of a revision', function () {
    $scenario = Governance::plan();
    // A R0 pagou R$ 100.000,00 na Torre Norte; a R1 aprova 12% (R$ 120.000,00):
    // saldo em aberto de R$ 20.000,00.
    $source = Governance::finalized($scenario, '2026-05', [10, 10]);
    $revision = Governance::revisionAtCompliance($scenario, $source, [12, 10]);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['compliance']);
    $rule = Governance::rule($scenario, ['name' => 'Tolerância contratual']);
    $this->actingAs($scenario['payer']);

    expect($revision->fresh()->status)->toBe('awaiting_payment');

    $component = Livewire::test(ViewMeasurement::class, ['record' => $revision->getRouteKey()])
        ->mountAction('registerPayment')
        ->set('mountedActions.0.data.pay_date', Governance::PAY_DATE);
    $item = governanceUiItemKey($component, $scenario['planSets'][0]->id);
    $component
        ->set("mountedActions.0.data.payments.{$item}.amount", '25.000,00')
        ->set("mountedActions.0.data.payments.{$item}.financial_rule_id", $rule->id);

    expect(governanceUiModalText($component, 'financial_rule_coverage', $item))
        ->toContain('passa do saldo em aberto da revisão (R$ 20.000,00)')
        ->toContain('nem com regra financeira')
        ->not->toContain('A regra cobre a divergência')
        ->and(governanceUiHelperText(governanceUiModalComponent($component, 'financial_rule_id', $item)))
        ->toEndWith('Na revisão, o empreendimento já pago antes dela não recebe acima do saldo em aberto, nem com regra.');

    // Dentro do saldo em aberto, a prévia volta a ser a da regra.
    $component->set("mountedActions.0.data.payments.{$item}.amount", '20.000,00');

    expect(governanceUiModalText($component, 'financial_rule_coverage', $item))
        ->toBe('Divergência acumulada do empreendimento: R$ 0,00; limite em reais R$ 5.000,00. O pagamento não diverge do saldo a pagar: não há divergência a enquadrar numa regra.');
});

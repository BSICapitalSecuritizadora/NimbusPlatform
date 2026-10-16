<?php

use App\Enums\MeasurementPlanRevisionCategory;
use App\Enums\MeasurementPlanVersionStatus;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementPlanVersion;
use App\Models\User;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementPlanVersionService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Placeholder;
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
use Tests\Support\MeasurementPlanVersionFixture;

/**
 * O Fundo de Obra exigido onde ele importa (Fase 4B-1): a versão do plano --
 * V1 ou revisão -- só entra em vigor com Fundo informado (zero é Fundo
 * válido), e nenhum pagamento é registrado no empreendimento sem Fundo na
 * versão congelada pela Engenharia. Zero e ausente nunca se confundem: zero
 * é "nada devido, demonstrado"; ausente é "valor devido indeterminado". O
 * legado sem Fundo continua andando até a etapa Pagamento.
 */
pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
    // A vigência da versão é o mês do calendário de negócio: fuso fixo.
    config()->set('measurements.business_timezone', 'America/Sao_Paulo');
});

/**
 * A operação da governança sem plano nenhum, com a coordenação podendo
 * replanejá-la: é quem cadastra e ativa o plano da obra.
 *
 * @return array<string, mixed>
 */
function fundRequirementPlanning(): array
{
    $scenario = Governance::plan([]);
    $scenario['coordinator']->givePermissionTo(['operations.view', 'operations.update']);

    return $scenario;
}

/**
 * Plano novo cadastrado pela coordenação pela porta de escrita: V1 em
 * rascunho, com o Fundo de Obra informado (ou nenhum) e uma medição prevista
 * de 5% por mês de 2027.
 *
 * @param  array<string, mixed>  $scenario
 */
function fundRequirementDraftPlan(array $scenario, ?string $fund): MeasurementPlanSet
{
    $lines = [];

    foreach (range(1, 12) as $sequence) {
        $lines[] = [
            'sequence_number' => $sequence,
            'planned_monthly_percent' => '5.00',
            'planned_cumulative_percent' => number_format(5 * $sequence, 2, '.', ''),
            'measurement_date' => CarbonImmutable::parse('2027-01-01')->addMonthsNoOverflow($sequence - 1)->format('Y-m'),
        ];
    }

    return app(MeasurementPlanVersionService::class)->createPlan($scenario['operation'], $scenario['coordinator'], [
        'name' => 'Torre Leste',
        'is_default' => true,
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => '0.00',
        'initial_physical_progress_reference_date' => null,
    ], ['construction_fund_amount' => $fund], $lines);
}

function fundRequirementVersion(MeasurementPlanSet $planSet, int $number): MeasurementPlanVersion
{
    return MeasurementPlanVersion::query()
        ->where('plan_set_id', $planSet->id)
        ->where('version_number', $number)
        ->sole();
}

/**
 * Ativa como quem acabou de abrir a tela: com o contador atual do rascunho.
 */
function fundRequirementActivate(MeasurementPlanVersion $version, User $actor): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->activate($version, $actor, (int) $version->fresh()->revision);
}

/**
 * Revisão de custo do plano: rascunho copiado da versão vigente, Fundo
 * inclusive.
 */
function fundRequirementRevision(MeasurementPlanSet $planSet, User $actor): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->createRevision($planSet, $actor, [
        'revision_category' => MeasurementPlanRevisionCategory::Cost->value,
        'revision_reason' => 'Orçamento da obra revisto com a construtora.',
    ]);
}

/**
 * Grava o Fundo de Obra no rascunho, com o contador atual dele.
 */
function fundRequirementSetDraftFund(MeasurementPlanVersion $draft, User $actor, ?string $fund): MeasurementPlanVersion
{
    return app(MeasurementPlanVersionService::class)->updateDraft(
        $draft,
        $actor,
        ['construction_fund_amount' => $fund],
        null,
        (int) $draft->fresh()->revision,
    );
}

/**
 * Versões e linhas do plano, coluna a coluna, e o tamanho da trilha: a
 * ativação recusada precisa deixar tudo exatamente assim.
 *
 * @return array{versions: list<array<string, mixed>>, lines: list<array<string, mixed>>, activities: int}
 */
function fundRequirementPlanFootprint(MeasurementPlanSet $planSet): array
{
    return [
        'versions' => DB::table('measurement_plan_versions')->where('plan_set_id', $planSet->id)->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)->all(),
        'lines' => DB::table('measurement_plan_lines')->where('plan_set_id', $planSet->id)->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)->all(),
        'activities' => DB::table('activity_log')->count(),
    ];
}

/**
 * As mensagens da recusa de validação, lidas da exceção: elas têm
 * dois-pontos.
 *
 * @return array<string, list<string>>
 */
function fundRequirementErrors(callable $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('A ação deveria ter sido recusada.');
}

/**
 * O que uma recusa não pode ter mudado: o fluxo da medição e os pagamentos.
 *
 * @return array{status: string, current_stage: int, workflow_revision: int, payment_review: string|null, payments: list<array<string, mixed>>}
 */
function fundRequirementState(Measurement $measurement): array
{
    $fresh = $measurement->fresh();

    return [
        'status' => (string) $fresh->status,
        'current_stage' => (int) $fresh->current_stage,
        'workflow_revision' => (int) $fresh->workflow_revision,
        'payment_review' => $fresh->reviews()->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->value('status'),
        'payments' => MeasurementPayment::query()->orderBy('id')->get(['id', 'measurement_id', 'plan_set_id', 'amount'])->toArray(),
    ];
}

/**
 * Chave real (UUID) do item do Repeater de pagamentos que corresponde ao
 * empreendimento.
 */
function fundRequirementItemKey(Testable $component, int $planSetId): string
{
    return (string) collect($component->get('mountedActions.0.data.payments') ?? [])
        ->search(fn (array $item): bool => (int) ($item['plan_set_id'] ?? 0) === $planSetId);
}

/**
 * Texto de um Placeholder do item do Repeater no modal montado. O modal vai
 * na resposta como JSON escapado: o conteúdo é lido pelo schema montado.
 */
function fundRequirementModalText(Testable $component, string $name, string $itemKey): string
{
    $page = $component->instance();
    /** @var Schema $schema */
    $schema = $page->{$page->getMountedActionSchemaName()};

    foreach ($schema->getFlatComponents() as $key => $item) {
        if (str_ends_with((string) $key, ".{$itemKey}.{$name}") && $item instanceof Placeholder) {
            $text = strip_tags((string) preg_replace('/</', ' <', (string) $item->getContent()));

            return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
        }
    }

    return '';
}

// ── Ativação da versão do plano ──────────────────────────────────────────────

it('refuses to activate a V1 drafted without Construction Fund, and changes nothing', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC'));
    $scenario = fundRequirementPlanning();
    $planSet = fundRequirementDraftPlan($scenario, null);
    $draft = fundRequirementVersion($planSet, 1);
    $before = fundRequirementPlanFootprint($planSet);

    $errors = fundRequirementErrors(fn () => fundRequirementActivate($draft, $scenario['coordinator']));

    // A versão ativa não muda mais: sem Fundo, as medições dela chegariam ao
    // pagamento sem valor aprovado.
    expect($draft->construction_fund_amount)->toBeNull()
        ->and($errors)->toBe(['construction_fund_amount' => ['Informe o Fundo de Obra da versão antes de ativá-la (zero é aceito): ele é a base financeira das medições deste plano, e a versão ativa não muda mais.']])
        ->and($errors['construction_fund_amount'][0])->toStartWith('Informe o Fundo de Obra da versão antes de ativá-la')
        ->and(fundRequirementPlanFootprint($planSet))->toBe($before)
        ->and($draft->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft)
        ->and($planSet->fresh()->currentConstructionFundAmount())->toBeNull();
});

it('activates a V1 whose Construction Fund is zero or positive', function (string $fund) {
    $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC'));
    $scenario = fundRequirementPlanning();
    $planSet = fundRequirementDraftPlan($scenario, $fund);

    $v1 = fundRequirementActivate(fundRequirementVersion($planSet, 1), $scenario['coordinator']);
    $activation = Activity::query()
        ->where('log_name', 'measurements')
        ->where('event', 'plan_version_activated')
        ->where('subject_type', MeasurementPlanVersion::class)
        ->where('subject_id', $v1->id)
        ->sole();

    expect($v1->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v1->construction_fund_amount)->toBe($fund)
        ->and($v1->effective_from->toDateString())->toBe('2027-01-01')
        ->and((int) $v1->activated_by)->toBe($scenario['coordinator']->id)
        ->and($planSet->fresh()->currentConstructionFundAmount())->toBe($fund)
        ->and((int) $activation->causer_id)->toBe($scenario['coordinator']->id)
        ->and($activation->properties['construction_fund_amount'])->toBe($fund);
})->with([
    'zero, a valid fund' => ['0.00'],
    'positive' => ['1000000.00'],
]);

it('refuses a revision that leaves blank the Construction Fund the active version had', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC'));
    $scenario = fundRequirementPlanning();
    $planSet = fundRequirementDraftPlan($scenario, '1000000.00');
    $v1 = fundRequirementActivate(fundRequirementVersion($planSet, 1), $scenario['coordinator']);

    $this->travelTo(CarbonImmutable::parse('2027-07-05 12:00:00', 'UTC'));
    $draft = fundRequirementSetDraftFund(fundRequirementRevision($planSet, $scenario['coordinator']), $scenario['coordinator'], null);
    $before = fundRequirementPlanFootprint($planSet);

    expect($draft->construction_fund_amount)->toBeNull()
        ->and(fundRequirementErrors(fn () => fundRequirementActivate($draft, $scenario['coordinator'])))
        ->toBe(['construction_fund_amount' => ['Informe o Fundo de Obra da revisão: a V1 vigente tem R$ 1.000.000,00, e a revisão não pode deixar o custo previsto em branco.']])
        ->and(fundRequirementPlanFootprint($planSet))->toBe($before)
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($draft->fresh()->status)->toBe(MeasurementPlanVersionStatus::Draft);
});

it('refuses a revision without Construction Fund of a legacy V1 that never had one, and activates it once the fund is informed', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-10 12:00:00', 'UTC'));
    $scenario = fundRequirementPlanning();
    $planSet = fundRequirementDraftPlan($scenario, null);
    // Estado legado: a V1 entrou em vigor sem Fundo antes da exigência (a
    // fixture ativa sem as conferências da ativação).
    MeasurementPlanVersionFixture::activate($planSet);
    $v1 = fundRequirementVersion($planSet, 1);

    $this->travelTo(CarbonImmutable::parse('2027-07-05 12:00:00', 'UTC'));
    $draft = fundRequirementRevision($planSet, $scenario['coordinator']);
    $before = fundRequirementPlanFootprint($planSet);

    // Antes a revisão do plano que nunca teve Fundo passava sem ele; agora a
    // exigência vale para toda versão que entra em vigor.
    expect($v1->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v1->construction_fund_amount)->toBeNull()
        ->and($draft->construction_fund_amount)->toBeNull()
        ->and(fundRequirementErrors(fn () => fundRequirementActivate($draft, $scenario['coordinator'])))
        ->toBe(['construction_fund_amount' => ['Informe o Fundo de Obra da versão antes de ativá-la (zero é aceito): ele é a base financeira das medições deste plano, e a versão ativa não muda mais.']])
        ->and(fundRequirementPlanFootprint($planSet))->toBe($before)
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Active);

    // A saída do legado é a própria revisão, com o Fundo informado.
    $v2 = fundRequirementActivate(fundRequirementSetDraftFund($draft, $scenario['coordinator'], '750000.00'), $scenario['coordinator']);

    expect($v2->status)->toBe(MeasurementPlanVersionStatus::Active)
        ->and($v2->construction_fund_amount)->toBe('750000.00')
        ->and($v2->effective_from->toDateString())->toBe('2027-07-01')
        ->and($v1->fresh()->status)->toBe(MeasurementPlanVersionStatus::Superseded)
        ->and($v1->fresh()->construction_fund_amount)->toBeNull();
});

// ── Zero não é ausência ──────────────────────────────────────────────────────

it('reads a zero Construction Fund as a valid fund: nothing is due, and the Payment stage concludes without disbursement', function () {
    $scenario = Governance::plan([['fund' => '0.00', 'name' => 'Torre Norte']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);
    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement);

    // Valor aprovado zero, demonstrado -- não indeterminado.
    expect($amountDue->isDetermined())->toBeTrue()
        ->and($amountDue->isNothingDue())->toBeTrue()
        ->and($amountDue->undetermined)->toBe([])
        ->and($amountDue->lines)->toHaveCount(1)
        ->and($amountDue->lines[0]->fundAmount)->toBe('0.00')
        ->and($amountDue->lines[0]->approvedAmount)->toBe('0.00')
        ->and($amountDue->lines[0]->remainingAmount)->toBe('0.00')
        ->and($amountDue->lines[0]->zeroReason())->toBe('zero_fund')
        ->and($workflow->canConcludeWithoutDisbursement($measurement, $scenario['payer']))->toBeTrue();

    // O Gestor de Pagamento decide; o Finalizador, outra pessoa, dá a ciência.
    $workflow->approve(
        $measurement->fresh(),
        $scenario['payer'],
        'Fundo de Obra zero: nada a desembolsar nesta competência.',
        expectedStage: MeasurementWorkflow::STAGE_PAYMENT,
        withoutDisbursement: true,
    );
    $finalized = Governance::finalize($scenario, $measurement, acknowledgeNoDisbursement: true);

    expect($finalized->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

it('refuses, even justified, a positive payment without financial rule on a zero Construction Fund, and registers nothing', function () {
    $scenario = Governance::plan([['fund' => '0.00', 'name' => 'Torre Norte']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10]);
    $before = fundRequirementState($measurement);

    $errors = fundRequirementErrors(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '1000.00', ['financial_justification' => Governance::JUSTIFICATION]),
    ]));

    expect($errors)->toBe(['payments.0.amount' => ['O pagamento excede o saldo autorizado e não possui regra financeira válida para a diferença. Torre Norte: valor aprovado R$ 0,00, já registrado R$ 0,00, saldo a pagar R$ 0,00; com este pagamento, o excedente acumulado chega a R$ 1.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta. Sem valor devido em nenhum empreendimento, conclua a etapa Pagamento sem desembolso, com a justificativa obrigatória.']])
        ->and($errors['payments.0.amount'][0])->toStartWith(MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL)
        ->and(fundRequirementState($measurement))->toBe($before)
        // A conclusão sem desembolso continua disponível: nada é devido.
        ->and(app(MeasurementWorkflow::class)->canConcludeWithoutDisbursement($measurement->fresh(), $scenario['payer']))->toBeTrue();
});

it('registers a positive payment on a zero Construction Fund only under a monetary rule registered by someone else', function () {
    $scenario = Governance::plan([['fund' => '0.00', 'name' => 'Torre Norte']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10]);
    // Regras de quem as cadastra: até R$ 5.000,00 para mais; e só 10% do
    // valor aprovado, que sobre aprovado zero não autoriza nada.
    $monetary = Governance::rule($scenario);
    $percentOnly = Governance::rule($scenario, [
        'name' => 'Tolerância percentual',
        'maximum_difference_amount' => null,
        'maximum_difference_percent' => '10.00',
    ]);
    // O Gestor de Pagamento que também cadastra regras não usa a dele.
    $scenario['payer']->givePermissionTo(['measurements.financial-rules.view', 'measurements.financial-rules.manage']);
    $ownRule = Governance::rule($scenario, ['name' => 'Regra do próprio pagador'], $scenario['payer']);
    $before = fundRequirementState($measurement);
    $pay = fn (int $ruleId) => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '1000.00', ['financial_rule_id' => $ruleId, 'financial_justification' => Governance::JUSTIFICATION]),
    ]);

    expect(fundRequirementErrors(fn () => $pay($percentOnly->id)))
        ->toBe(['payments.0.financial_rule_id' => ['A regra escolhida não cobre este pagamento. O valor aprovado do empreendimento é R$ 0,00: o limite percentual não autoriza divergência sem valor aprovado positivo, e a exceção sobre valor aprovado zero exige uma regra só com limite em reais, sem limite percentual.']])
        ->and(fundRequirementErrors(fn () => $pay($ownRule->id)))
        ->toBe(['payments.0.financial_rule_id' => [MeasurementPaymentFinancialService::RULE_AUTHOR_SELF_USE_REFUSAL.' Você registrou a versão 1 desta regra.']])
        ->and(fundRequirementState($measurement))->toBe($before);

    $payment = $pay($monetary->id)->sole();

    expect((int) $monetary->created_by)->toBe($scenario['ruleAuthor']->id)
        ->and((int) $payment->created_by)->toBe($scenario['payer']->id)
        ->and($payment->amount)->toBe('1000.00')
        ->and((int) $payment->financial_rule_id)->toBe($monetary->id)
        ->and($payment->financial_assessment['reference']['fund_amount'])->toBe('0.00')
        ->and($payment->financial_assessment['reference']['expected_amount'])->toBe('0.00')
        ->and($payment->financial_assessment['reference']['status'])->toBe('over')
        ->and($payment->financial_assessment['cumulative_excess_amount'])->toBe('1000.00')
        ->and($payment->financial_assessment['coverage']['covered'])->toBeTrue()
        ->and($payment->financial_assessment['rule']['id'])->toBe($monetary->id)
        ->and($payment->financial_assessment['requires_acceptance'])->toBeTrue()
        // Pago o excedente, a etapa não conclui mais sem desembolso.
        ->and(app(MeasurementWorkflow::class)->canConcludeWithoutDisbursement($measurement->fresh(), $scenario['payer']))->toBeFalse();
});

it('leaves the amount due undetermined without Construction Fund and refuses the no-disbursement decision', function () {
    // Torre Sul tem Fundo e nada a pagar no mês (0%): só a falta do Fundo da
    // Torre Norte impede a conclusão sem desembolso.
    $scenario = Governance::plan([['fund' => null, 'name' => 'Torre Norte'], ['fund' => '500000.00', 'name' => 'Torre Sul']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 0]);
    $workflow = app(MeasurementWorkflow::class);
    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement);
    $withoutFund = collect($amountDue->lines)->firstWhere('planSetId', $scenario['planSets'][0]->id);
    $before = fundRequirementState($measurement);

    expect($amountDue->isDetermined())->toBeFalse()
        ->and($amountDue->isNothingDue())->toBeFalse()
        ->and($amountDue->totalRemainingAmount())->toBeNull()
        ->and($amountDue->undetermined)->toBe(['Torre Norte: sem Fundo de Obra na versão do plano congelada pela Engenharia — o valor devido não pode ser determinado.'])
        ->and($withoutFund->fundAmount)->toBeNull()
        ->and($withoutFund->approvedAmount)->toBeNull()
        ->and($withoutFund->remainingAmount)->toBeNull()
        ->and($workflow->canConcludeWithoutDisbursement($measurement, $scenario['payer']))->toBeFalse();

    $errors = fundRequirementErrors(fn () => $workflow->approve(
        $measurement->fresh(),
        $scenario['payer'],
        'Nada a desembolsar nesta competência.',
        expectedStage: MeasurementWorkflow::STAGE_PAYMENT,
        withoutDisbursement: true,
    ));

    expect($errors)->toBe(['payments' => ['A etapa Pagamento não pode ser concluída sem desembolso: Torre Norte: sem Fundo de Obra na versão do plano congelada pela Engenharia — o valor devido não pode ser determinado. Sem o valor devido determinado, a conclusão sem desembolso não é possível: corrija o registro da Engenharia devolvendo a medição àquela etapa. O Fundo de Obra ausente na versão do plano congelada não se corrige pelo fluxo da medição: peça ao administrador a correção do cadastro do plano.']])
        ->and(fundRequirementState($measurement))->toBe($before);
});

it('refuses any payment for the construction without Construction Fund while the funded construction of the same measurement is paid', function () {
    $scenario = Governance::plan([['fund' => null, 'name' => 'Torre Norte'], ['fund' => '500000.00', 'name' => 'Torre Sul']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // Mesmo uma regra larga, de outra pessoa, não cria a base que falta.
    $rule = Governance::rule($scenario, ['maximum_difference_amount' => '1000000.00']);
    $refusal = sprintf(MeasurementPaymentFinancialService::MISSING_FUND_PAYMENT_REFUSAL, 'Torre Norte');
    $before = fundRequirementState($measurement);

    expect(fundRequirementErrors(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '50000.00', ['financial_justification' => Governance::JUSTIFICATION]),
    ])))->toBe(['payments.0.amount' => [$refusal]])
        ->and(fundRequirementErrors(fn () => Governance::pay($scenario, $measurement, [
            Governance::row($scenario, 0, '50000.00', ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]),
        ])))->toBe(['payments.0.amount' => [$refusal]])
        // O registro é atômico: com a linha da Torre Norte, nem a da Torre Sul
        // entra.
        ->and(fundRequirementErrors(fn () => Governance::pay($scenario, $measurement, [
            Governance::row($scenario, 1, '50000.00'),
            Governance::row($scenario, 0, '50000.00', ['financial_justification' => Governance::JUSTIFICATION]),
        ])))->toBe(['payments.1.amount' => [$refusal]])
        ->and(fundRequirementState($measurement))->toBe($before);

    // A operação mista não trava: a Torre Sul recebe o valor aprovado dela.
    $payment = Governance::pay($scenario, $measurement, [Governance::row($scenario, 1, '50000.00')])->sole();
    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement->fresh());
    $funded = collect($amountDue->lines)->firstWhere('planSetId', $scenario['planSets'][1]->id);

    expect((int) $payment->plan_set_id)->toBe($scenario['planSets'][1]->id)
        ->and((int) $payment->created_by)->toBe($scenario['payer']->id)
        ->and($payment->amount)->toBe('50000.00')
        ->and($payment->financial_assessment['reference']['expected_amount'])->toBe('50000.00')
        ->and($payment->financial_assessment['reference']['status'])->toBe('matched')
        ->and($payment->financial_assessment['rule'])->toBeNull()
        ->and($payment->financial_assessment['requires_acceptance'])->toBeFalse()
        ->and($funded->remainingAmount)->toBe('0.00')
        ->and($amountDue->undetermined)->toBe(['Torre Norte: sem Fundo de Obra na versão do plano congelada pela Engenharia — o valor devido não pode ser determinado.'])
        ->and(MeasurementPayment::query()->pluck('plan_set_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$scenario['planSets'][1]->id]);
});

// Nulo nunca é zero: a Torre Norte, medida no mês e sem Fundo, fica sem
// pagamento -- e essa ausência pede a justificativa da etapa Pagamento e o
// aceite expresso de um Finalizador independente, como a de qualquer
// empreendimento com valor a pagar.
it('requires the Payment-stage justification and an independent acceptance for a construction without Construction Fund left unpaid', function () {
    $scenario = Governance::plan([['fund' => null, 'name' => 'Torre Norte'], ['fund' => '500000.00', 'name' => 'Torre Sul']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    Governance::pay($scenario, $measurement, [Governance::row($scenario, 1, '50000.00')]);
    $unpaid = app(MeasurementPaymentFinancialService::class)->unpaidRequiredPlanSets($measurement->fresh());

    expect(array_map(fn ($line): int => $line->planSetId, $unpaid))->toBe([$scenario['planSets'][0]->id])
        ->and(app(MeasurementPaymentFinancialService::class)->describeUnpaidPlanSets($unpaid))
        ->toBe('Torre Norte (valor devido indeterminado: sem Fundo de Obra)')
        ->and(fundRequirementErrors(fn () => Governance::approvePaymentStage($scenario, $measurement)))
        ->toHaveKey('notes');

    Governance::readyToFinalize($scenario, $measurement, 'Torre Norte sem Fundo de Obra na versão congelada: cadastro em correção pelo administrador.');

    expect(fundRequirementErrors(fn () => Governance::finalize($scenario, $measurement)))->toHaveKey('accept_financial_exceptions');

    $finalized = Governance::finalize($scenario, $measurement, accept: true);
    $event = Activity::query()->where('log_name', 'measurement_workflow')->where('description', 'measurement_finalized')->latest('id')->firstOrFail();

    expect($finalized->status)->toBe('finalized')
        ->and(collect($event->properties['unpaid_plan_sets_accepted'])->pluck('plan_set_id')->map(fn (mixed $id): int => (int) $id)->all())
        ->toBe([$scenario['planSets'][0]->id])
        ->and($event->properties['segregation']['financial_exceptions']['independent'])->toBeTrue()
        ->and($event->causer_id)->toBe($scenario['finalizer']->id);
});

// ── Envio e Engenharia: o legado sem Fundo continua andando ──────────────────

it('lets a measurement of a legacy plan without Construction Fund through submission and Engineering up to the Payment stage', function (array $developments, array $percents) {
    // A recusa no envio e na Engenharia foi retirada de propósito: criaria
    // becos sem saída para os dados legados. A versão congelada no envio é a
    // que rege a competência, e a revisão do plano só vale do mês da ativação
    // em diante -- a competência passada de um plano sem Fundo nunca mais
    // seria enviada, e a medição já enviada ficaria presa na Engenharia, cuja
    // única saída é a recusa terminal. A falta de base financeira é recusada
    // onde importa: no pagamento.
    $scenario = Governance::plan($developments);
    $measurement = Governance::measured($scenario, '2026-05', $percents);
    $entry = collect($measurement->engineering_snapshot['plan_sets'])->firstWhere('plan_set_id', $scenario['planSets'][0]->id);
    $submission = Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('description', 'measurement_submitted')
        ->where('subject_type', $measurement->getMorphClass())
        ->where('subject_id', $measurement->id)
        ->sole();

    expect((int) $submission->causer_id)->toBe($scenario['coordinator']->id)
        ->and((int) $measurement->current_stage)->toBe(2)
        ->and($measurement->status)->toBe('in_review')
        ->and((int) $measurement->reviews()->where('stage', MeasurementWorkflow::STAGE_ENGINEERING)->value('reviewer_user_id'))->toBe($scenario['engineer']->id)
        ->and($entry['construction_fund_amount'])->toBeNull()
        ->and($entry['plan_version_number'])->toBe(1);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->approve($measurement->fresh(), $scenario['manager']);
    $workflow->approve($measurement->fresh(), $scenario['compliance']);
    $measurement = $measurement->fresh();

    expect((int) $measurement->current_stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
        ->and($measurement->status)->toBe('awaiting_payment')
        ->and(app(MeasurementPaymentFinancialService::class)->amountDue($measurement)->isDetermined())->toBeFalse();
})->with([
    'the only construction, without fund' => [[['fund' => null, 'name' => 'Torre Norte']], [10]],
    'next to a funded construction' => [[['fund' => null, 'name' => 'Torre Norte'], ['fund' => '500000.00', 'name' => 'Torre Sul']], [10, 10]],
]);

// ── Revisão ──────────────────────────────────────────────────────────────────

it('refuses a revision payment for a construction without Construction Fund even when the family never paid it', function () {
    $scenario = Governance::plan([['fund' => null, 'name' => 'Torre Norte'], ['fund' => '500000.00', 'name' => 'Torre Sul']]);
    // A R0 parada na etapa Pagamento, sem pagamento (caso B): a família não
    // tem histórico. Envio e Engenharia da revisão também não recusam o
    // plano sem Fundo.
    $base = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $revision = Governance::revisionAtCompliance($scenario, $base, [12, 10]);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['compliance']);
    $revision = $revision->fresh();
    $refusal = sprintf(MeasurementPaymentFinancialService::MISSING_FUND_PAYMENT_REFUSAL, 'Torre Norte');
    $before = fundRequirementState($revision);

    expect($revision->isRevision())->toBeTrue()
        ->and((int) $revision->current_stage)->toBe(MeasurementWorkflow::STAGE_PAYMENT)
        ->and($revision->status)->toBe('awaiting_payment')
        ->and(app(MeasurementRevisionService::class)->familyHasHistoricalPayments($revision))->toBeFalse();

    // Antes, sem pagamento anterior da família, o pagamento sem Fundo passava
    // sem referência financeira; agora é recusado como na medição original.
    expect(fundRequirementErrors(fn () => Governance::pay($scenario, $revision, [
        Governance::row($scenario, 0, '60000.00', ['financial_justification' => Governance::JUSTIFICATION]),
    ])))->toBe(['payments.0.amount' => [$refusal]])
        ->and(fundRequirementState($revision))->toBe($before);

    // A Torre Sul, com Fundo, recebe o aprovado revisado.
    $payment = Governance::pay($scenario, $revision, [Governance::row($scenario, 1, '50000.00')])->sole();

    expect((int) $payment->measurement_id)->toBe($revision->id)
        ->and((int) $payment->plan_set_id)->toBe($scenario['planSets'][1]->id)
        ->and($payment->amount)->toBe('50000.00')
        ->and(MeasurementPayment::query()->count())->toBe(1);
});

// ── Tela ─────────────────────────────────────────────────────────────────────

it('shows in the payment modal that the construction without Construction Fund cannot be paid, and refuses it there', function () {
    $scenario = Governance::plan([['fund' => null, 'name' => 'Torre Norte'], ['fund' => '500000.00', 'name' => 'Torre Sul']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $refusal = sprintf(MeasurementPaymentFinancialService::MISSING_FUND_PAYMENT_REFUSAL, 'Torre Norte');

    $component = Livewire::actingAs($scenario['payer'])
        ->test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->assertActionMounted('registerPayment');
    $withoutFund = fundRequirementItemKey($component, $scenario['planSets'][0]->id);
    $funded = fundRequirementItemKey($component, $scenario['planSets'][1]->id);

    expect($withoutFund)->not->toBe('')
        ->and($funded)->not->toBe('')
        ->and(fundRequirementModalText($component, 'reconciliation_reference', $withoutFund))->toBe($refusal)
        ->and(fundRequirementModalText($component, 'reconciliation_divergence', $withoutFund))->toBe($refusal)
        ->and(fundRequirementModalText($component, 'reconciliation_reference', $funded))->toContain('Fundo de obra R$ 500.000,00')
        ->and(fundRequirementModalText($component, 'reconciliation_reference', $funded))->not->toContain('Fundo de Obra válido')
        ->and(fundRequirementModalText($component, 'reconciliation_divergence', $funded))->toBe('Informe o valor para comparar com o saldo esperado.');

    // O envio recebe a mesma recusa, no campo do empreendimento.
    $component
        ->set("mountedActions.0.data.payments.{$withoutFund}.amount", '50.000,00')
        ->set("mountedActions.0.data.payments.{$withoutFund}.financial_justification", Governance::JUSTIFICATION)
        ->callMountedAction();

    expect($component->errors()->get("mountedActions.0.data.payments.{$withoutFund}.amount"))->toBe([$refusal])
        ->and(MeasurementPayment::query()->count())->toBe(0);

    $component->assertActionMounted('registerPayment');
});

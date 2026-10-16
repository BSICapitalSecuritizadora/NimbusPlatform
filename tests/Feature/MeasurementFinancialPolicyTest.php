<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Models\User;
use App\Services\MeasurementFinancialRuleService;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementGovernanceScenario as Governance;
use Tests\Support\MeasurementReceiptEvidenceScenario;

/**
 * Política financeira da Fase 4B-1: o pagamento comum vai até o saldo a
 * pagar; o que passa dele só entra coberto por uma regra financeira vigente,
 * cujo limite (em reais e em percentual do valor aprovado) vale para o
 * excedente acumulado do empreendimento -- nunca pela justificativa sozinha.
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
 * A recusa de validação do campo, lida da exceção: as mensagens têm
 * dois-pontos.
 */
function financialPolicyRefusal(callable $action, string $field = 'payments.0.amount'): ?string
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors()[$field][0] ?? null;
    }

    return null;
}

/**
 * O que uma recusa não pode ter mudado: a situação, a etapa e a versão do
 * fluxo da medição, e os pagamentos dela com o enquadramento de cada um.
 *
 * @return array{status: string, current_stage: int, workflow_revision: int, payments: list<array<string, mixed>>}
 */
function financialPolicyState(Measurement $measurement): array
{
    $fresh = $measurement->fresh();

    return [
        'status' => (string) $fresh->status,
        'current_stage' => (int) $fresh->current_stage,
        'workflow_revision' => (int) $fresh->workflow_revision,
        'payments' => MeasurementPayment::query()
            ->where('measurement_id', $fresh->id)
            ->orderBy('id')
            ->get(['id', 'plan_set_id', 'amount', 'created_by', 'financial_rule_id', 'financial_assessment'])
            ->toArray(),
    ];
}

/**
 * O registro protegido da Finalização da medição.
 */
function financialPolicyFinalizationAudit(Measurement $measurement): Activity
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('description', 'measurement_finalized')
        ->where('subject_type', $measurement->getMorphClass())
        ->where('subject_id', $measurement->getKey())
        ->latest('id')
        ->firstOrFail();
}

/**
 * O excedente registrado antes da Fase 4B-1 só com a justificativa (o saldo
 * de R$ 0,01 o deixava passar): Torre Norte R$ 4.000,00 acima do aprovado,
 * enquadrado "para mais" sem regra. Registrado coberto por uma regra e
 * despido dela direto no banco; a Torre Sul recebe o valor aprovado.
 *
 * @param  array<string, mixed>  $scenario
 */
function financialPolicyLegacyExcess(array $scenario, Measurement $measurement, MeasurementFinancialRule $rule): MeasurementPayment
{
    $payment = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '104000.00', ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]),
        Governance::row($scenario, 1, '50000.00'),
    ])->first();

    DB::table('measurement_payments')->where('id', $payment->id)->update([
        'financial_rule_id' => null,
        'financial_assessment' => json_encode([...$payment->financial_assessment, 'rule' => null, 'coverage' => null]),
    ]);

    return $payment->fresh();
}

it('registers an ordinary payment up to the remaining approved balance and finalizes it with distinct actors', function () {
    $scenario = Governance::plan();
    // Torre Norte: 1.000.000,00 × 10% = 100.000,00; Torre Sul: 500.000,00 × 10% = 50.000,00.
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);

    $payments = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '100000.00'),
        Governance::row($scenario, 1, '50000.00'),
    ]);

    expect($payments)->toHaveCount(2)
        ->and($payments->every(fn ($payment): bool => $payment->financial_assessment['requires_acceptance'] === false))->toBeTrue()
        ->and($payments->first()->financial_assessment['assessed_by_user_id'])->toBe($scenario['payer']->id)
        ->and($payments->first()->financial_assessment['schema_version'])->toBe(2);

    Governance::readyToFinalize($scenario, $measurement);
    $finalized = Governance::finalize($scenario, $measurement);

    expect($finalized->status)->toBe('finalized');
});

it('refuses an excess above a remaining balance of R$ 0,01 without a financial rule', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // O pagamento parcial é divergência para menos: justificada, como sempre.
    Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '99999.99', ['financial_justification' => 'Retenção de um centavo.'])]);

    $refusal = financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '50000.00', ['financial_justification' => Governance::JUSTIFICATION]),
    ]));

    expect($refusal)->toStartWith('O pagamento excede o saldo autorizado e não possui regra financeira válida para a diferença.')
        ->and($measurement->fresh()->payments()->count())->toBe(1);
});

// ── Saldo a pagar: o pagamento comum ─────────────────────────────────────────

it('refuses a positive payment on a construction with R$ 0,00 due when no rule covers it', function () {
    $scenario = Governance::plan();
    // Torre Norte sem avanço físico no mês: valor aprovado R$ 0,00.
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [0, 10]);
    $before = financialPolicyState($measurement);

    $refusal = financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '1000.00', ['financial_justification' => Governance::JUSTIFICATION]),
    ]));

    expect($refusal)->toBe(MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL.' Torre Norte: valor aprovado R$ 0,00, já registrado R$ 0,00, saldo a pagar R$ 0,00; com este pagamento, o excedente acumulado chega a R$ 1.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta.')
        ->and(financialPolicyState($measurement))->toBe($before);
});

it('accepts an ordinary payment up to the positive remaining balance without any rule', function (string $amount, string $status, bool $requiresAcceptance) {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // Pagar a menos é divergência justificada, aceita pelo Finalizador; pagar o saldo inteiro, não.
    $extra = $requiresAcceptance ? ['financial_justification' => 'Parcela da competência; o saldo segue na próxima remessa.'] : [];

    $payment = Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, $amount, $extra)])->sole();

    expect($payment->amount)->toBe($amount)
        ->and($payment->financial_rule_id)->toBeNull()
        ->and($payment->financial_assessment['reference']['expected_balance'])->toBe('100000.00')
        ->and($payment->financial_assessment['reference']['status'])->toBe($status)
        ->and($payment->financial_assessment['requires_acceptance'])->toBe($requiresAcceptance)
        ->and($payment->financial_assessment['rule'])->toBeNull()
        ->and($payment->financial_assessment['coverage'])->toBeNull()
        ->and($payment->financial_assessment['excess_amount'])->toBe('0.00')
        ->and($payment->financial_assessment['cumulative_excess_amount'])->toBe('0.00');
})->with([
    'a cent of it' => ['0.01', 'under', true],
    'half of it' => ['50000.00', 'under', true],
    'all of it' => ['100000.00', 'matched', false],
]);

it('computes the remaining balance of a partially paid construction from what the measurement already registered', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $financial = app(MeasurementPaymentFinancialService::class);
    $north = $scenario['planSets'][0]->id;
    $south = $scenario['planSets'][1]->id;

    Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '30000.00', ['financial_justification' => 'Primeira parcela; o saldo segue na próxima remessa.'])]);
    $partial = collect($financial->amountDue($measurement->fresh())->lines)->keyBy('planSetId');

    expect($partial[$north]->approvedAmount)->toBe('100000.00')
        ->and($partial[$north]->registeredAmount)->toBe('30000.00')
        ->and($partial[$north]->remainingAmount)->toBe('70000.00')
        ->and($partial[$south]->remainingAmount)->toBe('50000.00');

    $final = Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '70000.00')])->sole();
    $settled = collect($financial->amountDue($measurement->fresh())->lines)->keyBy('planSetId');

    expect($final->financial_assessment['reference'])->toMatchArray([
        'expected_amount' => '100000.00',
        'registered_amount' => '30000.00',
        'expected_balance' => '70000.00',
        'entered_amount' => '70000.00',
        'divergence_amount' => '0.00',
        'status' => 'matched',
    ])
        ->and($final->financial_assessment['requires_acceptance'])->toBeFalse()
        ->and($settled[$north]->registeredAmount)->toBe('100000.00')
        ->and($settled[$north]->remainingAmount)->toBe('0.00')
        ->and($settled[$south]->remainingAmount)->toBe('50000.00');
});

it('refuses an additional payment without rule once the construction is fully paid', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '100000.00')]);
    $before = financialPolicyState($measurement);

    $refusal = financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '10000.00', ['financial_justification' => 'Complemento pedido pela construtora.']),
    ]));

    expect($refusal)->toBe(MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL.' Torre Norte: valor aprovado R$ 100.000,00, já registrado R$ 100.000,00, saldo a pagar R$ 0,00; com este pagamento, o excedente acumulado chega a R$ 10.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta.')
        ->and(financialPolicyState($measurement))->toBe($before);
});

// ── Excedente acumulado: o limite não recomeça ───────────────────────────────

it('never grants the rule limit again to a construction already paid above its approved amount', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $tolerance = Governance::rule($scenario);
    $sameLimit = Governance::rule($scenario, ['name' => 'Tolerância da obra']);
    $excess = fn (string $amount, ?MeasurementFinancialRule $rule): array => Governance::row($scenario, 0, $amount, [
        'financial_rule_id' => $rule?->id,
        'financial_justification' => Governance::JUSTIFICATION,
    ]);

    // R$ 3.000,00 acima do aprovado, dentro do limite de R$ 5.000,00.
    Governance::pay($scenario, $measurement, [$excess('103000.00', $tolerance)]);
    $before = financialPolicyState($measurement);
    $exceeded = 'A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 5.500,00, acima do limite em reais da regra (R$ 5.000,00).';

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$excess('2500.00', $tolerance)]), 'payments.0.financial_rule_id'))->toBe($exceeded)
        // Outra regra com o mesmo limite mede o mesmo acumulado: a conta não recomeça.
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$excess('2500.00', $sameLimit)]), 'payments.0.financial_rule_id'))->toBe($exceeded)
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$excess('2500.00', null)])))
        ->toStartWith(MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL)
        ->toContain('já registrado R$ 103.000,00, saldo a pagar R$ 0,00; com este pagamento, o excedente acumulado chega a R$ 5.500,00.')
        ->and(financialPolicyState($measurement))->toBe($before);

    $topUp = Governance::pay($scenario, $measurement, [$excess('2000.00', $tolerance)])->sole();

    expect($topUp->financial_assessment['excess_amount'])->toBe('2000.00')
        ->and($topUp->financial_assessment['cumulative_excess_amount'])->toBe('5000.00')
        ->and($topUp->financial_assessment['coverage'])->toMatchArray(['covered' => true, 'divergence_amount' => '5000.00', 'ceiling_amount' => '5000.00']);
});

it('adds up two rows of one registration for the same construction against the rule limit, persisting none when one is refused', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $tolerance = Governance::rule($scenario);
    $row = fn (string $amount): array => Governance::row($scenario, 0, $amount, ['financial_rule_id' => $tolerance->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $before = financialPolicyState($measurement);

    // Cada linha passa R$ 4.000,00 do que encontra -- sozinha caberia no limite; juntas, não.
    $refusal = financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
        $row('104000.00'),
        $row('4000.00'),
    ]), 'payments.1.financial_rule_id');

    expect($refusal)->toBe('A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 8.000,00, acima do limite em reais da regra (R$ 5.000,00).')
        ->and(financialPolicyState($measurement))->toBe($before)
        ->and(MeasurementPayment::query()->count())->toBe(0);

    $payments = Governance::pay($scenario, $measurement, [$row('104000.00'), $row('1000.00')]);

    expect($payments->pluck('financial_assessment.excess_amount')->all())->toBe(['4000.00', '1000.00'])
        ->and($payments->pluck('financial_assessment.cumulative_excess_amount')->all())->toBe(['4000.00', '5000.00'])
        ->and($payments->pluck('financial_assessment.coverage.divergence_amount')->all())->toBe(['4000.00', '5000.00']);
});

it('never lets separate registrations reuse the rule limit', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $tolerance = Governance::rule($scenario);
    $excess = fn (string $amount): array => Governance::row($scenario, 0, $amount, ['financial_rule_id' => $tolerance->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $refusalFor = fn (string $amount): ?string => financialPolicyRefusal(
        fn () => Governance::pay($scenario, $measurement, [$excess($amount)]),
        'payments.0.financial_rule_id',
    );

    Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '100000.00')]);
    $first = Governance::pay($scenario, $measurement, [$excess('4000.00')])->sole();
    $before = financialPolicyState($measurement);

    expect($refusalFor('4000.00'))->toBe('A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 8.000,00, acima do limite em reais da regra (R$ 5.000,00).')
        ->and(financialPolicyState($measurement))->toBe($before);

    $second = Governance::pay($scenario, $measurement, [$excess('1000.00')])->sole();
    $before = financialPolicyState($measurement);

    expect($refusalFor('0.01'))->toBe('A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 5.000,01, acima do limite em reais da regra (R$ 5.000,00).')
        ->and(financialPolicyState($measurement))->toBe($before)
        // O que cada pagamento acrescentou soma o limite, nunca mais que ele.
        ->and([$first->financial_assessment['excess_amount'], $second->financial_assessment['excess_amount']])->toBe(['4000.00', '1000.00'])
        ->and($second->financial_assessment['cumulative_excess_amount'])->toBe('5000.00');
});

it('never compensates an underpaid construction with an overpaid one', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $narrow = Governance::rule($scenario, ['maximum_difference_amount' => '9999.99']);
    $exact = Governance::rule($scenario, ['name' => 'Tolerância da Torre Sul', 'maximum_difference_amount' => '10000.00']);
    // Total pago igual ao total aprovado (R$ 150.000,00): Torre Norte R$ 10.000,00 abaixo, Torre Sul R$ 10.000,00 acima.
    $rows = fn (?MeasurementFinancialRule $southRule): array => [
        Governance::row($scenario, 0, '90000.00', ['financial_justification' => 'Retenção da Torre Norte.']),
        Governance::row($scenario, 1, '60000.00', ['financial_rule_id' => $southRule?->id, 'financial_justification' => Governance::JUSTIFICATION]),
    ];
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, $rows(null)), 'payments.1.amount'))
        ->toBe(MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL.' Torre Sul: valor aprovado R$ 50.000,00, já registrado R$ 0,00, saldo a pagar R$ 50.000,00; com este pagamento, o excedente acumulado chega a R$ 10.000,00. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta.')
        // A falta da Torre Norte não abate o excedente da Torre Sul.
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, $rows($narrow)), 'payments.1.financial_rule_id'))
        ->toBe('A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 10.000,00, acima do limite em reais da regra (R$ 9.999,99).')
        ->and(financialPolicyState($measurement))->toBe($before);

    [$north, $south] = Governance::pay($scenario, $measurement, $rows($exact))->all();

    expect($north->financial_assessment['reference']['status'])->toBe('under')
        ->and($north->financial_assessment['reference']['divergence_amount'])->toBe('-10000.00')
        ->and($north->financial_assessment['rule'])->toBeNull()
        ->and($south->financial_assessment['coverage'])->toMatchArray([
            'covered' => true,
            'divergence_amount' => '10000.00',
            'reference_amount' => '50000.00',
        ])
        ->and($south->financial_assessment['cumulative_excess_amount'])->toBe('10000.00');
});

// ── Fundo de Obra: zero é Fundo, nulo não é ──────────────────────────────────

it('treats a valid zero Construction Fund as nothing due and accepts a positive payment only with a monetary rule', function () {
    $scenario = Governance::plan([['fund' => '0.00', 'name' => 'Torre Zero']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10]);
    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement);
    $justified = ['financial_justification' => 'Adiantamento previsto no contrato da emissão.'];
    $percentOnly = Governance::rule($scenario, ['name' => 'Tolerância percentual', 'maximum_difference_amount' => null, 'maximum_difference_percent' => '10.0000']);
    $monetary = Governance::rule($scenario);
    $before = financialPolicyState($measurement);

    expect($amountDue->isNothingDue())->toBeTrue()
        ->and($amountDue->lines[0]->fundAmount)->toBe('0.00')
        ->and($amountDue->lines[0]->approvedAmount)->toBe('0.00')
        ->and($amountDue->lines[0]->zeroReason())->toBe('zero_fund')
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '1000.00', $justified)])))
        ->toStartWith(MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL.' Torre Zero: valor aprovado R$ 0,00')
        ->toEndWith(' Sem valor devido em nenhum empreendimento, conclua a etapa Pagamento sem desembolso, com a justificativa obrigatória.')
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
            Governance::row($scenario, 0, '1000.00', ['financial_rule_id' => $percentOnly->id, ...$justified]),
        ]), 'payments.0.financial_rule_id'))
        ->toBe('A regra escolhida não cobre este pagamento. O valor aprovado do empreendimento é R$ 0,00: o limite percentual não autoriza divergência sem valor aprovado positivo, e a exceção sobre valor aprovado zero exige uma regra só com limite em reais, sem limite percentual.')
        ->and(financialPolicyState($measurement))->toBe($before);

    $payment = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '1000.00', ['financial_rule_id' => $monetary->id, ...$justified]),
    ])->sole();

    expect($payment->financial_assessment['requires_acceptance'])->toBeTrue()
        ->and($payment->financial_assessment['coverage'])->toMatchArray([
            'covered' => true,
            'divergence_amount' => '1000.00',
            'reference_amount' => '0.00',
            'ceiling_amount' => '5000.00',
        ])
        ->and(app(MeasurementWorkflow::class)->canConcludeWithoutDisbursement($measurement->fresh(), $scenario['payer']))->toBeFalse();
});

it('concludes without disbursement when every construction has nothing due, a zero Construction Fund included', function () {
    $scenario = Governance::plan([['fund' => '0.00', 'name' => 'Torre Zero'], ['fund' => '1000000.00', 'name' => 'Torre Norte']]);
    $workflow = app(MeasurementWorkflow::class);
    // Maio: Fundo zero na Torre Zero e nenhum avanço na Torre Norte -- nada é devido.
    $may = Governance::awaitingPayment($scenario, '2026-05', [10, 0]);

    expect($workflow->canConcludeWithoutDisbursement($may, $scenario['payer']))->toBeTrue();

    $workflow->approve($may->fresh(), $scenario['payer'], 'Nada é devido nesta competência: Fundo de Obra zero e nenhum avanço a pagar.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, withoutDisbursement: true);
    $finalized = Governance::finalize($scenario, $may, acknowledgeNoDisbursement: true);

    expect($finalized->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->where('measurement_id', $may->id)->count())->toBe(0);

    // Junho: a Torre Norte tem valor devido -- a decisão vale para a medição inteira.
    $june = Governance::awaitingPayment($scenario, '2026-06', [10, 10]);
    $before = financialPolicyState($june);

    expect($workflow->canConcludeWithoutDisbursement($june, $scenario['payer']))->toBeFalse()
        ->and(financialPolicyRefusal(fn () => $workflow->approve($june->fresh(), $scenario['payer'], 'Nada a pagar.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, withoutDisbursement: true), 'payments'))
        ->toBe('A etapa Pagamento não pode ser concluída sem desembolso: Torre Norte: valor devido R$ 100.000,00. Registre o pagamento devido.')
        ->and(financialPolicyState($june))->toBe($before);
});

it('refuses any payment on a construction without Construction Fund, even with a rule, without blocking the other construction', function () {
    $scenario = Governance::plan([['fund' => null, 'name' => 'Torre Legada'], ['fund' => '500000.00', 'name' => 'Torre Sul']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $wide = Governance::rule($scenario, ['maximum_difference_amount' => '1000000.00']);
    $justified = ['financial_justification' => Governance::JUSTIFICATION];
    $missingFund = sprintf(MeasurementPaymentFinancialService::MISSING_FUND_PAYMENT_REFUSAL, 'Torre Legada');
    $before = financialPolicyState($measurement);

    expect(collect($measurement->engineering_snapshot['plan_sets'])->firstWhere('plan_set_id', $scenario['planSets'][0]->id)['construction_fund_amount'])->toBeNull()
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '1000.00', $justified)])))->toBe($missingFund)
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
            Governance::row($scenario, 0, '1000.00', ['financial_rule_id' => $wide->id, ...$justified]),
        ])))->toBe($missingFund)
        // No mesmo registro, a linha da Torre Sul também não fica.
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
            Governance::row($scenario, 1, '50000.00'),
            Governance::row($scenario, 0, '1000.00', $justified),
        ]), 'payments.1.amount'))->toBe($missingFund)
        ->and(financialPolicyState($measurement))->toBe($before);

    $south = Governance::pay($scenario, $measurement, [Governance::row($scenario, 1, '50000.00')])->sole();

    expect($south->financial_assessment['reference']['status'])->toBe('matched')
        ->and($south->financial_assessment['requires_acceptance'])->toBeFalse();
});

it('never reads a missing Construction Fund as nothing due', function () {
    $scenario = Governance::plan([['fund' => null, 'name' => 'Torre Legada']]);
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [0]);
    $workflow = app(MeasurementWorkflow::class);
    $before = financialPolicyState($measurement);

    expect(app(MeasurementPaymentFinancialService::class)->amountDue($measurement)->isNothingDue())->toBeFalse()
        ->and($workflow->canConcludeWithoutDisbursement($measurement, $scenario['payer']))->toBeFalse()
        ->and(financialPolicyRefusal(fn () => $workflow->approve($measurement->fresh(), $scenario['payer'], 'Sem avanço físico nesta competência.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, withoutDisbursement: true), 'payments'))
        ->toStartWith('A etapa Pagamento não pode ser concluída sem desembolso: Torre Legada: sem Fundo de Obra na versão do plano congelada pela Engenharia — o valor devido não pode ser determinado.')
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
            Governance::row($scenario, 0, '1000.00', ['financial_justification' => Governance::JUSTIFICATION]),
        ])))->toBe(sprintf(MeasurementPaymentFinancialService::MISSING_FUND_PAYMENT_REFUSAL, 'Torre Legada'))
        ->and(financialPolicyState($measurement))->toBe($before);
});

it('registers no payment when the frozen financial snapshot cannot be read, even with a rule', function (string $field, ?string $value, string $reason) {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $wide = Governance::rule($scenario, ['maximum_difference_amount' => '1000000.00']);
    $snapshot = $measurement->engineering_snapshot;

    foreach ($snapshot['plan_sets'] as $index => $entry) {
        if ((int) $entry['plan_set_id'] !== (int) $scenario['planSets'][0]->id) {
            continue;
        }

        if ($value === null) {
            unset($snapshot['plan_sets'][$index][$field]);
        } else {
            $snapshot['plan_sets'][$index][$field] = $value;
        }
    }

    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($snapshot)]);
    $before = financialPolicyState($measurement);

    $northRefusal = financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '100000.00', ['financial_rule_id' => $wide->id, 'financial_justification' => Governance::JUSTIFICATION]),
    ]));
    // A posição da medição inteira fica indeterminada: nem a Torre Sul, intacta, recebe pagamento.
    $southRefusal = financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [Governance::row($scenario, 1, '50000.00')]));

    expect($northRefusal)->toBe("Nenhum pagamento é registrado sem a posição financeira desta medição. {$reason} Corrija o registro da Engenharia, devolvendo a medição àquela etapa.")
        ->and($southRefusal)->toBe($northRefusal)
        ->and(financialPolicyState($measurement))->toBe($before);
})->with([
    'unreadable Construction Fund' => ['construction_fund_amount', 'abc', 'Torre Norte: Fundo de Obra ilegível no registro da Engenharia — o valor devido não pode ser determinado.'],
    'realized monthly percent removed' => ['realized_monthly_percent', null, 'Torre Norte: percentual realizado do mês ausente ou ilegível no registro da Engenharia — o valor devido não pode ser determinado.'],
]);

// ── Regra financeira: vigência, direção e limites ────────────────────────────

it('registers an exceptional payment covered by a valid rule with the rule snapshot and the cumulative coverage, accepted by an independent Finalizer', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario);

    $payment = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '104000.00', ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]),
        Governance::row($scenario, 1, '50000.00'),
    ])->first();
    $assessment = $payment->financial_assessment;

    expect($payment->financial_rule_id)->toBe($rule->id)
        ->and($assessment['rule'])->toEqual($rule->fresh()->snapshot())
        ->and($assessment['rule']['created_by'])->toBe($scenario['ruleAuthor']->id)
        ->and($assessment['coverage'])->toEqual([
            'covered' => true,
            'refusal' => null,
            'direction' => 'over',
            'divergence_amount' => '4000.00',
            'reference_amount' => '100000.00',
            'maximum_difference_amount' => '5000.00',
            'maximum_difference_percent' => null,
            'percent_basis' => null,
            'percent_ceiling_amount' => null,
            'ceiling_amount' => '5000.00',
            'cumulative' => true,
        ])
        ->and($assessment['reference']['status'])->toBe('over')
        ->and($assessment['reference']['divergence_amount'])->toBe('4000.00')
        ->and($assessment['excess_amount'])->toBe('4000.00')
        ->and($assessment['cumulative_excess_amount'])->toBe('4000.00')
        ->and($assessment['requires_acceptance'])->toBeTrue()
        ->and($assessment['justification'])->toBe(Governance::JUSTIFICATION)
        ->and($assessment['assessed_by_user_id'])->toBe($scenario['payer']->id);

    Governance::readyToFinalize($scenario, $measurement);

    expect(financialPolicyRefusal(fn () => Governance::finalize($scenario, $measurement), 'accept_financial_exceptions'))
        ->toBe('Confirme expressamente o aceite das divergências e justificativas financeiras.')
        ->and($measurement->fresh()->status)->toBe('approved');

    $finalized = Governance::finalize($scenario, $measurement, accept: true);
    $audit = financialPolicyFinalizationAudit($measurement);
    $makers = collect($audit->properties['segregation']['financial_exceptions']['makers'])->pluck('user_id')->unique()->values()->all();

    expect($finalized->status)->toBe('finalized')
        ->and($audit->causer_id)->toBe($scenario['finalizer']->id)
        ->and(collect($audit->properties['financial_exceptions_accepted'])->pluck('payment_id')->all())->toBe([$payment->id])
        ->and($audit->properties['segregation']['financial_exceptions']['independent'])->toBeTrue()
        ->and($makers)->toContain($scenario['payer']->id, $scenario['ruleAuthor']->id)
        ->and($makers)->not->toContain($scenario['finalizer']->id);
});

it('refuses a rule that does not apply to the payment', function (string $case) {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = match ($case) {
        'expired before the payment date' => Governance::rule($scenario, ['effective_until' => '2026-06-09']),
        'not yet effective on the payment date' => Governance::rule($scenario, ['effective_from' => '2026-06-11']),
        'retired' => tap(Governance::rule($scenario), fn (MeasurementFinancialRule $retired) => app(MeasurementFinancialRuleService::class)->retire($retired, $scenario['ruleAuthor'])),
        'of another emission' => Governance::rule($scenario, ['emission_id' => Emission::factory()->create()->id]),
        'of another construction of the emission' => Governance::rule($scenario, ['construction_id' => $scenario['constructions'][1]->id]),
    };
    $row = fn (MeasurementFinancialRule $chosen): array => Governance::row($scenario, 0, '104000.00', ['financial_rule_id' => $chosen->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$row($rule)]), 'payments.0.financial_rule_id'))
        ->toBe(MeasurementPaymentFinancialService::RULE_UNAVAILABLE_REFUSAL)
        ->and(financialPolicyState($measurement))->toBe($before);

    // A mesma linha com uma regra vigente da emissão passa: a recusa era da regra.
    expect(Governance::pay($scenario, $measurement, [$row(Governance::rule($scenario))])->sole()->financial_assessment['coverage']['covered'])->toBeTrue();
})->with([
    'expired before the payment date',
    'not yet effective on the payment date',
    'retired',
    'of another emission',
    'of another construction of the emission',
]);

it('applies a rule whose validity starts or ends exactly on the payment date', function (array $validity) {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario, $validity);

    $payment = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '104000.00', ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]),
    ])->sole();

    expect($payment->financial_rule_id)->toBe($rule->id)
        ->and($payment->financial_assessment['coverage']['covered'])->toBeTrue();
})->with([
    'effective from the payment date' => [['effective_from' => Governance::PAY_DATE]],
    'effective until the payment date' => [['effective_until' => Governance::PAY_DATE]],
]);

it('refuses a rule that allows a divergence only in the other direction', function (string $direction, string $amount, string $refusal) {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $wrong = Governance::rule($scenario, ['direction' => $direction]);
    $both = Governance::rule($scenario, ['name' => 'Tolerância nos dois sentidos', 'direction' => 'both']);
    $row = fn (MeasurementFinancialRule $chosen): array => Governance::row($scenario, 0, $amount, ['financial_rule_id' => $chosen->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$row($wrong)]), 'payments.0.financial_rule_id'))
        ->toBe("A regra escolhida não cobre este pagamento. {$refusal}")
        ->and(financialPolicyState($measurement))->toBe($before);

    $payment = Governance::pay($scenario, $measurement, [$row($both)])->sole();

    expect($payment->financial_rule_id)->toBe($both->id)
        ->and($payment->financial_assessment['coverage'])->toMatchArray(['covered' => true, 'divergence_amount' => '4000.00']);
})->with([
    'paying less only, against an excess' => ['under', '104000.00', 'A regra permite divergência para menos, e este pagamento diverge para mais do saldo a pagar.'],
    'paying more only, against a partial payment' => ['over', '96000.00', 'A regra permite divergência para mais, e este pagamento diverge para menos do saldo a pagar.'],
]);

it('refuses an excess above the monetary limit of the rule and accepts one within it', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario);
    $excess = fn (string $amount): array => Governance::row($scenario, 0, $amount, ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]);
    // Torre Norte paga pelo aprovado: R$ 100.000,00.
    Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '100000.00')]);
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$excess('7000.00')]), 'payments.0.financial_rule_id'))
        ->toBe('A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 7.000,00, acima do limite em reais da regra (R$ 5.000,00).')
        ->and(financialPolicyState($measurement))->toBe($before);

    $payment = Governance::pay($scenario, $measurement, [$excess('4000.00')])->sole();

    expect($payment->financial_assessment['reference']['expected_balance'])->toBe('0.00')
        ->and($payment->financial_assessment['coverage'])->toMatchArray([
            'covered' => true,
            'divergence_amount' => '4000.00',
            'maximum_difference_amount' => '5000.00',
            'ceiling_amount' => '5000.00',
        ])
        ->and($payment->financial_assessment['excess_amount'])->toBe('4000.00')
        ->and($payment->financial_assessment['cumulative_excess_amount'])->toBe('4000.00');
});

it('caps a percent-only rule at that percent of the approved amount', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario, ['name' => 'Tolerância de 5%', 'maximum_difference_amount' => null, 'maximum_difference_percent' => '5.0000']);
    $row = fn (string $amount): array => Governance::row($scenario, 0, $amount, ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $before = financialPolicyState($measurement);

    expect($rule->fresh()->percent_basis)->toBe('approved_amount')
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$row('105000.01')]), 'payments.0.financial_rule_id'))
        ->toBe('A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 5.000,01, acima de 5% do valor aprovado (R$ 100.000,00), isto é, R$ 5.000,00.')
        ->and(financialPolicyState($measurement))->toBe($before);

    $payment = Governance::pay($scenario, $measurement, [$row('105000.00')])->sole();

    expect($payment->financial_assessment['coverage'])->toEqual([
        'covered' => true,
        'refusal' => null,
        'direction' => 'over',
        'divergence_amount' => '5000.00',
        'reference_amount' => '100000.00',
        'maximum_difference_amount' => null,
        'maximum_difference_percent' => '5.0000',
        'percent_basis' => 'approved_amount',
        'percent_ceiling_amount' => '5000.00',
        'ceiling_amount' => '5000.00',
        'cumulative' => true,
    ]);
});

it('measures the percent limit on the approved amount, never on the remaining balance', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario, ['name' => 'Tolerância de 5%', 'maximum_difference_amount' => null, 'maximum_difference_percent' => '5.0000']);
    $row = fn (string $amount): array => Governance::row($scenario, 0, $amount, ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]);
    Governance::pay($scenario, $measurement, [Governance::row($scenario, 0, '60000.00', ['financial_justification' => 'Primeira parcela.'])]);

    // Saldo a pagar R$ 40.000,00: 5% dele seriam R$ 2.000,00; 5% do aprovado são R$ 5.000,00.
    $payment = Governance::pay($scenario, $measurement, [$row('45000.00')])->sole();
    $before = financialPolicyState($measurement);

    expect($payment->financial_assessment['reference']['expected_balance'])->toBe('40000.00')
        ->and($payment->financial_assessment['coverage'])->toMatchArray([
            'covered' => true,
            'divergence_amount' => '5000.00',
            'reference_amount' => '100000.00',
            'percent_ceiling_amount' => '5000.00',
        ])
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$row('0.01')]), 'payments.0.financial_rule_id'))
        ->toBe('A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 5.000,01, acima de 5% do valor aprovado (R$ 100.000,00), isto é, R$ 5.000,00.')
        ->and(financialPolicyState($measurement))->toBe($before);
});

it('applies both limits of a rule together, the lower ceiling prevailing', function (string $amountLimit, string $percentLimit, string $refused, string $accepted, string $refusal, string $ceiling) {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario, ['maximum_difference_amount' => $amountLimit, 'maximum_difference_percent' => $percentLimit]);
    $row = fn (string $amount): array => Governance::row($scenario, 0, $amount, ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$row($refused)]), 'payments.0.financial_rule_id'))
        ->toBe("A regra escolhida não cobre este pagamento. {$refusal}")
        ->and(financialPolicyState($measurement))->toBe($before);

    expect(Governance::pay($scenario, $measurement, [$row($accepted)])->sole()->financial_assessment['coverage'])
        ->toMatchArray(['covered' => true, 'ceiling_amount' => $ceiling]);
})->with([
    'the monetary limit is lower' => ['3000.00', '5.0000', '103000.01', '103000.00', 'A divergência acumulada do empreendimento chega a R$ 3.000,01, acima do limite em reais da regra (R$ 3.000,00).', '3000.00'],
    'the percent limit is lower' => ['10000.00', '2.0000', '102000.01', '102000.00', 'A divergência acumulada do empreendimento chega a R$ 2.000,01, acima de 2% do valor aprovado (R$ 100.000,00), isto é, R$ 2.000,00.', '2000.00'],
]);

it('never authorizes by percent a construction approved at zero', function (?string $amountLimit) {
    $scenario = Governance::plan();
    // Torre Norte sem avanço físico no mês: valor aprovado R$ 0,00.
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [0, 10]);
    // Os dois limites valem juntos: o percentual recusa mesmo com o limite em reais folgado.
    $withPercent = Governance::rule($scenario, ['maximum_difference_amount' => $amountLimit, 'maximum_difference_percent' => '10.0000']);
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '1000.00', ['financial_rule_id' => $withPercent->id, 'financial_justification' => Governance::JUSTIFICATION]),
    ]), 'payments.0.financial_rule_id'))
        ->toBe('A regra escolhida não cobre este pagamento. O valor aprovado do empreendimento é R$ 0,00: o limite percentual não autoriza divergência sem valor aprovado positivo, e a exceção sobre valor aprovado zero exige uma regra só com limite em reais, sem limite percentual'
            .($amountLimit === null ? '' : ' (os dois limites valem juntos: o percentual impede a regra mesmo com o limite em reais atendido)').'.')
        ->and(financialPolicyState($measurement))->toBe($before);
})->with([
    'percent-only rule' => [null],
    'rule with both limits' => ['5000.00'],
]);

it('never authorizes by the percent of a rule version registered on the legacy basis', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // Versão anterior à base explícita: o percentual dela era sobre o saldo a pagar.
    $legacy = MeasurementFinancialRule::factory()->legacyPercentBasis()->create([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => null,
        'name' => 'Tolerância percentual antiga',
        'direction' => 'over',
        'maximum_difference_amount' => null,
        'maximum_difference_percent' => '50.0000',
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'created_by' => $scenario['ruleAuthor']->id,
    ]);
    $before = financialPolicyState($measurement);

    expect($legacy->fresh()->percent_basis)->toBeNull()
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
            Governance::row($scenario, 0, '101000.00', ['financial_rule_id' => $legacy->id, 'financial_justification' => Governance::JUSTIFICATION]),
        ]), 'payments.0.financial_rule_id'))
        ->toBe('A regra escolhida não cobre este pagamento. O limite percentual desta versão da regra foi cadastrado sobre o saldo a pagar, base que deixou de valer: ele não autoriza divergência. Registre uma nova versão da regra (o percentual passa a ser do valor aprovado).')
        ->and(app(MeasurementPaymentFinancialService::class)->selectableRules($measurement->fresh(), $scenario['planSets'][0]->id, Governance::PAY_DATE, $scenario['payer'])->pluck('id')->all())
        ->not->toContain($legacy->id)
        ->and(financialPolicyState($measurement))->toBe($before);
});

it('refuses a legacy-basis rule with both limits even when its monetary limit would cover the excess', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // Os limites valem juntos, e o percentual da base anterior nunca autoriza: a versão não cobre nada.
    $legacy = MeasurementFinancialRule::factory()->legacyPercentBasis()->create([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => null,
        'name' => 'Tolerância antiga com os dois limites',
        'direction' => 'over',
        'maximum_difference_amount' => '5000.00',
        'maximum_difference_percent' => '10.0000',
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'created_by' => $scenario['ruleAuthor']->id,
    ]);
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '101000.00', ['financial_rule_id' => $legacy->id, 'financial_justification' => Governance::JUSTIFICATION]),
    ]), 'payments.0.financial_rule_id'))
        ->toBe('A regra escolhida não cobre este pagamento. O limite percentual desta versão da regra foi cadastrado sobre o saldo a pagar, base que deixou de valer: ele não autoriza divergência. Registre uma nova versão da regra (o percentual passa a ser do valor aprovado).')
        ->and(financialPolicyState($measurement))->toBe($before);
});

// A versão com percentual da base anterior nunca cobre divergência (coverage()
// a recusa sempre): o modal não a oferece.
it('never offers in the payment form a legacy-basis rule that can cover nothing', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // Com o limite em reais e o percentual da base anterior, a versão nunca cobre divergência
    // alguma (ver o teste anterior): oferecê-la no modal só leva à recusa.
    $legacy = MeasurementFinancialRule::factory()->legacyPercentBasis()->create([
        'emission_id' => $scenario['emission']->id,
        'construction_id' => null,
        'name' => 'Tolerância antiga com os dois limites',
        'direction' => 'over',
        'maximum_difference_amount' => '5000.00',
        'maximum_difference_percent' => '10.0000',
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'created_by' => $scenario['ruleAuthor']->id,
    ]);

    expect(app(MeasurementPaymentFinancialService::class)->selectableRules($measurement, $scenario['planSets'][0]->id, Governance::PAY_DATE, $scenario['payer'])->pluck('id')->all())
        ->not->toContain($legacy->id);
});

it('requires the justification of a divergence even when a rule covers it', function (string $amount, bool $withRule) {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario);
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, $amount, $withRule ? ['financial_rule_id' => $rule->id] : []),
    ]), 'payments.0.financial_justification'))
        ->toBe('Explique a divergência financeira para a conferência do Finalizador.')
        ->and(financialPolicyState($measurement))->toBe($before);
})->with([
    'an excess covered by a rule' => ['104000.00', true],
    'a partial payment' => ['60000.00', false],
]);

it('requires the supporting document a rule demands', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $rule = Governance::rule($scenario, ['requires_document' => true]);
    $row = Governance::row($scenario, 0, '104000.00', ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$row]), 'payments.0.financial_support'))
        ->toBe('Esta regra exige documento de suporte (PDF, JPG ou PNG).')
        ->and(financialPolicyState($measurement))->toBe($before);

    $payment = Governance::pay($scenario, $measurement, [[
        ...$row,
        'financial_support' => MeasurementReceiptEvidenceScenario::file('aditivo-contratual.pdf', '%PDF-1.7 aditivo contratual'),
    ]])->sole();
    $support = $payment->financial_assessment['support'];

    expect($support['name'])->toBe('aditivo-contratual.pdf')
        ->and($support['sha256'])->toBeString()->not->toBeEmpty()
        ->and(Storage::disk($support['disk'])->exists($support['path']))->toBeTrue()
        ->and($payment->financial_assessment['rule']['requires_document'])->toBeTrue();
});

it('refuses a rule to the payer who registered it and accepts it from another payment manager', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // A regra registrada pelo próprio Gestor de Pagamento.
    $ownRule = Governance::rule($scenario, [], $scenario['payer']);
    $row = Governance::row($scenario, 0, '104000.00', ['financial_rule_id' => $ownRule->id, 'financial_justification' => Governance::JUSTIFICATION]);
    $before = financialPolicyState($measurement);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [$row]), 'payments.0.financial_rule_id'))
        ->toBe(MeasurementPaymentFinancialService::RULE_AUTHOR_SELF_USE_REFUSAL.' Você registrou a versão 1 desta regra.')
        ->and(financialPolicyState($measurement))->toBe($before);

    // Outro Gestor de Pagamento assume a operação: a mesma regra serve a ele.
    $substitute = User::factory()->withTwoFactor()->create();
    $substitute->givePermissionTo(['measurements.view', 'measurements.pay']);
    $scenario['operation']->forceFill(['payment_manager_user_id' => $substitute->id])->save();

    $payment = Governance::pay($scenario, $measurement, [$row], $substitute)->sole();

    expect($payment->created_by)->toBe($substitute->id)
        ->and($payment->financial_rule_id)->toBe($ownRule->id)
        ->and($payment->financial_assessment['rule']['created_by'])->toBe($scenario['payer']->id)
        ->and($payment->financial_assessment['assessed_by_user_id'])->toBe($substitute->id);
});

// ── Reavaliação: corrige o enquadramento, não cria autorização ───────────────

it('refuses a reassessment that picks a rule registered after the payment', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    // Pagamento parcial, registrado sem regra: divergência para menos justificada.
    $payment = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '40000.00', ['financial_justification' => 'Primeira parcela; o saldo segue na próxima remessa.']),
    ])->sole();

    $this->travel(5)->minutes();
    $later = Governance::rule($scenario, ['name' => 'Retenção contratual', 'direction' => 'under', 'maximum_difference_amount' => '100000.00']);
    $before = financialPolicyState($measurement);

    $refusal = financialPolicyRefusal(fn () => app(MeasurementWorkflow::class)->reassessPayment(
        $payment->fresh(),
        $scenario['payer'],
        ['financial_rule_id' => $later->id, 'financial_justification' => 'Retenção prevista no contrato da emissão.'],
        (int) $measurement->fresh()->workflow_revision,
    ), 'financial_rule_id');

    expect($refusal)->toBe(sprintf('Esta versão da regra foi registrada depois do pagamento #%d: a reavaliação não cria autorização retroativa. Deixe a regra em branco para manter a atual, ou escolha uma regra que já existia quando o pagamento foi registrado.', $payment->id))
        ->and(financialPolicyState($measurement))->toBe($before);
});

it('keeps the current rule of a reassessment left blank, measured as of the registration even after later payments', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $tolerance = Governance::rule($scenario);
    $wider = Governance::rule($scenario, ['name' => 'Tolerância ampliada', 'maximum_difference_amount' => '20000.00']);

    $first = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '104000.00', ['financial_rule_id' => $tolerance->id, 'financial_justification' => Governance::JUSTIFICATION]),
    ])->sole();
    // Depois dele, o acumulado chega a R$ 10.000,00 -- acima do limite da regra do primeiro.
    $second = Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '6000.00', ['financial_rule_id' => $wider->id, 'financial_justification' => Governance::JUSTIFICATION]),
    ])->sole();
    $secondAssessment = $second->fresh()->financial_assessment;
    // Encerrada depois do pagamento, a regra continua sendo a autorização com que ele entrou.
    app(MeasurementFinancialRuleService::class)->retire($tolerance, $scenario['ruleAuthor']);
    $revision = (int) $measurement->fresh()->workflow_revision;

    app(MeasurementWorkflow::class)->reassessPayment($first->fresh(), $scenario['payer'], [
        'financial_rule_id' => null,
        'financial_justification' => 'Justificativa revista: excedente previsto no aditivo contratual.',
    ], $revision);
    $reassessed = $first->fresh();

    expect($reassessed->financial_rule_id)->toBe($tolerance->id)
        ->and($reassessed->financial_assessment['rule']['id'])->toBe($tolerance->id)
        ->and($reassessed->financial_assessment['coverage'])->toMatchArray(['covered' => true, 'divergence_amount' => '4000.00'])
        ->and($reassessed->financial_assessment['excess_amount'])->toBe('4000.00')
        ->and($reassessed->financial_assessment['cumulative_excess_amount'])->toBe('4000.00')
        ->and($reassessed->financial_assessment['justification'])->toBe('Justificativa revista: excedente previsto no aditivo contratual.')
        ->and($second->fresh()->financial_assessment)->toEqual($secondAssessment)
        ->and((int) $measurement->fresh()->workflow_revision)->toBe($revision + 1);
});

it('never keeps an excess payment without a rule on reassessment, and corrects it only with a rule that already existed', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $tolerance = Governance::rule($scenario);
    $payment = financialPolicyLegacyExcess($scenario, $measurement, $tolerance);

    $this->travel(5)->minutes();
    $later = Governance::rule($scenario, ['name' => 'Tolerância posterior', 'maximum_difference_amount' => '10000.00']);
    $reassess = fn (?int $ruleId) => app(MeasurementWorkflow::class)->reassessPayment(
        $payment->fresh(),
        $scenario['payer'],
        ['financial_rule_id' => $ruleId, 'financial_justification' => Governance::JUSTIFICATION],
        (int) $measurement->fresh()->workflow_revision,
    );
    $before = financialPolicyState($measurement);

    expect($payment->financial_assessment['reference']['status'])->toBe('over')
        ->and($payment->financial_assessment['rule'])->toBeNull()
        // Em branco, não há regra atual a manter: o excedente não fica sem regra.
        ->and(financialPolicyRefusal(fn () => $reassess(null), 'financial_rule_id'))
        ->toStartWith(MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL.' Torre Norte: valor aprovado R$ 100.000,00, já registrado R$ 0,00')
        ->and(financialPolicyRefusal(fn () => $reassess($later->id), 'financial_rule_id'))
        ->toStartWith(sprintf('Esta versão da regra foi registrada depois do pagamento #%d: a reavaliação não cria autorização retroativa.', $payment->id))
        ->and(financialPolicyState($measurement))->toBe($before);

    $reassess($tolerance->id);
    $corrected = $payment->fresh();

    expect($corrected->financial_rule_id)->toBe($tolerance->id)
        ->and($corrected->financial_assessment['rule']['id'])->toBe($tolerance->id)
        ->and($corrected->financial_assessment['coverage'])->toMatchArray(['covered' => true, 'divergence_amount' => '4000.00']);
});

it('refuses at the Finalization an excess payment registered without a rule before the rule became mandatory', function () {
    $scenario = Governance::plan();
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $payment = financialPolicyLegacyExcess($scenario, $measurement, Governance::rule($scenario));
    Governance::readyToFinalize($scenario, $measurement);

    expect(fn () => Governance::finalize($scenario, $measurement, accept: true))
        ->toThrow(MeasurementWorkflowException::class, sprintf('O pagamento #%d excede o saldo autorizado sem regra financeira que cubra a diferença', $payment->id))
        ->and($measurement->fresh()->status)->toBe('approved')
        // Nem a revisão o leva adiante: a orientação é a mesma da Finalização.
        ->and(app(MeasurementRevisionService::class)->creationBlockReason($measurement->fresh()))
        ->toBe(sprintf(MeasurementRevisionService::PAID_LEGACY_EXCESS_REFUSAL, $payment->id));
});

// ── O que já funcionava continua: sem desembolso e revisões ──────────────────

it('keeps the original without disbursement working end to end with distinct actors', function () {
    $scenario = Governance::plan();
    // Nenhum avanço físico no mês: nada é devido em nenhum empreendimento.
    $measurement = Governance::awaitingPayment($scenario, '2026-05', [0, 0]);
    $workflow = app(MeasurementWorkflow::class);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $measurement, [
        Governance::row($scenario, 0, '1000.00', ['financial_justification' => Governance::JUSTIFICATION]),
    ])))->toEndWith(' Sem valor devido em nenhum empreendimento, conclua a etapa Pagamento sem desembolso, com a justificativa obrigatória.')
        ->and($workflow->canConcludeWithoutDisbursement($measurement->fresh(), $scenario['payer']))->toBeTrue();

    $workflow->approve($measurement->fresh(), $scenario['payer'], 'Sem avanço físico nesta competência: nada a pagar.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, withoutDisbursement: true);

    expect($measurement->fresh()->status)->toBe('approved')
        ->and((int) $measurement->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and(financialPolicyRefusal(fn () => Governance::finalize($scenario, $measurement), 'acknowledge_no_disbursement'))
        ->toBe(MeasurementWorkflow::NO_DISBURSEMENT_ACKNOWLEDGMENT_REQUIRED)
        ->and($measurement->fresh()->status)->toBe('approved');

    $finalized = Governance::finalize($scenario, $measurement, acknowledgeNoDisbursement: true);
    $audit = financialPolicyFinalizationAudit($measurement);

    expect($finalized->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->where('measurement_id', $measurement->id)->count())->toBe(0)
        ->and($audit->causer_id)->toBe($scenario['finalizer']->id)
        ->and($audit->properties['no_disbursement_acknowledged']['decided_by'])->toBe($scenario['payer']->id)
        ->and($audit->properties['no_disbursement_acknowledged']['same_actor_as_decision'])->toBeFalse()
        ->and($audit->properties['segregation']['no_disbursement']['independent'])->toBeTrue()
        ->and($audit->properties['segregation']['no_disbursement']['checker_user_id'])->toBe($scenario['finalizer']->id);
});

it('keeps the R1 reconciliation: pays within the open balance, never above it after a family payment, and finalizes', function () {
    $scenario = Governance::plan();
    // R0 finalizada, paga pelo aprovado: Torre Norte R$ 100.000,00, Torre Sul R$ 50.000,00.
    $original = Governance::finalized($scenario, '2026-05', [10, 10]);
    // R1: Torre Norte revista para 15% (aprovado R$ 150.000,00); Torre Sul igual.
    $revision = Governance::revisionAtCompliance($scenario, $original, [15, 10]);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['compliance']);
    $rule = Governance::rule($scenario, ['maximum_difference_amount' => '20000.00']);
    $justified = ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION];
    $history = fn (): array => MeasurementPayment::query()
        ->where('measurement_id', $original->id)
        ->orderBy('id')
        ->get(['id', 'plan_set_id', 'amount', 'created_by', 'financial_rule_id', 'financial_assessment', 'updated_at'])
        ->toArray();
    $paidBefore = $history();
    $before = financialPolicyState($revision);

    expect($revision->fresh()->status)->toBe('awaiting_payment')
        ->and($revision->fresh()->isEffectiveRevision())->toBeTrue()
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $revision, [Governance::row($scenario, 0, '60000.00', $justified)])))
        ->toBe('O valor informado para Torre Norte passa do saldo em aberto da revisão (R$ 50.000,00): o que já foi pago nas revisões anteriores não se paga de novo, e o pagamento complementar não passa do saldo a pagar, nem com regra financeira.')
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $revision, [Governance::row($scenario, 1, '1000.00', $justified)])))
        ->toBe('Não há valor a pagar nesta revisão para Torre Sul: o valor aprovado revisado (R$ 50.000,00) já está coberto pelos pagamentos registrados (R$ 50.000,00 antes da revisão, R$ 0,00 nela).')
        ->and(financialPolicyState($revision))->toBe($before);

    $payment = Governance::pay($scenario, $revision, [Governance::row($scenario, 0, '50000.00')])->sole();

    expect($payment->financial_assessment['reference'])->toMatchArray([
        'expected_amount' => '150000.00',
        'historical_paid_amount' => '100000.00',
        'expected_balance' => '50000.00',
        'divergence_amount' => '0.00',
        'status' => 'matched',
    ])->and($payment->financial_assessment['requires_acceptance'])->toBeFalse();

    Governance::readyToFinalize($scenario, $revision);

    expect(Governance::finalize($scenario, $revision)->status)->toBe('finalized')
        ->and($history())->toBe($paidBefore);
});

it('blocks any revision payment while the family has an outstanding overpayment, even with a rule', function () {
    $scenario = Governance::plan();
    $original = Governance::finalized($scenario, '2026-05', [10, 10]);
    // R1: Torre Norte revista para 5% (aprovado R$ 50.000,00), já paga R$ 100.000,00 na R0.
    $revision = Governance::revisionAtCompliance($scenario, $original, [5, 10]);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['compliance']);
    $rule = Governance::rule($scenario, ['maximum_difference_amount' => '1000000.00']);
    $before = financialPolicyState($revision);

    expect(financialPolicyRefusal(fn () => Governance::pay($scenario, $revision, [
        Governance::row($scenario, 0, '1000.00', ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION]),
    ])))->toBe('A família já pagou R$ 50.000,00 a maior em Torre Norte, acima do aprovado vigente (R$ 50.000,00): nenhum novo pagamento é registrado enquanto houver valor pago a maior em aberto. O sistema não registra devolução, estorno nem compensação.')
        ->and(financialPolicyState($revision))->toBe($before);
});

it('refuses a revision payment for a construction without Construction Fund', function () {
    $scenario = Governance::plan([['fund' => null, 'name' => 'Torre Legada'], ['fund' => '500000.00', 'name' => 'Torre Sul']]);
    // R0 parada na etapa Pagamento, sem pagamento: revisada pelo caso B.
    $original = Governance::awaitingPayment($scenario, '2026-05', [10, 10]);
    $revision = Governance::revisionAtCompliance($scenario, $original, [10, 10]);
    app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['compliance']);
    $rule = Governance::rule($scenario, ['maximum_difference_amount' => '1000000.00']);
    $justified = ['financial_rule_id' => $rule->id, 'financial_justification' => Governance::JUSTIFICATION];
    $before = financialPolicyState($revision);

    expect($revision->fresh()->status)->toBe('awaiting_payment')
        ->and(financialPolicyRefusal(fn () => Governance::pay($scenario, $revision, [Governance::row($scenario, 0, '1000.00', $justified)])))
        ->toBe(sprintf(MeasurementPaymentFinancialService::MISSING_FUND_PAYMENT_REFUSAL, 'Torre Legada'))
        ->and(financialPolicyState($revision))->toBe($before);

    // Torre Sul, sem pagamento anterior na família: acima do saldo, só com regra -- como na original.
    $payment = Governance::pay($scenario, $revision, [Governance::row($scenario, 1, '52000.00', $justified)])->sole();

    expect($payment->financial_assessment['coverage'])->toMatchArray([
        'covered' => true,
        'divergence_amount' => '2000.00',
        'reference_amount' => '50000.00',
    ])->and($payment->financial_assessment['requires_acceptance'])->toBeTrue();
});

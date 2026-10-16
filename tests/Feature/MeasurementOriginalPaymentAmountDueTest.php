<?php

use App\Models\Emission;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementNoDisbursementScenario as Scenario;
use Tests\Support\MeasurementRevisionScenario;

/**
 * O pagamento da medição original sem valor devido: o registro só o aceita
 * coberto por regra financeira -- a justificativa sozinha não basta --, e o
 * valor devido indeterminado nunca é licença para pagar. O pagamento devido,
 * parcial ou final, segue o caminho de sempre. Desde a Fase 4B-1, o que passa
 * do saldo a pagar -- zero ou não -- também só entra com regra que o cubra.
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
 * A recusa de validação no campo indicado, lida da exceção: as mensagens têm
 * dois-pontos.
 */
function originalDueRefusal(callable $action, string $field = 'payments.0.amount'): ?string
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors()[$field][0] ?? null;
    }

    return null;
}

/**
 * A recusa do pagamento acima do saldo a pagar sem regra financeira, com a
 * conta do empreendimento (Fase 4B-1: a justificativa sozinha não basta).
 */
function originalDueExcessRefusal(string $construction, string $approved, string $registered, string $remaining, string $excess, bool $suggestsNoDisbursement = false): string
{
    return MeasurementPaymentFinancialService::EXCESS_WITHOUT_RULE_REFUSAL
        ." {$construction}: valor aprovado {$approved}, já registrado {$registered}, saldo a pagar {$remaining}; com este pagamento, o excedente acumulado chega a {$excess}. Acima do saldo, o pagamento só é registrado com uma regra financeira vigente que cubra o excedente acumulado; a justificativa sozinha não basta."
        .($suggestsNoDisbursement ? ' Sem valor devido em nenhum empreendimento, conclua a etapa Pagamento sem desembolso, com a justificativa obrigatória.' : '');
}

/**
 * O que uma recusa não pode ter mudado: o fluxo e os pagamentos da medição.
 *
 * @return array{status: string, current_stage: int, workflow_revision: int, payments: list<array<string, mixed>>}
 */
function originalDueState(Measurement $measurement): array
{
    $fresh = $measurement->fresh();

    return [
        'status' => (string) $fresh->status,
        'current_stage' => (int) $fresh->current_stage,
        'workflow_revision' => (int) $fresh->workflow_revision,
        'payments' => MeasurementPayment::query()
            ->where('measurement_id', $fresh->id)
            ->orderBy('id')
            ->get(['id', 'plan_set_id', 'amount', 'financial_assessment'])
            ->toArray(),
    ];
}

/**
 * Uma linha do registro de pagamentos, para o empreendimento de posição
 * indicada no cenário.
 *
 * @param  array<string, mixed>  $scenario
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function originalDueRow(array $scenario, int $planSet, string $amount, array $extra = []): array
{
    return [
        'plan_set_id' => $scenario['planSets'][$planSet]->id,
        'pay_date' => '2026-06-10',
        'amount' => $amount,
        'method' => 'TED',
        ...$extra,
    ];
}

/**
 * Regra financeira da emissão da medição que autoriza pagar a mais até
 * R$ 5.000,00, vigente em 2026.
 *
 * @param  array<string, mixed>  $attributes
 */
function originalDueRule(Measurement $measurement, array $attributes = []): MeasurementFinancialRule
{
    return MeasurementFinancialRule::factory()->create([
        'emission_id' => $measurement->operation->emission_id,
        'name' => 'Adiantamento contratual',
        'direction' => 'over',
        'maximum_difference_amount' => '5000.00',
        'maximum_difference_percent' => null,
        ...$attributes,
    ]);
}

/**
 * O registro da Engenharia corrompido de um jeito que não sustenta a conta.
 *
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function originalDueCorrupted(string $case, array $snapshot): array
{
    match ($case) {
        'percent missing' => $snapshot['plan_sets'][0]['realized_monthly_percent'] = null,
        'fund with thousands separator' => $snapshot['plan_sets'][0]['construction_fund_amount'] = '1.000.000',
        'construction repeated' => $snapshot['plan_sets'][] = $snapshot['plan_sets'][0],
        'record approved before plan versioning with another fund' => (function () use (&$snapshot): void {
            unset($snapshot['plan_sets'][0]['plan_version_id'], $snapshot['plan_sets'][0]['plan_version_number']);
            $snapshot['plan_sets'][0]['construction_fund_amount'] = '999.00';
        })(),
    };

    return $snapshot;
}

// ── Nada devido: só com regra financeira ─────────────────────────────────────

it('refuses a payment without financial rule on an original with nothing due and keeps the no-disbursement path (A)', function (string $fund, int $percent) {
    $scenario = Scenario::plan([['fund' => $fund]]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [$percent]);
    $workflow = app(MeasurementWorkflow::class);
    $before = originalDueState($measurement);

    $refusal = originalDueRefusal(fn () => $workflow->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '1000.00', ['financial_justification' => 'Adiantamento pedido pela construtora.']),
    ));

    expect($refusal)->toBe(originalDueExcessRefusal('Torre A', 'R$ 0,00', 'R$ 0,00', 'R$ 0,00', 'R$ 1.000,00', suggestsNoDisbursement: true))
        ->and(originalDueState($measurement))->toBe($before)
        ->and($workflow->canConcludeWithoutDisbursement($measurement->fresh(), $scenario['actor']))->toBeTrue();

    $finalized = Scenario::finalizeAcknowledging($scenario, Scenario::concludeWithoutDisbursement($scenario, $measurement));

    expect($finalized->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0);
})->with([
    'no physical progress in the month' => ['1000000.00', 0],
    'zero Construction Fund' => ['0.00', 10],
    'product rounded to zero cents' => ['0.04', 10],
]);

it('refuses an extra payment without rule once the original construction is fully paid, and keeps the recorded payment intact (B)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);
    $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '100000.00'));
    $before = originalDueState($measurement);

    $refusal = originalDueRefusal(fn () => $workflow->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '10000.00', ['financial_justification' => 'Complemento pedido pela construtora.']),
    ));

    expect($refusal)->toBe(originalDueExcessRefusal('Torre A', 'R$ 100.000,00', 'R$ 100.000,00', 'R$ 0,00', 'R$ 10.000,00'))
        ->and(originalDueState($measurement))->toBe($before);
});

it('reads an original already paid above the approved amount as nothing due, never as a credit (B)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);
    // Divergência para mais com o valor devido em aberto -- Fase 4B-1: só com
    // regra que cubra o excedente.
    $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '120000.00', [
        'financial_rule_id' => originalDueRule($measurement, ['maximum_difference_amount' => '20000.00'])->id,
        'financial_justification' => 'Medição complementar paga junto, conforme e-mail da construtora.',
    ]));
    $before = originalDueState($measurement);

    $refusal = originalDueRefusal(fn () => $workflow->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '0.01', ['financial_justification' => 'Ajuste de centavos.']),
    ));

    expect($refusal)->toBe(originalDueExcessRefusal('Torre A', 'R$ 100.000,00', 'R$ 120.000,00', 'R$ 0,00', 'R$ 20.000,01'))
        ->and(originalDueState($measurement))->toBe($before);
});

// ── Valor devido: o caminho de sempre ────────────────────────────────────────

it('keeps partial and final payments within the amount due of an original (C)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);

    $partial = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '60000.00', [
        'financial_justification' => 'Primeira parcela; o saldo segue na próxima remessa.',
    ]));
    $final = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '40000.00'));

    expect($partial->financial_assessment['reference']['expected_balance'])->toBe('100000.00')
        ->and($final->financial_assessment['reference']['expected_balance'])->toBe('40000.00')
        ->and($final->financial_assessment['requires_acceptance'])->toBeFalse()
        ->and(app(MeasurementPaymentFinancialService::class)->amountDue($measurement->fresh())->isNothingDue())->toBeTrue()
        ->and(MeasurementPayment::query()->pluck('amount')->all())->toBe(['60000.00', '40000.00']);
});

it('never takes invisible characters as the justification of a divergence', function (string $invisible) {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $before = originalDueState($measurement);

    expect(originalDueRefusal(fn () => app(MeasurementWorkflow::class)->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '50000.00', ['financial_justification' => $invisible]),
    ), 'payments.0.financial_justification'))->toBe('Explique a divergência financeira para a conferência do Finalizador.')
        ->and(originalDueState($measurement))->toBe($before);
})->with([
    'zero-width space' => ["\u{200B}"],
    'no-break spaces' => ["\u{00A0}\u{202F}"],
    'word joiner between spaces' => [" \u{2060} "],
]);

it('keeps a payment above a remaining amount due as a divergence for the Finalizer, only under a covering rule (C)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);
    $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '60000.00', [
        'financial_justification' => 'Primeira parcela.',
    ]));
    $before = originalDueState($measurement);
    $row = originalDueRow($scenario, 0, '50000.00', [
        'financial_justification' => 'Saldo pago com a retenção da medição anterior.',
    ]);

    // Fase 4B-1: acima do saldo a pagar, a justificativa sozinha não autoriza o excedente.
    expect(originalDueRefusal(fn () => $workflow->registerPayment($measurement->fresh(), $scenario['actor'], $row)))
        ->toBe(originalDueExcessRefusal('Torre A', 'R$ 100.000,00', 'R$ 60.000,00', 'R$ 40.000,00', 'R$ 10.000,00'))
        ->and(originalDueState($measurement))->toBe($before);

    $rule = originalDueRule($measurement, ['maximum_difference_amount' => '10000.00']);
    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], [...$row, 'financial_rule_id' => $rule->id]);

    expect($payment->financial_assessment['reference']['expected_balance'])->toBe('40000.00')
        ->and($payment->financial_assessment['reference']['divergence_amount'])->toBe('10000.00')
        ->and($payment->financial_assessment['rule']['id'])->toBe($rule->id)
        ->and($payment->financial_assessment['requires_acceptance'])->toBeTrue();
});

// ── Vários empreendimentos ───────────────────────────────────────────────────

it('refuses the whole registration when a construction has nothing due, inserting none of its rows (D)', function (array $order, string $field) {
    $scenario = Scenario::plan([['fund' => '1000000.00'], ['fund' => '800000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0, 10]);
    $workflow = app(MeasurementWorkflow::class);
    $before = originalDueState($measurement);
    $rows = [
        0 => originalDueRow($scenario, 0, '1000.00', ['financial_justification' => 'Adiantamento.']),
        1 => originalDueRow($scenario, 1, '80000.00'),
    ];

    $refusal = originalDueRefusal(fn () => $workflow->registerPayments(
        $measurement->fresh(),
        $scenario['actor'],
        array_map(fn (int $planSet): array => $rows[$planSet], $order),
    ), $field);

    expect($refusal)->toBe(originalDueExcessRefusal('Torre A', 'R$ 0,00', 'R$ 0,00', 'R$ 0,00', 'R$ 1.000,00'))
        ->and(originalDueState($measurement))->toBe($before);

    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], $rows[1]);

    expect(MeasurementPayment::query()->pluck('id')->all())->toBe([$payment->id])
        ->and(app(MeasurementPaymentFinancialService::class)->amountDue($measurement->fresh())->isNothingDue())->toBeTrue();
})->with([
    'zero-due construction first' => [[0, 1], 'payments.0.amount'],
    'payable construction first' => [[1, 0], 'payments.1.amount'],
]);

it('counts the earlier rows of the same registration, so the amount due is not paid twice (D)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00'], ['fund' => '800000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0, 10]);
    $workflow = app(MeasurementWorkflow::class);
    $before = originalDueState($measurement);

    $refusal = originalDueRefusal(fn () => $workflow->registerPayments($measurement->fresh(), $scenario['actor'], [
        originalDueRow($scenario, 1, '80000.00'),
        originalDueRow($scenario, 1, '80000.00', ['financial_justification' => 'Repetido por engano.']),
    ]), 'payments.1.amount');

    expect($refusal)->toBe(originalDueExcessRefusal('Torre B', 'R$ 80.000,00', 'R$ 80.000,00', 'R$ 0,00', 'R$ 80.000,00'))
        ->and(originalDueState($measurement))->toBe($before);
});

// ── Posição indeterminada: nenhum pagamento ──────────────────────────────────

it('registers no payment when the engineering record does not support the amount due (E)', function (string $case, string $reason) {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    DB::table('measurements')->where('id', $measurement->id)->update([
        'engineering_snapshot' => json_encode(originalDueCorrupted($case, $measurement->engineering_snapshot)),
    ]);
    $before = originalDueState($measurement);

    $refusal = originalDueRefusal(fn () => app(MeasurementWorkflow::class)->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '100000.00', ['financial_justification' => 'Pagamento da competência.']),
    ));

    expect($refusal)
        ->toStartWith('Nenhum pagamento é registrado sem a posição financeira desta medição.')
        ->toContain($reason)
        ->toEndWith('Corrija o registro da Engenharia, devolvendo a medição àquela etapa.')
        ->and(originalDueState($measurement))->toBe($before);
})->with([
    'percent missing (the reconciliation would read 0%)' => ['percent missing', 'percentual realizado do mês ausente ou ilegível'],
    'fund with thousands separator (read differently by each parser)' => ['fund with thousands separator', 'Fundo de Obra ilegível'],
    'construction repeated' => ['construction repeated', 'aparece mais de uma vez'],
    'record approved before plan versioning with another fund' => ['record approved before plan versioning with another fund', 'não confere com a versão do plano do arquivo da medição'],
]);

it('registers no payment on an undetermined position even with a financial rule that would cover it (E)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $rule = originalDueRule($measurement, ['maximum_difference_amount' => '1000000.00']);
    // Sem o percentual, a conciliação leria 0%: a regra cobriria a divergência.
    DB::table('measurements')->where('id', $measurement->id)->update([
        'engineering_snapshot' => json_encode(originalDueCorrupted('percent missing', $measurement->engineering_snapshot)),
    ]);
    $before = originalDueState($measurement);

    expect(originalDueRefusal(fn () => app(MeasurementWorkflow::class)->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '1000.00', [
            'financial_rule_id' => $rule->id,
            'financial_justification' => 'Adiantamento.',
        ]),
    )))->toStartWith('Nenhum pagamento é registrado sem a posição financeira desta medição.')
        ->and(originalDueState($measurement))->toBe($before);
});

it('points a paid original whose engineering record no longer supports the account to the formal revision, not back to Engineering (E)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);
    $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '60000.00', [
        'financial_justification' => 'Primeira parcela.',
    ]));
    DB::table('measurements')->where('id', $measurement->id)->update([
        'engineering_snapshot' => json_encode(originalDueCorrupted('percent missing', $measurement->fresh()->engineering_snapshot)),
    ]);
    $before = originalDueState($measurement);

    $refusal = originalDueRefusal(fn () => $workflow->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '40000.00'),
    ));

    expect($refusal)
        ->toStartWith('Nenhum pagamento é registrado sem a posição financeira desta medição.')
        ->toEndWith('não volta à Engenharia para ser reescrito. Aprove a etapa Pagamento com o que já foi pago, conclua a conferência dos comprovantes e corrija os dados por uma revisão formal (Criar revisão).')
        ->and(originalDueState($measurement))->toBe($before);
});

it('refuses a payment on a construction without Construction Fund, as on the zero-due one next to it without rule (E)', function () {
    $scenario = Scenario::plan([['fund' => null], ['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10, 0]);
    $workflow = app(MeasurementWorkflow::class);
    $before = originalDueState($measurement);

    // Fase 4B-1: sem Fundo de Obra não há valor aprovado, e nenhum pagamento é registrado.
    expect(originalDueRefusal(fn () => $workflow->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '50000.00'),
    )))->toBe(sprintf(MeasurementPaymentFinancialService::MISSING_FUND_PAYMENT_REFUSAL, 'Torre A'))
        ->and(originalDueState($measurement))->toBe($before);

    expect(originalDueRefusal(fn () => $workflow->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 1, '1000.00', ['financial_justification' => 'Adiantamento.']),
    )))->toBe(originalDueExcessRefusal('Torre B', 'R$ 0,00', 'R$ 0,00', 'R$ 0,00', 'R$ 1.000,00'))
        ->and(originalDueState($measurement))->toBe($before);
});

// ── Exceção autorizada por regra financeira ──────────────────────────────────

it('registers a zero-due payment covered by a financial rule and requires the express acceptance of the Finalizer (F)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $rule = originalDueRule($measurement);
    $workflow = app(MeasurementWorkflow::class);

    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '5000.00', [
        'financial_rule_id' => $rule->id,
        'financial_justification' => 'Adiantamento previsto no contrato da emissão.',
    ]));

    expect($payment->financial_rule_id)->toBe($rule->id)
        ->and($payment->financial_assessment['rule']['id'])->toBe($rule->id)
        ->and($payment->financial_assessment['reference']['expected_balance'])->toBe('0.00')
        ->and($payment->financial_assessment['justification'])->toBe('Adiantamento previsto no contrato da emissão.')
        ->and($payment->financial_assessment['requires_acceptance'])->toBeTrue()
        ->and($workflow->canConcludeWithoutDisbursement($measurement->fresh(), $scenario['actor']))->toBeFalse();

    $workflow->approve($measurement->fresh(), $scenario['actor']);
    MeasurementRevisionScenario::receiptsApproved($scenario, $measurement);

    expect(originalDueRefusal(fn () => $workflow->finalize($measurement->fresh(), $scenario['finalizer']), 'accept_financial_exceptions'))
        ->toBe('Confirme expressamente o aceite das divergências e justificativas financeiras.');

    $workflow->finalize($measurement->fresh(), $scenario['finalizer'], acceptFinancialExceptions: true);

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->pluck('id')->all())->toBe([$payment->id]);
});

it('keeps the justification mandatory for a zero-due payment covered by a rule (F)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $rule = originalDueRule($measurement);
    $before = originalDueState($measurement);

    expect(originalDueRefusal(fn () => app(MeasurementWorkflow::class)->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '5000.00', ['financial_rule_id' => $rule->id]),
    ), 'payments.0.financial_justification'))->toBe('Explique a divergência financeira para a conferência do Finalizador.')
        ->and(originalDueState($measurement))->toBe($before);
});

it('refuses a zero-due payment whose rule does not authorize it (F)', function (string $case) {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $rule = originalDueRule($measurement, match ($case) {
        'limit below the amount' => ['maximum_difference_amount' => '999.99'],
        'percent limit only (no amount due to apply it to)' => ['maximum_difference_amount' => null, 'maximum_difference_percent' => '10.0000'],
        'paying less only' => ['direction' => 'under'],
        'retired' => ['retired_at' => now()],
        'another emission' => ['emission_id' => Emission::factory()->create()->id],
        'not yet effective on the payment date' => ['effective_from' => '2026-07-01'],
    });
    // Fase 4B-1: a recusa diz por que a regra não cobre -- ou que ela não vale para o pagamento.
    $expected = match ($case) {
        'limit below the amount' => 'A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 1.000,00, acima do limite em reais da regra (R$ 999,99).',
        'percent limit only (no amount due to apply it to)' => 'A regra escolhida não cobre este pagamento. O valor aprovado do empreendimento é R$ 0,00: o limite percentual não autoriza divergência sem valor aprovado positivo, e a exceção sobre valor aprovado zero exige uma regra só com limite em reais, sem limite percentual.',
        'paying less only' => 'A regra escolhida não cobre este pagamento. A regra permite divergência para menos, e este pagamento diverge para mais do saldo a pagar.',
        'retired', 'another emission', 'not yet effective on the payment date' => MeasurementPaymentFinancialService::RULE_UNAVAILABLE_REFUSAL,
    };
    $before = originalDueState($measurement);

    expect(originalDueRefusal(fn () => app(MeasurementWorkflow::class)->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, '1000.00', [
            'financial_rule_id' => $rule->id,
            'financial_justification' => 'Adiantamento.',
        ]),
    ), 'payments.0.financial_rule_id'))->toBe($expected)
        ->and(originalDueState($measurement))->toBe($before);
})->with([
    'limit below the amount',
    'percent limit only (no amount due to apply it to)',
    'paying less only',
    'retired',
    'another emission',
    'not yet effective on the payment date',
]);

it('never measures a percent limit against an amount already paid above what was due (F)', function (string $case) {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $workflow = app(MeasurementWorkflow::class);
    // Já pago a maior em R$ 20.000,00 (coberto por regra), ou já pago
    // R$ 5.000,00 coberto por regra numa linha de aprovado zero: o saldo é
    // negativo, e o que já foi pago não é base de percentual nenhum -- desde a
    // Fase 4B-1, o percentual é do valor aprovado.
    [$measurement, $amount, $percent, $reason] = match ($case) {
        'line paid above the approved amount' => (function () use ($scenario, $workflow): array {
            $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
            $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '120000.00', [
                'financial_rule_id' => originalDueRule($measurement, ['maximum_difference_amount' => '20000.00'])->id,
                'financial_justification' => 'Medição complementar paga junto.',
            ]));

            // 35% do aprovado (R$ 100.000,00) não cobre R$ 40.000,00; dos R$ 120.000,00 já pagos, cobriria.
            return [$measurement, '20000.00', '35.0000', 'A divergência acumulada do empreendimento chega a R$ 40.000,00, acima de 35% do valor aprovado (R$ 100.000,00), isto é, R$ 35.000,00.'];
        })(),
        'second payment on a line approved at zero' => (function () use ($scenario, $workflow): array {
            $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
            $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '5000.00', [
                'financial_rule_id' => originalDueRule($measurement)->id,
                'financial_justification' => 'Adiantamento previsto no contrato.',
            ]));

            return [$measurement, '10000.00', '300.0000', 'O valor aprovado do empreendimento é R$ 0,00: o limite percentual não autoriza divergência sem valor aprovado positivo, e a exceção sobre valor aprovado zero exige uma regra só com limite em reais, sem limite percentual.'];
        })(),
    };
    $percentRule = originalDueRule($measurement, ['direction' => 'both', 'maximum_difference_amount' => null, 'maximum_difference_percent' => $percent]);
    $before = originalDueState($measurement);

    expect(originalDueRefusal(fn () => $workflow->registerPayment(
        $measurement->fresh(),
        $scenario['actor'],
        originalDueRow($scenario, 0, $amount, [
            'financial_rule_id' => $percentRule->id,
            'financial_justification' => 'Complemento pedido pela construtora.',
        ]),
    ), 'payments.0.financial_rule_id'))->toBe("A regra escolhida não cobre este pagamento. {$reason}")
        ->and(originalDueState($measurement))->toBe($before);
})->with([
    'line paid above the approved amount',
    'second payment on a line approved at zero',
]);

it('keeps the rule a zero-due payment was registered under when it is reassessed without one, even after the rule is retired (F)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $rule = originalDueRule($measurement);
    $workflow = app(MeasurementWorkflow::class);
    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '5000.00', [
        'financial_rule_id' => $rule->id,
        'financial_justification' => 'Adiantamento previsto no contrato da emissão.',
    ]));

    $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
        'financial_rule_id' => null,
        'financial_justification' => 'Só a justificativa, conferida de novo.',
    ], (int) $measurement->fresh()->workflow_revision);

    // toEqual: o MySQL reordena as chaves do JSON gravado.
    expect($payment->fresh()->financial_rule_id)->toBe($rule->id)
        ->and($payment->fresh()->financial_assessment['rule'])->toEqual($rule->snapshot())
        ->and($payment->fresh()->financial_assessment['justification'])->toBe('Só a justificativa, conferida de novo.');

    // Encerrada a regra, o pagamento continua reavaliável -- com ela.
    $rule->update(['retired_at' => now()]);

    $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
        'financial_justification' => 'Conferido depois do encerramento da regra.',
    ], (int) $measurement->fresh()->workflow_revision);

    expect($payment->fresh()->financial_rule_id)->toBe($rule->id)
        ->and($payment->fresh()->financial_assessment['justification'])->toBe('Conferido depois do encerramento da regra.');
});

it('keeps on reassessment the rule of a payment whose line a later payment took further, measuring the divergence of its registration (F)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $workflow = app(MeasurementWorkflow::class);
    $small = originalDueRule($measurement);
    $first = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '5000.00', [
        'financial_rule_id' => $small->id,
        'financial_justification' => 'Adiantamento previsto no contrato.',
    ]));
    // O segundo pagamento leva a divergência acumulada da linha a R$ 10.000,00.
    $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '5000.00', [
        'financial_rule_id' => originalDueRule($measurement, ['maximum_difference_amount' => '10000.00'])->id,
        'financial_justification' => 'Segundo adiantamento.',
    ]));

    // Fase 4B-1: a reavaliação mede a exposição do registro do pagamento -- o
    // posterior não a muda --, e a regra atual continua escolhível e mantida.
    $workflow->reassessPayment($first->fresh(), $scenario['actor'], [
        'financial_rule_id' => $small->id,
        'financial_justification' => 'Primeiro adiantamento, com a regra dele.',
    ], (int) $measurement->fresh()->workflow_revision);

    $workflow->reassessPayment($first->fresh(), $scenario['actor'], [
        'financial_justification' => 'Primeiro adiantamento, conferido de novo.',
    ], (int) $measurement->fresh()->workflow_revision);

    expect($first->fresh()->financial_rule_id)->toBe($small->id)
        ->and($first->fresh()->financial_assessment['rule']['id'])->toBe($small->id)
        ->and($first->fresh()->financial_assessment['reference']['divergence_amount'])->toBe('5000.00')
        ->and($first->fresh()->financial_assessment['justification'])->toBe('Primeiro adiantamento, conferido de novo.')
        ->and($first->fresh()->financial_assessment['requires_acceptance'])->toBeTrue();
});

it('keeps on reassessment a rule whose direction or percent limit covered the payment at its registration, even once nothing else is due (C)', function (array $attributes) {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);
    $rule = originalDueRule($measurement, $attributes);
    // R$ 1.000,00 de R$ 100.000,00 devidos: divergência para menos, coberta.
    $first = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '1000.00', [
        'financial_rule_id' => $rule->id,
        'financial_justification' => 'Parcela inicial.',
    ]));
    // O segundo pagamento esgota o valor devido e passa dele em R$ 1.000,00:
    // pela Fase 4B-1, só com regra que cubra o excedente.
    $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '100000.00', [
        'financial_rule_id' => originalDueRule($measurement)->id,
        'financial_justification' => 'Saldo com a parcela inicial a conferir.',
    ]));

    // Fase 4B-1: a reavaliação mede a exposição do registro; o pagamento posterior não tira a regra do primeiro.
    $workflow->reassessPayment($first->fresh(), $scenario['actor'], [
        'financial_justification' => 'Parcela inicial, conferida de novo.',
    ], (int) $measurement->fresh()->workflow_revision);

    expect($first->fresh()->financial_rule_id)->toBe($rule->id)
        ->and($first->fresh()->financial_assessment['rule']['id'])->toBe($rule->id)
        ->and($first->fresh()->financial_assessment['reference']['divergence_amount'])->toBe('-99000.00');
})->with([
    'percent limit, no base without amount due' => [['direction' => 'both', 'maximum_difference_amount' => null, 'maximum_difference_percent' => '99.0000']],
    'only for paying less' => [['direction' => 'under', 'maximum_difference_amount' => '99000.00']],
]);

it('does not read a tampered rule snapshot as a rule when reassessing (F)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $workflow = app(MeasurementWorkflow::class);
    $rule = originalDueRule($measurement);
    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '5000.00', [
        'financial_rule_id' => $rule->id,
        'financial_justification' => 'Adiantamento.',
    ]));
    // Limite reescrito como a tela mostra, e não como a regra grava.
    $assessment = $payment->fresh()->financial_assessment;
    $assessment['rule']['maximum_difference_amount'] = '5.000,00';
    DB::table('measurement_payments')->where('id', $payment->id)->update(['financial_assessment' => json_encode($assessment)]);
    $before = originalDueState($measurement);

    // Fase 4B-1: sem regra legível, o excedente não fica só com a justificativa -- a reavaliação é recusada.
    expect(originalDueRefusal(fn () => $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
        'financial_justification' => 'Conferido de novo.',
    ], (int) $measurement->fresh()->workflow_revision), 'financial_rule_id'))
        ->toBe(originalDueExcessRefusal('Torre A', 'R$ 0,00', 'R$ 0,00', 'R$ 0,00', 'R$ 5.000,00'))
        ->and(originalDueState($measurement))->toBe($before)
        ->and($payment->fresh()->financial_rule_id)->toBe($rule->id);
});

it('keeps the rule of a payment that still had an amount due when reassessed without one (C)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);
    $rule = originalDueRule($measurement, ['maximum_difference_amount' => '1000.00']);
    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '100500.00', [
        'financial_rule_id' => $rule->id,
        'financial_justification' => 'Complemento contratual.',
    ]));

    // Fase 4B-1: em branco, a reavaliação mantém a regra atual enquanto ela cobrir; o excedente nunca perde a regra.
    $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
        'financial_justification' => 'Complemento contratual, conferido de novo.',
    ], (int) $measurement->fresh()->workflow_revision);

    expect($payment->fresh()->financial_rule_id)->toBe($rule->id)
        ->and($payment->fresh()->financial_assessment['rule']['id'])->toBe($rule->id)
        ->and($payment->fresh()->financial_assessment['justification'])->toBe('Complemento contratual, conferido de novo.')
        ->and($payment->fresh()->financial_assessment['requires_acceptance'])->toBeTrue();
});

it('keeps the usual refusal of a non-covering rule where an amount is due (C)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);
    $narrow = originalDueRule($measurement, ['maximum_difference_amount' => '100.00']);
    $usual = 'A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 500,00, acima do limite em reais da regra (R$ 100,00).';

    expect(originalDueRefusal(fn () => $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '100500.00', [
        'financial_rule_id' => $narrow->id,
        'financial_justification' => 'Complemento.',
    ])), 'payments.0.financial_rule_id'))->toBe($usual);

    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '100500.00', [
        'financial_rule_id' => originalDueRule($measurement, ['maximum_difference_amount' => '1000.00'])->id,
        'financial_justification' => 'Complemento.',
    ]));

    // Fase 4B-1: na reavaliação, a recusa aponta a regra atual, que a regra em branco mantém.
    expect(originalDueRefusal(fn () => $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
        'financial_rule_id' => $narrow->id,
        'financial_justification' => 'Outra regra.',
    ], (int) $measurement->fresh()->workflow_revision), 'financial_rule_id'))
        ->toBe($usual.' Escolha uma regra que cubra o pagamento, ou deixe a regra em branco para manter a atual (Adiantamento contratual · versão 1).');
});

it('keeps requiring the support document of the kept rule on reassessment', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $workflow = app(MeasurementWorkflow::class);
    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '5000.00', [
        'financial_rule_id' => originalDueRule($measurement)->id,
        'financial_justification' => 'Adiantamento.',
    ]));
    // Enquadramento gravado sem o documento que a regra exige.
    $assessment = $payment->fresh()->financial_assessment;
    $assessment['rule']['requires_document'] = true;
    DB::table('measurement_payments')->where('id', $payment->id)->update(['financial_assessment' => json_encode($assessment)]);

    expect(originalDueRefusal(fn () => $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
        'financial_justification' => 'Conferido de novo.',
    ], (int) $measurement->fresh()->workflow_revision), 'financial_support'))->toBe('Esta regra exige documento de suporte (PDF, JPG ou PNG).');
});

it('refuses to reassess a zero-due payment under a rule that does not cover it, pointing to the current rule (F)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $workflow = app(MeasurementWorkflow::class);
    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '5000.00', [
        'financial_rule_id' => originalDueRule($measurement)->id,
        'financial_justification' => 'Adiantamento previsto no contrato da emissão.',
    ]));
    $narrow = originalDueRule($measurement, ['name' => 'Ajuste pontual', 'maximum_difference_amount' => '100.00']);
    $assessment = $payment->fresh()->financial_assessment;

    expect(originalDueRefusal(fn () => $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
        'financial_rule_id' => $narrow->id,
        'financial_justification' => 'Outra regra.',
    ], (int) $measurement->fresh()->workflow_revision), 'financial_rule_id'))
        ->toBe('A regra escolhida não cobre este pagamento. A divergência acumulada do empreendimento chega a R$ 5.000,00, acima do limite em reais da regra (R$ 100,00). Escolha uma regra que cubra o pagamento, ou deixe a regra em branco para manter a atual (Adiantamento contratual · versão 1).')
        ->and($payment->fresh()->financial_assessment)->toBe($assessment);
});

it('reassesses a zero-due payment recorded without rule before the requirement only under a rule that existed at its registration (F)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $workflow = app(MeasurementWorkflow::class);
    $rule = originalDueRule($measurement);
    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], originalDueRow($scenario, 0, '5000.00', [
        'financial_rule_id' => $rule->id,
        'financial_justification' => 'Adiantamento.',
    ]));
    // O pagamento de antes da exigência: justificado, sem regra.
    DB::table('measurement_payments')->where('id', $payment->id)->update([
        'financial_rule_id' => null,
        'financial_assessment' => json_encode(['rule' => null] + $payment->fresh()->financial_assessment),
    ]);
    $before = originalDueState($measurement);

    // Fase 4B-1: a justificativa sozinha não autoriza o excedente, nem na reavaliação do pagamento antigo.
    expect(originalDueRefusal(fn () => $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
        'financial_justification' => 'Pagamento antigo, mantido como exceção sem regra.',
    ], (int) $measurement->fresh()->workflow_revision), 'financial_rule_id'))
        ->toBe(originalDueExcessRefusal('Torre A', 'R$ 0,00', 'R$ 0,00', 'R$ 0,00', 'R$ 5.000,00'))
        ->and(originalDueState($measurement))->toBe($before);

    // Com a regra que já existia no registro, o pagamento antigo é enquadrado.
    $workflow->reassessPayment($payment->fresh(), $scenario['actor'], [
        'financial_rule_id' => $rule->id,
        'financial_justification' => 'Pagamento antigo, enquadrado na regra vigente no registro.',
    ], (int) $measurement->fresh()->workflow_revision);

    expect($payment->fresh()->financial_rule_id)->toBe($rule->id)
        ->and($payment->fresh()->financial_assessment['rule']['id'])->toBe($rule->id)
        ->and($payment->fresh()->financial_assessment['justification'])->toBe('Pagamento antigo, enquadrado na regra vigente no registro.');
});

// ── Revisões: a regra própria continua ───────────────────────────────────────

it('leaves the revisions to their own ceiling, with their own refusal', function () {
    $scenario = MeasurementRevisionScenario::plan();
    $may = MeasurementRevisionScenario::finalized($scenario, '2026-05', 10);
    $revision = MeasurementRevisionScenario::effective($scenario, $may, 8, 'Percentual superestimado em maio.');

    $refusal = originalDueRefusal(fn () => MeasurementRevisionScenario::pay($scenario, $revision, '1000.00', justification: 'Ajuste.'));

    expect($refusal)->toStartWith('A família já pagou R$ 20.000,00 a maior em')
        ->and(MeasurementPayment::query()->where('measurement_id', $revision->id)->exists())->toBeFalse()
        // Nada devido e sem regra, mas é revisão: a conferência da original não
        // se aplica a ela.
        ->and(fn () => app(MeasurementPaymentFinancialService::class)->assertOriginalPaymentFits($revision->fresh(), [
            'plan_set_id' => $scenario['planSet']->id,
            'amount' => '1000.00',
        ], 0))->not->toThrow(Throwable::class);
});

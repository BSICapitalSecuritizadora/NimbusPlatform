<?php

use App\Enums\OperationStatus;
use App\Exceptions\MeasurementWorkflowException;
use App\Exceptions\OperationLifecycleException;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use App\Services\OperationLifecycleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementNoDisbursementScenario as Scenario;
use Tests\Support\MeasurementReceiptEvidenceScenario;
use Tests\Support\MeasurementRevisionScenario;

pest()->group('parity');

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * A mensagem da recusa de validação no campo indicado -- lida da exceção, não
 * por `toThrow()`: as mensagens têm dois-pontos.
 */
function noDisbursementValidationMessage(callable $action, string $field): ?string
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors()[$field][0] ?? null;
    }

    return null;
}

function noDisbursementRefusal(callable $action): ?string
{
    try {
        $action();
    } catch (MeasurementWorkflowException $exception) {
        return $exception->getMessage();
    }

    return null;
}

function noDisbursementActivity(Measurement $measurement, string $description, ?int $stage = null): ?Activity
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

// ── Nada a pagar, demonstrado ────────────────────────────────────────────────

it('concludes the Payment stage of an original measurement without disbursement and finalizes it with the acknowledgment', function (array $development, int|string $percent, string $approved, string $zeroReason) {
    $scenario = Scenario::plan([$development]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [$percent]);
    $workflow = app(MeasurementWorkflow::class);
    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement);

    expect($amountDue->isDetermined())->toBeTrue()
        ->and($amountDue->isNothingDue())->toBeTrue()
        ->and($amountDue->refusals())->toBe([])
        ->and($amountDue->lines)->toHaveCount(1)
        ->and($amountDue->lines[0]->fundAmount)->toBe($development['fund'])
        ->and($amountDue->lines[0]->approvedAmount)->toBe($approved)
        ->and($amountDue->lines[0]->remainingAmount)->toBe('0.00')
        ->and($amountDue->lines[0]->zeroReason())->toBe($zeroReason)
        ->and($workflow->hasValidPayment($measurement))->toBeTrue()
        ->and($workflow->canConcludeWithoutDisbursement($measurement, $scenario['actor']))->toBeTrue();

    $measurement = Scenario::concludeWithoutDisbursement($scenario, $measurement);

    // Nenhum pagamento -- nem de valor zero -- e nada a esperar de comprovante.
    expect($measurement->status)->toBe('approved')
        ->and($measurement->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION)
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(app(MeasurementReceiptEvidenceService::class)->allPaymentsApproved($measurement))->toBeTrue()
        ->and($workflow->requiresNoDisbursementAcknowledgment($measurement))->toBeTrue()
        ->and($workflow->noDisbursementDecision($measurement)['justification'])->toBe(Scenario::JUSTIFICATION);

    $measurement = Scenario::finalizeAcknowledging($scenario, $measurement);

    expect($measurement->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and($workflow->noDisbursementAcknowledgment($measurement)['acknowledged'])->toBe([
            'no_payment_due' => true,
            'no_bank_payment_registered' => true,
            'payment_stage_justification_reviewed' => true,
        ]);
})->with([
    'original at 0% with a valid fund (A)' => [['fund' => '1000000.00'], 0, '0.00', 'no_progress'],
    'explicitly valid zero Construction Fund (D)' => [['fund' => '0.00'], 10, '0.00', 'zero_fund'],
    'amount rounded to zero cents' => [['fund' => '0.10'], 1, '0.00', 'rounded_to_zero'],
]);

it('concludes without disbursement a competence already covered by the immutable initial physical progress (B)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00', 'initial' => '35.00', 'reference_date' => '2026-05-31']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $entry = $measurement->engineering_snapshot['plan_sets'][0];

    expect($entry['realized_monthly_percent'])->toBe('0.00')
        ->and($entry['plan_initial_physical_progress_percent'])->toBe('35.00')
        ->and(app(MeasurementPaymentFinancialService::class)->amountDue($measurement)->isNothingDue())->toBeTrue();

    $measurement = Scenario::finalizeAcknowledging($scenario, Scenario::concludeWithoutDisbursement($scenario, $measurement));

    expect($measurement->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and($scenario['planSets'][0]->fresh()->initial_physical_progress_percent)->toBe('35.00');
});

it('concludes without disbursement a 0% month of a construction already at 100% physical progress (C)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00', 'initial' => '100.00', 'reference_date' => '2026-04-30']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $entry = $measurement->engineering_snapshot['plan_sets'][0];

    expect($entry['realized_cumulative_percent'])->toBe('100.00')
        ->and(app(MeasurementPaymentFinancialService::class)->amountDue($measurement)->isNothingDue())->toBeTrue();

    $measurement = Scenario::finalizeAcknowledging($scenario, Scenario::concludeWithoutDisbursement($scenario, $measurement));

    expect($measurement->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

it('takes one decision for a measurement whose constructions all have nothing due (F)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00'], ['fund' => '0.00'], ['fund' => '0.10']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0, 25, 1]);
    $measurement = Scenario::concludeWithoutDisbursement($scenario, $measurement);
    $decision = noDisbursementActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT);

    expect($decision->properties['no_disbursement']['amount_due']['lines'])->toHaveCount(3)
        ->and(collect($decision->properties['no_disbursement']['amount_due']['lines'])->pluck('zero_reason')->all())
        ->toBe(['no_progress', 'zero_fund', 'rounded_to_zero'])
        ->and(collect($decision->properties['no_disbursement']['amount_due']['lines'])->pluck('remaining_amount')->unique()->all())->toBe(['0.00']);

    expect(Scenario::finalizeAcknowledging($scenario, $measurement)->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

// ── O que nunca é "nada a pagar" ─────────────────────────────────────────────

it('never treats a missing Construction Fund as zero (E)', function (array $developments, array $percents, string $reason) {
    $scenario = Scenario::plan($developments);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', $percents);
    $workflow = app(MeasurementWorkflow::class);
    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement);

    expect($amountDue->isDetermined())->toBeFalse()
        ->and($amountDue->isNothingDue())->toBeFalse()
        ->and(implode(' ', $amountDue->refusals()))->toContain($reason)
        ->and($workflow->hasValidPayment($measurement))->toBeFalse()
        ->and($workflow->canConcludeWithoutDisbursement($measurement, $scenario['actor']))->toBeFalse()
        ->and(noDisbursementValidationMessage(fn () => Scenario::concludeWithoutDisbursement($scenario, $measurement), 'payments'))
        ->toContain('A etapa Pagamento não pode ser concluída sem desembolso')
        ->toContain($reason)
        // Sem conta, o caminho é corrigir a origem dela, não pagar para contorná-la.
        // Fase 4B-1: o Fundo ausente na versão congelada não se corrige pelo fluxo da medição.
        ->toContain('corrija o registro da Engenharia devolvendo a medição àquela etapa')
        ->toContain('peça ao administrador a correção do cadastro do plano')
        ->not->toContain('Registre o pagamento devido')
        ->and(noDisbursementValidationMessage(fn () => $workflow->approve($measurement->fresh(), $scenario['actor'], 'Sem pagamento.'), 'payments'))
        ->toBe('Cadastre ao menos um pagamento válido antes de aprovar a etapa Pagamento.');

    expect($measurement->fresh()->only(['status', 'current_stage']))->toBe(['status' => 'awaiting_payment', 'current_stage' => MeasurementWorkflow::STAGE_PAYMENT])
        ->and(noDisbursementActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toBeNull()
        ->and(MeasurementPayment::query()->count())->toBe(0);
})->with([
    'single construction without fund' => [[['fund' => null]], [0], 'Torre A: sem Fundo de Obra'],
    'zero-due construction next to one without fund' => [[['fund' => '1000000.00'], ['fund' => null]], [0, 0], 'Torre B: sem Fundo de Obra'],
]);

it('refuses the zero-payment exception when the engineering record does not support the account', function (Closure $corrupt, string $reason) {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $snapshot = $measurement->engineering_snapshot;
    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($corrupt($snapshot))]);
    $measurement->refresh();

    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement);

    expect($amountDue->isNothingDue())->toBeFalse()
        ->and(implode(' ', $amountDue->refusals()))->toContain($reason)
        ->and(app(MeasurementWorkflow::class)->hasValidPayment($measurement))->toBeFalse();
})->with([
    'percent missing (the reconciliation would read 0%)' => [function (array $snapshot): array {
        unset($snapshot['plan_sets'][0]['realized_monthly_percent']);

        return $snapshot;
    }, 'percentual realizado do mês ausente ou ilegível'],
    'percent unreadable' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['realized_monthly_percent'] = 'zero';

        return $snapshot;
    }, 'percentual realizado do mês ausente ou ilegível'],
    'fund unreadable' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['construction_fund_amount'] = 'abc';

        return $snapshot;
    }, 'Fundo de Obra ilegível'],
    'negative fund' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['construction_fund_amount'] = '-10.00';

        return $snapshot;
    }, 'Fundo de Obra ilegível'],
    'fund with thousands separator (read differently by each parser)' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['construction_fund_amount'] = '1.000';

        return $snapshot;
    }, 'Fundo de Obra ilegível'],
    'fund with currency mask' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['construction_fund_amount'] = 'R$ 1.000,00';

        return $snapshot;
    }, 'Fundo de Obra ilegível'],
    'percent with decimal comma' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['realized_monthly_percent'] = '0,00';

        return $snapshot;
    }, 'percentual realizado do mês ausente ou ilegível'],
    'percent above 100' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['realized_monthly_percent'] = '100.01';

        return $snapshot;
    }, 'percentual realizado do mês ausente ou ilegível'],
    'plan version missing' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['plan_version_id'] = null;

        return $snapshot;
    }, 'sem versão do plano congelada'],
    'construction without identification' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['plan_set_id'] = 0;

        return $snapshot;
    }, 'empreendimento sem identificação'],
    'construction repeated' => [function (array $snapshot): array {
        $snapshot['plan_sets'][] = $snapshot['plan_sets'][0];

        return $snapshot;
    }, 'aparece mais de uma vez'],
    'record of another measurement' => [function (array $snapshot): array {
        $snapshot['measurement_id'] = 999999;

        return $snapshot;
    }, 'não está disponível ou não é verificável'],
    'legacy record without schema' => [function (array $snapshot): array {
        unset($snapshot['schema_version']);

        return $snapshot;
    }, 'não está disponível ou não é verificável'],
    'no construction at all' => [function (array $snapshot): array {
        $snapshot['plan_sets'] = [];

        return $snapshot;
    }, 'não está disponível ou não é verificável'],
    'construction outside the operation' => [function (array $snapshot): array {
        $snapshot['plan_sets'][0]['plan_set_id'] = 999999;

        return $snapshot;
    }, 'contexto aprovado pela Engenharia não está mais disponível'],
]);

it('keeps a real obligation of another construction mandatory and pays it normally (G)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00'], ['fund' => '1200000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0, 10]);
    $workflow = app(MeasurementWorkflow::class);
    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement);

    expect($amountDue->isDetermined())->toBeTrue()
        ->and($amountDue->isNothingDue())->toBeFalse()
        ->and($amountDue->refusals())->toBe(['Torre B: valor devido R$ 120.000,00.'])
        ->and($workflow->canConcludeWithoutDisbursement($measurement, $scenario['actor']))->toBeFalse()
        ->and(noDisbursementValidationMessage(fn () => Scenario::concludeWithoutDisbursement($scenario, $measurement), 'payments'))
        ->toBe('A etapa Pagamento não pode ser concluída sem desembolso: Torre B: valor devido R$ 120.000,00. Registre o pagamento devido.')
        ->and(noDisbursementValidationMessage(fn () => $workflow->approve($measurement->fresh(), $scenario['actor'], 'Sem pagamento.'), 'payments'))
        ->toBe('Cadastre ao menos um pagamento válido antes de aprovar a etapa Pagamento.');

    // O pagamento da Torre B segue o caminho de sempre: comprovante e
    // Finalização sem ciência de ausência de pagamento.
    $payment = $workflow->registerPayment($measurement->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][1]->id,
        'pay_date' => '2026-06-10',
        'amount' => '120000.00',
        'method' => 'TED',
        'receipt' => MeasurementReceiptEvidenceScenario::file(),
    ]);

    expect(noDisbursementValidationMessage(fn () => Scenario::concludeWithoutDisbursement($scenario, $measurement), 'payments'))
        ->toBe('Há pagamento registrado nesta medição: aprove a etapa Pagamento com ele. A conclusão sem desembolso vale só para a medição sem nenhum pagamento.');

    $workflow->approve($measurement->fresh(), $scenario['actor']);

    expect($measurement->fresh()->status)->toBe('awaiting_receipt')
        ->and($workflow->requiresNoDisbursementAcknowledgment($measurement->fresh()))->toBeFalse();

    // Fase 4B-1: quem envia o comprovante não o aprova -- o Finalizador confere e finaliza.
    MeasurementRevisionScenario::receiptsApproved(['actor' => $scenario['actor'], 'finalizer' => $scenario['finalizer']], $measurement);
    $workflow->finalize($measurement->fresh(), $scenario['finalizer']);

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->pluck('id')->all())->toBe([$payment->id])
        ->and(noDisbursementActivity($measurement, 'measurement_finalized')->properties->has('no_disbursement_acknowledged'))->toBeFalse();
});

it('completes with one payment for the payable construction and none for the zero-due one, without duplicates (H)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00'], ['fund' => '1200000.00']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0, 10]);
    $workflow = app(MeasurementWorkflow::class);
    $workflow->registerPayment($measurement->fresh(), $scenario['actor'], [
        'plan_set_id' => $scenario['planSets'][1]->id,
        'pay_date' => '2026-06-10',
        'amount' => '120000.00',
        'receipt' => MeasurementReceiptEvidenceScenario::file(),
    ]);
    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement->fresh());

    // Paga a Torre B, nada mais é devido -- e a Torre A não pede justificativa
    // nem aceite: o valor aprovado dela é zero.
    expect($amountDue->isNothingDue())->toBeTrue()
        ->and(app(MeasurementPaymentFinancialService::class)->unpaidRequiredPlanSets($measurement->fresh()))->toBe([]);

    $workflow->approve($measurement->fresh(), $scenario['actor']);
    // Fase 4B-1: quem envia o comprovante não o aprova -- o Finalizador confere e finaliza.
    MeasurementRevisionScenario::receiptsApproved(['actor' => $scenario['actor'], 'finalizer' => $scenario['finalizer']], $measurement);
    $workflow->finalize($measurement->fresh(), $scenario['finalizer']);

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(1);
});

it('refuses to finalize a payable original measurement that has no payment (I)', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [10]);
    $workflow = app(MeasurementWorkflow::class);

    // Na etapa Pagamento, a Finalização não existe.
    expect(noDisbursementRefusal(fn () => $workflow->finalize($measurement->fresh(), $scenario['finalizer'], acknowledgeNoDisbursement: true)))
        ->toBe('A etapa desta medição foi alterada por outra ação. Atualize a página.');

    // Estado forçado: a medição na Finalização sem pagamento e com valor devido.
    DB::table('measurements')->where('id', $measurement->id)->update(['status' => 'approved', 'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION]);
    DB::table('measurement_reviews')->where('measurement_id', $measurement->id)->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->update(['status' => 'approved', 'notes' => 'Forçado.']);

    // A decisão é conferida antes dos comprovantes: sem pagamento não há
    // comprovante a pedir, e a recusa certa é a da falta da decisão.
    expect(noDisbursementRefusal(fn () => $workflow->finalize($measurement->fresh(), $scenario['finalizer'], acknowledgeNoDisbursement: true)))
        ->toBe(MeasurementWorkflow::NO_DISBURSEMENT_DECISION_MISSING)
        ->and($measurement->fresh()->status)->toBe('approved');
});

it('refuses a zero-due original at Finalization that has no recorded no-disbursement decision', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);

    // Estado forçado, sem a decisão na trilha protegida: a nota da etapa sozinha
    // não autoriza a Finalização.
    DB::table('measurements')->where('id', $measurement->id)->update(['status' => 'approved', 'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION]);
    DB::table('measurement_reviews')->where('measurement_id', $measurement->id)->where('stage', MeasurementWorkflow::STAGE_PAYMENT)->update(['status' => 'approved', 'notes' => 'Sem desembolso.']);

    expect(noDisbursementRefusal(fn () => Scenario::finalizeAcknowledging($scenario, $measurement)))
        ->toBe(MeasurementWorkflow::NO_DISBURSEMENT_DECISION_MISSING)
        ->and($measurement->fresh()->status)->toBe('approved');
});

it('accepts a record approved before plan versioning when its fund matches the version of the measurement file', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $snapshot = $measurement->engineering_snapshot;
    // O registro aprovado antes da Fase 2 não trazia a versão do plano.
    unset($snapshot['plan_sets'][0]['plan_version_id'], $snapshot['plan_sets'][0]['plan_version_number']);
    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($snapshot)]);
    $measurement->refresh();

    expect(app(MeasurementPaymentFinancialService::class)->amountDue($measurement)->isNothingDue())->toBeTrue();

    $measurement = Scenario::finalizeAcknowledging($scenario, Scenario::concludeWithoutDisbursement($scenario, $measurement));

    expect($measurement->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

it('refuses a record approved before plan versioning whose fund does not match the version of the measurement file', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $snapshot = $measurement->engineering_snapshot;
    unset($snapshot['plan_sets'][0]['plan_version_id'], $snapshot['plan_sets'][0]['plan_version_number']);
    $snapshot['plan_sets'][0]['construction_fund_amount'] = '999.00';
    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($snapshot)]);
    $measurement->refresh();
    $amountDue = app(MeasurementPaymentFinancialService::class)->amountDue($measurement);

    expect($amountDue->isNothingDue())->toBeFalse()
        ->and(implode(' ', $amountDue->refusals()))->toContain('anterior ao versionamento do plano, não confere com a versão do plano do arquivo da medição')
        ->and(app(MeasurementWorkflow::class)->canConcludeWithoutDisbursement($measurement, $scenario['actor']))->toBeFalse();
});

it('refuses the Finalization with the position message when the amount due stopped being zero after the decision', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));
    $snapshot = $measurement->engineering_snapshot;
    $snapshot['plan_sets'][0]['realized_monthly_percent'] = '10.00';
    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($snapshot)]);

    // Nem a mensagem dos comprovantes -- não há pagamento --, nem a da cobertura:
    // a decisão não vale para a posição de agora.
    expect(noDisbursementRefusal(fn () => Scenario::finalizeAcknowledging($scenario, $measurement)))
        ->toBe(MeasurementWorkflow::NO_DISBURSEMENT_POSITION_CHANGED)
        ->and($measurement->fresh()->status)->toBe('approved');
});

it('tells the Payment Manager that the zero-due original waits for the decision, not for a payment registration', function () {
    $scenario = Scenario::plan();
    Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $payableScenario = Scenario::plan();
    Scenario::awaitingPayment($payableScenario, '2026-05', [10]);

    Notification::assertSentTo(
        $scenario['actor'],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'awaiting_payment_without_disbursement',
    );
    Notification::assertNotSentTo(
        $scenario['actor'],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'awaiting_payment',
    );
    Notification::assertSentTo(
        $payableScenario['actor'],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'awaiting_payment',
    );
});

// ── Justificativa e ciência ──────────────────────────────────────────────────

it('requires the justification of the no-disbursement decision (J)', function (?string $justification) {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);

    expect(noDisbursementValidationMessage(fn () => Scenario::concludeWithoutDisbursement($scenario, $measurement, $justification), 'notes'))
        ->toBe(MeasurementWorkflow::NO_DISBURSEMENT_JUSTIFICATION_REQUIRED)
        ->and($measurement->fresh()->only(['status', 'current_stage', 'workflow_revision']))->toBe($measurement->only(['status', 'current_stage', 'workflow_revision']))
        ->and(noDisbursementActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['   '],
    'invisible characters only' => ["\u{00A0}\u{200B}\t\u{2003}"],
]);

it('never approves a zero-due original by an ordinary approval, even with a note', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);

    expect(noDisbursementValidationMessage(fn () => app(MeasurementWorkflow::class)->approve($measurement->fresh(), $scenario['actor'], 'Aprovada.'), 'payments'))
        ->toBe(MeasurementWorkflow::NO_DISBURSEMENT_DECISION_REQUIRED)
        ->and($measurement->fresh()->status)->toBe('awaiting_payment');
});

it('refuses the Finalization without the acknowledgment of the absence of payment (K)', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));
    $workflow = app(MeasurementWorkflow::class);

    // O aceite das divergências financeiras não substitui a ciência.
    expect(noDisbursementValidationMessage(fn () => $workflow->finalize($measurement->fresh(), $scenario['finalizer']), 'acknowledge_no_disbursement'))
        ->toBe(MeasurementWorkflow::NO_DISBURSEMENT_ACKNOWLEDGMENT_REQUIRED)
        ->and(noDisbursementValidationMessage(fn () => $workflow->finalize($measurement->fresh(), $scenario['finalizer'], acceptFinancialExceptions: true), 'acknowledge_no_disbursement'))
        ->toBe(MeasurementWorkflow::NO_DISBURSEMENT_ACKNOWLEDGMENT_REQUIRED)
        ->and($measurement->fresh()->status)->toBe('approved')
        ->and(noDisbursementActivity($measurement, 'measurement_finalized'))->toBeNull();
});

it('asks for a new decision after the Finalization returns the measurement to the Payment stage', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]), 'Primeira decisão.');
    $workflow = app(MeasurementWorkflow::class);
    $workflow->returnToStage($measurement->fresh(), $scenario['finalizer'], MeasurementWorkflow::STAGE_PAYMENT, 'Rever a decisão.');

    expect($measurement->fresh()->status)->toBe('awaiting_payment')
        ->and($workflow->noDisbursementDecision($measurement->fresh()))->toBeNull();

    $measurement = Scenario::concludeWithoutDisbursement($scenario, $measurement, 'Segunda decisão.');
    $measurement = Scenario::finalizeAcknowledging($scenario, $measurement);
    $acknowledgment = noDisbursementActivity($measurement, 'measurement_finalized')->properties['no_disbursement_acknowledged'];

    expect($acknowledgment['justification'])->toBe('Segunda decisão.')
        ->and($acknowledgment['decision_activity_id'])->toBe(noDisbursementActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)->id);
});

it('refuses the acknowledgment when the engineering record changed after the decision', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));
    $snapshot = $measurement->engineering_snapshot;
    // Campo que a conferência da cobertura não relê: só o hash canônico da
    // decisão percebe a troca.
    $snapshot['plan_sets'][0]['construction_name'] = 'Nome trocado depois da decisão';
    DB::table('measurements')->where('id', $measurement->id)->update(['engineering_snapshot' => json_encode($snapshot)]);

    expect(noDisbursementRefusal(fn () => Scenario::finalizeAcknowledging($scenario, $measurement)))
        ->toBe(MeasurementWorkflow::NO_DISBURSEMENT_POSITION_CHANGED)
        ->and($measurement->fresh()->status)->toBe('approved');
});

// ── Comportamento histórico ──────────────────────────────────────────────────

it('keeps original measurements with genuine payments on the ordinary path (L)', function () {
    $scenario = MeasurementRevisionScenario::plan();
    $measurement = MeasurementRevisionScenario::awaitingPayment($scenario, '2026-05', 10);
    $workflow = app(MeasurementWorkflow::class);

    expect($workflow->canConcludeWithoutDisbursement($measurement, $scenario['actor']))->toBeFalse()
        ->and(noDisbursementValidationMessage(fn () => $workflow->approve($measurement->fresh(), $scenario['actor']), 'payments'))
        ->toBe('Cadastre ao menos um pagamento válido antes de aprovar a etapa Pagamento.');

    MeasurementRevisionScenario::pay($scenario, $measurement, MeasurementRevisionScenario::expected(10));
    // A ciência pedida por engano a uma medição paga não é gravada.
    $workflow->approve($measurement->fresh(), $scenario['actor']);
    MeasurementRevisionScenario::receiptsApproved($scenario, $measurement);
    $workflow->finalize($measurement->fresh(), $scenario['finalizer'], acknowledgeNoDisbursement: true);

    expect($measurement->fresh()->status)->toBe('finalized')
        ->and(noDisbursementActivity($measurement, 'measurement_finalized')->properties->has('no_disbursement_acknowledged'))->toBeFalse()
        ->and(noDisbursementActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT)->properties->has('no_disbursement'))->toBeFalse();
});

it('keeps revisions with nothing to pay on the Phase 3 path, without the original-measurement decision (L)', function () {
    $scenario = MeasurementRevisionScenario::plan();
    $may = MeasurementRevisionScenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = MeasurementRevisionScenario::effective($scenario, $may, 0);
    $workflow = app(MeasurementWorkflow::class);

    expect(app(MeasurementRevisionService::class)->allowsApprovalWithoutOwnPayment($revision))->toBeTrue()
        ->and($workflow->hasValidPayment($revision))->toBeTrue()
        ->and($workflow->canConcludeWithoutDisbursement($revision, $scenario['actor']))->toBeFalse()
        ->and(noDisbursementValidationMessage(fn () => Scenario::concludeWithoutDisbursement($scenario, $revision), 'payments'))
        ->toBe('A conclusão sem desembolso é da medição original. A revisão termina a etapa Pagamento pela posição financeira da família: aprove a etapa.');

    $finalized = MeasurementRevisionScenario::closePaymentAndFinalize($scenario, $revision);

    expect($finalized->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

it('lets a revision whose family already paid finish without paying again (H, revision)', function () {
    $scenario = MeasurementRevisionScenario::plan();
    $may = MeasurementRevisionScenario::finalized($scenario, '2026-05', 10);
    $revision = MeasurementRevisionScenario::effective($scenario, $may, 10);

    expect(app(MeasurementWorkflow::class)->hasValidPayment($revision))->toBeTrue()
        ->and(MeasurementRevisionScenario::closePaymentAndFinalize($scenario, $revision)->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(1);
});

// ── Encerramento da operação e trilha ────────────────────────────────────────

it('does not block the Operation closure once the zero-disbursement original is finalized (M)', function () {
    $scenario = Scenario::plan();
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0]);
    $admin = makeAdminUser();

    expect(fn () => app(OperationLifecycleService::class)->complete($scenario['operation']->fresh(), $admin))
        ->toThrow(OperationLifecycleException::class);

    Scenario::finalizeAcknowledging($scenario, Scenario::concludeWithoutDisbursement($scenario, $measurement));

    expect(app(OperationLifecycleService::class)->complete($scenario['operation']->fresh(), $admin)->status)->toBe(OperationStatus::Completed);
});

it('reconstructs the whole no-disbursement decision from the protected audit trail (N)', function () {
    $scenario = Scenario::plan([['fund' => '1000000.00', 'name' => 'Torre Alfa'], ['fund' => '0.00', 'name' => 'Torre Beta']]);
    $measurement = Scenario::awaitingPayment($scenario, '2026-05', [0, 15]);
    $measurement = Scenario::concludeWithoutDisbursement($scenario, $measurement);
    $measurement = Scenario::finalizeAcknowledging($scenario, $measurement);
    $decision = noDisbursementActivity($measurement, 'measurement_stage_approved', MeasurementWorkflow::STAGE_PAYMENT);
    $finalization = noDisbursementActivity($measurement, 'measurement_finalized');
    $snapshotHash = MeasurementRevisionService::canonicalSnapshotHash($measurement->engineering_snapshot);

    expect(config('audit.protected_logs'))->toContain('measurement_workflow')
        ->and($decision->causer_id)->toBe($scenario['actor']->id)
        ->and($decision->properties['from_status'])->toBe('awaiting_payment')
        ->and($decision->properties['to_status'])->toBe('approved')
        ->and($decision->properties['responsibility'])->toBe('payment_manager_user_id')
        ->and($decision->properties['delegated'])->toBeFalse()
        ->and($decision->properties['admin_override'])->toBeFalse()
        ->and($decision->properties['notes'])->toBe(Scenario::JUSTIFICATION)
        ->and($decision->properties['no_disbursement']['decision'])->toBe('no_disbursement')
        ->and($decision->properties['no_disbursement']['justification'])->toBe(Scenario::JUSTIFICATION)
        ->and($decision->properties['no_disbursement']['payments_registered'])->toBe(0)
        ->and($decision->properties['no_disbursement']['amount_due']['nothing_due'])->toBeTrue()
        ->and($decision->properties['no_disbursement']['amount_due']['total_remaining_amount'])->toBe('0.00')
        ->and($decision->properties['no_disbursement']['amount_due']['engineering_snapshot_sha256'])->toBe($snapshotHash)
        ->and(collect($decision->properties['no_disbursement']['amount_due']['lines'])->map(fn (array $line): array => [
            $line['label'], $line['fund_amount'], $line['realized_monthly_percent'], $line['approved_amount'], $line['remaining_amount'],
        ])->all())->toBe([
            ['Torre Alfa', '1000000.00', '0.00', '0.00', '0.00'],
            ['Torre Beta', '0.00', '15.00', '0.00', '0.00'],
        ])
        ->and($finalization->causer_id)->toBe($scenario['finalizer']->id)
        ->and($finalization->properties['responsibility'])->toBe('payment_finalizer_user_id')
        ->and($finalization->properties['no_disbursement_acknowledged']['decision_activity_id'])->toBe($decision->id)
        ->and($finalization->properties['no_disbursement_acknowledged']['justification'])->toBe(Scenario::JUSTIFICATION)
        ->and($finalization->properties['no_disbursement_acknowledged']['decided_by'])->toBe($scenario['actor']->id)
        // Fase 4B-1: quem decidiu sem desembolso não dá a ciência; a chave fica, sempre falsa.
        ->and($finalization->properties['no_disbursement_acknowledged']['same_actor_as_decision'])->toBeFalse()
        ->and($finalization->properties['no_disbursement_acknowledged']['decision_delegated'])->toBeFalse()
        ->and($finalization->properties['no_disbursement_acknowledged']['decision_admin_override'])->toBeFalse()
        ->and($finalization->properties['no_disbursement_acknowledged']['payments_registered'])->toBe(0)
        ->and($finalization->properties['no_disbursement_acknowledged']['amount_due']['engineering_snapshot_sha256'])->toBe($snapshotHash)
        ->and($finalization->properties['no_disbursement_acknowledged']['acknowledged'])->toBe([
            'no_payment_due' => true,
            'no_bank_payment_registered' => true,
            'payment_stage_justification_reviewed' => true,
        ]);

    // Os dois registros vivem na trilha protegida: a limpeza descartável não os alcança.
    Activity::query()->whereKey([$decision->id, $finalization->id])->update(['created_at' => now()->subDays(400)]);
    $this->artisan('audit:clean-filtered')->assertSuccessful();

    expect(Activity::query()->whereKey([$decision->id, $finalization->id])->count())->toBe(2);
});

it('notifies the Finalizer that the Payment stage ended without disbursement', function () {
    $scenario = Scenario::plan();
    Scenario::concludeWithoutDisbursement($scenario, Scenario::awaitingPayment($scenario, '2026-05', [0]));

    Notification::assertSentTo(
        $scenario['finalizer'],
        MeasurementWorkflowNotification::class,
        fn (MeasurementWorkflowNotification $notification): bool => $notification->event === 'ready_to_finalize_without_disbursement',
    );
});

<?php

use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
use App\Enums\AccessPermission;
use App\Enums\MeasurementRevisionAdjustmentStatus;
use App\Enums\MeasurementRevisionDifferenceType;
use App\Models\Measurement;
use App\Models\MeasurementFinancialRule;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\MeasurementRevisionDifference;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPaymentFinancialService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPlanVersionFixture;
use Tests\Support\MeasurementRevisionScenario as Scenario;

uses(RefreshDatabase::class);
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * A posição única do empreendimento do cenário, recalculada agora.
 */
function revisionExposurePosition(Measurement $revision): MeasurementRevisionPlanSetPosition
{
    $positions = app(MeasurementRevisionService::class)->positions($revision->fresh());

    expect($positions)->toHaveCount(1);

    return $positions[0];
}

/**
 * A mensagem de validação de um campo, lida da exceção: as mensagens têm
 * dois-pontos, e o `toThrow` compara o texto resumido da exceção.
 */
function revisionExposureMessage(callable $attempt, string $field): string
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return (string) collect($exception->errors()[$field] ?? [])->first();
    }

    throw new RuntimeException("A tentativa não foi recusada no campo {$field}.");
}

/**
 * Os eventos de uma medição na trilha protegida do fluxo.
 *
 * @return Builder<Activity>
 */
function revisionExposureAudits(Measurement $subject, string $event): Builder
{
    return Activity::query()
        ->where('log_name', 'measurement_workflow')
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->where('description', $event);
}

/**
 * Os reconhecimentos que a tela da revisão lista: rótulo, escopo e decisão.
 *
 * @return list<array{0: string, 1: string, 2: string|null}>
 */
function revisionExposureAcknowledgments(Measurement $revision): array
{
    return collect(app(MeasurementRevisionService::class)->overview($revision->fresh())['acknowledgments'])
        ->map(fn (array $record): array => [$record['label'], $record['scope'], $record['decision']])
        ->values()
        ->all();
}

/**
 * Regra financeira da emissão da operação do cenário, para qualquer
 * empreendimento: tolera até R$ 10.000,00 acima do saldo.
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function revisionExposureOverpaymentRule(array $scenario): MeasurementFinancialRule
{
    return MeasurementFinancialRule::factory()->create([
        'emission_id' => $scenario['operation']->emission_id,
        'direction' => 'over',
        'maximum_difference_amount' => '10000.00',
        'maximum_difference_percent' => null,
        'created_by' => $scenario['actor']->id,
    ]);
}

/**
 * A R0 aprovada em R$ 100.000,00 (10%) e paga em R$ 60.000,00 -- abaixo do
 * esperado, com a divergência justificada --, finalizada com o aceite do
 * Finalizador (caso A) ou parada na Finalização com a documentação completa
 * (caso C).
 *
 * @param  array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}  $scenario
 */
function revisionExposureUnderpaidSource(array $scenario, string $case): Measurement
{
    $justification = 'Retenção contratual de 40% até a vistoria da obra.';

    if ($case === 'paid_awaiting_finalization') {
        return Scenario::paidAwaitingFinalization($scenario, '2026-05', 10, '60000.00', $justification);
    }

    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    Scenario::pay($scenario, $may, '60000.00', justification: $justification);

    return Scenario::closePaymentAndFinalize($scenario, $may, acceptFinancialExceptions: true);
}

/**
 * O cenário de {@see Scenario::plan()} com o plano sem Fundo de Obra: a V1
 * nasce sem fundo e entra em vigor assim, como a ativação aceita quando nenhuma
 * versão o teve. A Engenharia aprova sem referência financeira, e o pagamento
 * não tem valor esperado com que comparar.
 *
 * @return array{actor: User, operation: Operation, planSet: MeasurementPlanSet, lines: array<string, MeasurementPlanLine>}
 */
function revisionExposurePlanWithoutFund(): array
{
    config()->set('filesystems.private_disk', 'local');

    $actor = User::factory()->withTwoFactor()->create();
    $actor->givePermissionTo([
        'measurements.view', 'measurements.create', 'measurements.update', 'measurements.review', 'measurements.pay',
        'measurements.receipts', 'measurements.finalize', AccessPermission::MeasurementsRevise->value,
    ]);
    $operation = Operation::factory()->create([
        'status' => 'active',
        'assigned_user_id' => $actor->id,
        'responsible_user_id' => $actor->id,
        'stage2_reviewer_user_id' => $actor->id,
        'stage3_reviewer_user_id' => $actor->id,
        'payment_manager_user_id' => $actor->id,
        'payment_receipt_uploader_user_id' => $actor->id,
        'payment_finalizer_user_id' => $actor->id,
    ]);
    $planSet = MeasurementPlanSet::factory()->default()->withConstructionFund(null)->create([
        'operation_id' => $operation->id,
        'initial_incurred_amount' => '0.00',
        'initial_physical_progress_percent' => '0.00',
        'initial_physical_progress_reference_date' => null,
    ]);
    $lines = [];

    foreach (['2026-05', '2026-06'] as $index => $month) {
        $lines[$month] = MeasurementPlanLine::factory()->create([
            'operation_id' => $operation->id,
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

    return compact('actor', 'operation', 'planSet', 'lines');
}

it('keeps the overpayment of a downward revision open after acknowledging it at Finalization, without touching the payment', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', 'America/Sao_Paulo'));
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::finalized($scenario, '2026-05', 50);
    $paymentBefore = $may->payments()->sole()->only(['id', 'measurement_id', 'plan_set_id', 'amount', 'pay_date', 'financial_assessment']);
    $revision = Scenario::effective($scenario, $may, 47);
    $identified = revisionExposurePosition($revision);

    expect($identified->revisedApprovedAmount)->toBe('470000.00')
        ->and($identified->financialDifferenceAmount)->toBe('-30000.00')
        ->and($identified->historicalPaidAmount)->toBe('500000.00')
        ->and($identified->familyPaidAmount)->toBe('500000.00')
        ->and($identified->outstandingOverpaymentAmount)->toBe('30000.00')
        ->and($identified->remainingPayableAmount)->toBe('0.00')
        ->and($identified->previouslyAcknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($identified->unacknowledgedOverpaymentAmount)->toBe('30000.00')
        ->and($identified->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPendingDecision)
        ->and($identified->adjustmentStatus->isIdentified())->toBeTrue()
        ->and($identified->adjustmentStatus->isAcknowledged())->toBeFalse();

    expect(revisionExposureMessage(fn () => Scenario::pay($scenario, $revision, '1000.00'), 'payments.0.amount'))
        ->toContain('já pagou R$ 30.000,00 a maior', 'nenhum novo pagamento é registrado enquanto houver valor pago a maior em aberto')
        ->and(revisionExposureMessage(fn () => $workflow->approve($revision->fresh(), $scenario['actor']), 'notes'))
        ->toContain('Registre a decisão financeira sobre o valor pago a maior');

    $workflow->approve($revision->fresh(), $scenario['actor'], 'Valor pago a maior será cobrado da construtora fora do sistema.');
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'America/Sao_Paulo'));

    expect(app(MeasurementPaymentFinancialService::class)->requiresAcceptance($revision->fresh()))->toBeTrue()
        ->and(revisionExposureMessage(fn () => $workflow->finalize($revision->fresh(), $scenario['actor']), 'accept_financial_exceptions'))
        ->toContain('valor pago a maior desta revisão')
        ->and($revision->fresh()->status)->toBe('approved');

    $workflow->finalize($revision->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    $acknowledged = revisionExposurePosition($revision);

    // Reconhecer não é regularizar: o valor continua em aberto, e o pagamento
    // da R0 continua exatamente como foi registrado.
    expect($revision->fresh()->status)->toBe('finalized')
        ->and($acknowledged->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered)
        ->and($acknowledged->adjustmentStatus->isAcknowledged())->toBeTrue()
        ->and($acknowledged->adjustmentStatus->isSettled())->toBeFalse()
        ->and($acknowledged->adjustmentStatus->regularizationLabel())->toBe('Regularização financeira pendente')
        ->and($acknowledged->outstandingOverpaymentAmount)->toBe('30000.00')
        ->and($acknowledged->unacknowledgedOverpaymentAmount)->toBe('30000.00')
        ->and($may->payments()->sole()->only(['id', 'measurement_id', 'plan_set_id', 'amount', 'pay_date', 'financial_assessment']))->toEqual($paymentBefore)
        ->and(MeasurementPayment::query()->count())->toBe(1)
        ->and(app(MeasurementRevisionService::class)->overview($revision->fresh())['has_unresolved_difference'])->toBeTrue();

    $acknowledgment = revisionExposureAudits($revision, 'measurement_revision_financial_difference_acknowledged')->sole();

    expect($acknowledgment->properties['responsibility'])->toBe('payment_finalizer_user_id')
        ->and($acknowledgment->properties['decision'])->toBe('Valor pago a maior será cobrado da construtora fora do sistema.')
        ->and($acknowledgment->properties['decided_by'])->toBe($scenario['actor']->id)
        ->and($acknowledgment->properties['decided_at'])->toBe('2026-10-13T15:00:00+00:00')
        ->and($acknowledgment->properties['accepted_by'])->toBe($scenario['actor']->id)
        ->and($acknowledgment->properties['accepted_at'])->toBe('2026-10-14T15:00:00+00:00')
        ->and($acknowledgment->properties['positions'])->toHaveCount(1)
        ->and($acknowledgment->properties['positions'][0])->toMatchArray([
            'plan_set_id' => $scenario['planSet']->id,
            'revised_approved_amount' => '470000.00',
            'family_paid_amount' => '500000.00',
            'outstanding_overpayment_amount' => '30000.00',
            'remaining_payable_amount' => '0.00',
            'acknowledged_now_overpayment_amount' => '30000.00',
            'previously_acknowledged_overpayment_amount' => '0.00',
            'previously_acknowledged_by_measurement_ids' => [],
            'adjustment_status' => MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered->value,
            'exposure_unmeasurable' => false,
        ]);

    $unresolved = revisionExposureAudits($revision, 'measurement_revision_financial_exception_unresolved')->sole();

    expect($unresolved->properties['responsibility'])->toBe('payment_finalizer_user_id')
        ->and($unresolved->properties['regularization'])->toBe('pending')
        ->and($unresolved->properties['recovery_registered'])->toBeFalse()
        ->and($unresolved->properties['positions'][0]['outstanding_overpayment_amount'])->toBe('30000.00');
});

it('splits the overpayment of a second downward revision into the part the first acknowledged and the part it must decide', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $first = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $may, 8), 'Decisão da R1: valor pago a maior tratado fora do sistema.', acceptFinancialExceptions: true);
    $second = Scenario::effective($scenario, $first, 7);
    $position = revisionExposurePosition($second);
    $identified = revisionExposureAudits($second, 'measurement_revision_financial_difference_identified')->sole();

    // Cada revisão registra a própria diferença (incremental); a exposição é a
    // da família contra o aprovado vigente.
    expect(revisionExposurePosition($first)->financialDifferenceAmount)->toBe('-20000.00')
        ->and(revisionExposurePosition($first)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::PositionSuperseded)
        ->and($position->previousApprovedAmount)->toBe('80000.00')
        ->and($position->revisedApprovedAmount)->toBe('70000.00')
        ->and($position->financialDifferenceAmount)->toBe('-10000.00')
        ->and($position->historicalPaidAmount)->toBe('100000.00')
        ->and($position->ownPaidAmount)->toBe('0.00')
        ->and($position->openBalanceAmount)->toBe('-30000.00')
        ->and($position->outstandingOverpaymentAmount)->toBe('30000.00')
        ->and($position->previouslyAcknowledgedOverpaymentAmount)->toBe('20000.00')
        ->and($position->previouslyAcknowledgedByMeasurementIds)->toBe([$first->id])
        ->and($position->unacknowledgedOverpaymentAmount)->toBe('10000.00')
        ->and($position->settledMeasurementId)->toBe($first->id)
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPendingDecision)
        ->and(MeasurementRevisionDifference::query()->where('measurement_id', $second->id)->sole()->unresolved_overpayment_amount)->toBe('10000.00')
        ->and($identified->properties['positions'][0])->toMatchArray([
            'outstanding_overpayment_amount' => '30000.00',
            'remaining_payable_amount' => '0.00',
            'previously_acknowledged_overpayment_amount' => '20000.00',
            'previously_acknowledged_by_measurement_ids' => [$first->id],
            'unresolved_overpayment_amount' => '10000.00',
        ]);

    expect(revisionExposureMessage(fn () => Scenario::pay($scenario, $second, '1000.00'), 'payments.0.amount'))
        ->toContain('nenhum novo pagamento é registrado enquanto houver valor pago a maior em aberto')
        ->and(revisionExposureMessage(fn () => $workflow->approve($second->fresh(), $scenario['actor']), 'notes'))
        ->toContain(
            'Registre a decisão financeira sobre o valor pago a maior',
            'pago a maior em aberto R$ 30.000,00, dos quais R$ 20.000,00 reconhecidos anteriormente e R$ 10.000,00 a reconhecer nesta revisão',
        );

    $workflow->approve($second->fresh(), $scenario['actor'], 'Decisão da R2: os R$ 10.000,00 adicionais seguem a mesma tratativa.');

    expect(app(MeasurementPaymentFinancialService::class)->requiresAcceptance($second->fresh()))->toBeTrue()
        ->and(revisionExposureMessage(fn () => $workflow->finalize($second->fresh(), $scenario['actor']), 'accept_financial_exceptions'))
        ->toContain('valor pago a maior desta revisão');

    $workflow->finalize($second->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    $acknowledgment = revisionExposureAudits($second, 'measurement_revision_financial_difference_acknowledged')->sole();

    expect(revisionExposurePosition($second)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered)
        ->and($acknowledgment->properties['positions'][0])->toMatchArray([
            'outstanding_overpayment_amount' => '30000.00',
            'acknowledged_now_overpayment_amount' => '10000.00',
            'previously_acknowledged_overpayment_amount' => '20000.00',
            'previously_acknowledged_by_measurement_ids' => [$first->id],
        ])
        ->and(app(MeasurementRevisionService::class)->overview($second->fresh())['has_unresolved_difference'])->toBeTrue()
        ->and(revisionExposureAcknowledgments($second))->toBe([
            ['R2', 'nesta revisão', 'Decisão da R2: os R$ 10.000,00 adicionais seguem a mesma tratativa.'],
            ['R1', 'anteriormente', 'Decisão da R1: valor pago a maior tratado fora do sistema.'],
        ]);
});

it('asks no new decision nor acceptance when an earlier Finalization already acknowledged the whole overpayment still open', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $first = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $may, 8), 'Decisão da R1: valor pago a maior tratado fora do sistema.', acceptFinancialExceptions: true);
    $second = Scenario::effective($scenario, $first, '9.50');
    $position = revisionExposurePosition($second);

    // A R2 aprova mais do que a R1 (+R$ 15.000,00), e ainda assim a família
    // continua com R$ 5.000,00 pagos a maior -- já reconhecidos pela R1.
    expect($position->revisedApprovedAmount)->toBe('95000.00')
        ->and($position->financialDifferenceAmount)->toBe('15000.00')
        ->and($position->differenceType)->toBe(MeasurementRevisionDifferenceType::PositiveDifference)
        ->and($position->outstandingOverpaymentAmount)->toBe('5000.00')
        ->and($position->remainingPayableAmount)->toBe('0.00')
        ->and($position->previouslyAcknowledgedOverpaymentAmount)->toBe('5000.00')
        ->and($position->previouslyAcknowledgedByMeasurementIds)->toBe([$first->id])
        ->and($position->unacknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPreviouslyAccepted)
        ->and(revisionExposureMessage(fn () => Scenario::pay($scenario, $second, '1000.00'), 'payments.0.amount'))
        ->toContain('nenhum novo pagamento é registrado enquanto houver valor pago a maior em aberto');

    $workflow->approve($second->fresh(), $scenario['actor']);

    expect(app(MeasurementPaymentFinancialService::class)->requiresAcceptance($second->fresh()))->toBeFalse();

    $workflow->finalize($second->fresh(), $scenario['actor']);
    $finalized = revisionExposurePosition($second);
    $exception = revisionExposureAudits($second, 'measurement_revision_financial_exception_unresolved')->sole();

    // A Finalização da R2 não aceitou nada: o reconhecimento é o da R1, com o
    // autor e a decisão dela. A trilha da R2 não ganha um "reconhecimento" com
    // o Finalizador dela -- só a exceção que continua em aberto, com a origem.
    expect($second->fresh()->status)->toBe('finalized')
        ->and($finalized->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPreviouslyAccepted)
        ->and($finalized->adjustmentStatus->isAcknowledged())->toBeTrue()
        ->and($finalized->adjustmentStatus->isSettled())->toBeFalse()
        ->and($finalized->adjustmentStatus->regularizationLabel())->toBe('Regularização financeira pendente')
        ->and(revisionExposureAudits($second, 'measurement_revision_financial_difference_acknowledged')->exists())->toBeFalse()
        ->and($exception->properties['recovery_registered'])->toBeFalse()
        ->and($exception->properties['positions'][0])->toMatchArray([
            'outstanding_overpayment_amount' => '5000.00',
            'acknowledged_now_overpayment_amount' => '0.00',
            'previously_acknowledged_overpayment_amount' => '5000.00',
            'previously_acknowledged_by_measurement_ids' => [$first->id],
            'adjustment_status' => MeasurementRevisionAdjustmentStatus::OverpaymentPreviouslyAccepted->value,
        ])
        ->and(app(MeasurementRevisionService::class)->overview($second->fresh())['has_unresolved_difference'])->toBeTrue()
        ->and(revisionExposureAcknowledgments($second))->toBe([
            ['R1', 'anteriormente', 'Decisão da R1: valor pago a maior tratado fora do sistema.'],
        ]);
});

it('never counts the supplementary payment of a revision replaced before its Finalization as acknowledged', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $first = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $may, 9), 'Decisão da R1: R$ 10.000,00 pagos a maior tratados fora do sistema.', acceptFinancialExceptions: true);
    $second = Scenario::effective($scenario, $first, 11);
    $supplementary = Scenario::pay($scenario, $second, '10000.00');
    $workflow->approve($second->fresh(), $scenario['actor']);
    Scenario::receiptsApproved($scenario, $second);

    // A R2 pagou o complemento e está na Finalização com a documentação
    // completa: a revisão de medição paga ainda não finalizada (caso C).
    expect($second->fresh()->status)->toBe('approved')
        ->and($second->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION);

    $third = Scenario::effective($scenario, $second, 10);
    $position = revisionExposurePosition($third);

    expect($second->fresh()->status)->toBe('superseded')
        ->and($supplementary->fresh()->measurement_id)->toBe($second->id)
        ->and($supplementary->fresh()->amount)->toBe('10000.00')
        ->and($position->previousApprovedAmount)->toBe('110000.00')
        ->and($position->revisedApprovedAmount)->toBe('100000.00')
        ->and($position->historicalPaidAmount)->toBe('110000.00')
        ->and($position->outstandingOverpaymentAmount)->toBe('10000.00')
        ->and($position->previouslyAcknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($position->previouslyAcknowledgedByMeasurementIds)->toBe([])
        ->and($position->unacknowledgedOverpaymentAmount)->toBe('10000.00')
        ->and($position->settledMeasurementId)->toBe($first->id)
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPendingDecision)
        ->and(revisionExposureMessage(fn () => $workflow->approve($third->fresh(), $scenario['actor']), 'notes'))
        ->toContain('Registre a decisão financeira sobre o valor pago a maior');

    $workflow->approve($third->fresh(), $scenario['actor'], 'Decisão da R3: complemento pago na R2 será cobrado de volta fora do sistema.');

    expect(revisionExposureMessage(fn () => $workflow->finalize($third->fresh(), $scenario['actor']), 'accept_financial_exceptions'))
        ->toContain('valor pago a maior desta revisão')
        ->and($third->fresh()->status)->toBe('approved');

    $workflow->finalize($third->fresh(), $scenario['actor'], acceptFinancialExceptions: true);

    expect($third->fresh()->status)->toBe('finalized')
        ->and(revisionExposurePosition($third)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered);
});

it('keeps crediting an earlier Finalization that still covers more of the overpayment than the latest one', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $first = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $may, 8), 'Decisão da R1: valor pago a maior tratado fora do sistema.', acceptFinancialExceptions: true);
    $second = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $first, 9));
    $third = Scenario::effective($scenario, $second, '8.50');
    $position = revisionExposurePosition($third);

    // A R1 reconheceu R$ 20.000,00 e cobre os R$ 15.000,00 em aberto; a R2,
    // a última finalizada, cobriria só R$ 10.000,00.
    expect($second->status)->toBe('finalized')
        ->and($position->revisedApprovedAmount)->toBe('85000.00')
        ->and($position->historicalPaidAmount)->toBe('100000.00')
        ->and($position->outstandingOverpaymentAmount)->toBe('15000.00')
        ->and($position->previouslyAcknowledgedOverpaymentAmount)->toBe('15000.00')
        ->and($position->previouslyAcknowledgedByMeasurementIds)->toBe([$first->id])
        ->and($position->unacknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($position->settledMeasurementId)->toBe($second->id)
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPreviouslyAccepted);

    $workflow->approve($third->fresh(), $scenario['actor']);
    $workflow->finalize($third->fresh(), $scenario['actor']);

    expect($third->fresh()->status)->toBe('finalized')
        ->and(revisionExposurePosition($third)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPreviouslyAccepted);
});

it('caps a supplementary payment at the remaining payable even when a financial rule covers the excess', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);
    $rule = revisionExposureOverpaymentRule($scenario);
    $justification = 'Excedente dentro da tolerância da regra da emissão.';
    $assessment = app(MeasurementPaymentFinancialService::class)->assess($revision->fresh(), [
        'plan_set_id' => $scenario['planSet']->id,
        'pay_date' => '2026-07-20',
        'amount' => '25000.00',
        'financial_rule_id' => $rule->id,
        'financial_justification' => $justification,
    ]);

    // A regra cobre os R$ 5.000,00 acima do saldo de R$ 20.000,00; ainda assim
    // o pagamento complementar não passa do saldo a pagar.
    expect($assessment['rule']['id'])->toBe($rule->id)
        ->and(revisionExposurePosition($revision)->remainingPayableAmount)->toBe('20000.00')
        ->and(revisionExposureMessage(fn () => Scenario::pay($scenario, $revision, '25000.00', $rule->id, $justification), 'payments.0.amount'))
        ->toContain('passa do saldo em aberto da revisão (R$ 20.000,00)', 'nem com regra financeira')
        ->and($revision->payments()->count())->toBe(0);

    Scenario::pay($scenario, $revision, '20000.00');
    $registered = revisionExposurePosition($revision);

    expect($registered->ownPaidAmount)->toBe('20000.00')
        ->and($registered->familyPaidAmount)->toBe('120000.00')
        ->and($registered->remainingPayableAmount)->toBe('0.00')
        ->and($registered->outstandingOverpaymentAmount)->toBe('0.00')
        ->and($registered->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SupplementaryPaymentRegistered)
        ->and($registered->adjustmentStatus->isSettled())->toBeFalse();

    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision);
    $settled = revisionExposurePosition($finalized);

    expect($finalized->status)->toBe('finalized')
        ->and($settled->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SettledBySupplementaryPayment)
        ->and($settled->adjustmentStatus->isSettled())->toBeTrue()
        ->and($settled->adjustmentStatus->regularizationLabel())->toBe('Regularização concluída')
        ->and($finalized->hasOpenRevisionFinancialPosition())->toBeFalse()
        ->and(revisionExposureAudits($finalized, 'measurement_revision_financial_exception_unresolved')->exists())->toBeFalse();
});

it('keeps a partial supplementary payment open and only acknowledges the missing balance at Finalization', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);

    Scenario::pay($scenario, $revision, '10000.00', justification: 'Primeira parcela do complemento; o restante depende da vistoria.');
    $partial = revisionExposurePosition($revision);

    expect($partial->historicalPaidAmount)->toBe('100000.00')
        ->and($partial->ownPaidAmount)->toBe('10000.00')
        ->and($partial->remainingPayableAmount)->toBe('10000.00')
        ->and($partial->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::PartialSupplementaryPayment)
        ->and($partial->adjustmentStatus->isUnresolved())->toBeTrue()
        ->and($partial->adjustmentStatus->isSettled())->toBeFalse()
        ->and(revisionExposureMessage(fn () => Scenario::pay($scenario, $revision, '15000.00'), 'payments.0.amount'))
        ->toContain('passa do saldo em aberto da revisão (R$ 10.000,00)', 'nem com regra financeira')
        ->and($revision->fresh()->hasOpenRevisionFinancialPosition())->toBeTrue();

    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision, acceptFinancialExceptions: true);
    $accepted = revisionExposurePosition($finalized);

    expect($finalized->status)->toBe('finalized')
        ->and($finalized->payments()->pluck('amount')->all())->toBe(['10000.00'])
        ->and($accepted->remainingPayableAmount)->toBe('10000.00')
        ->and($accepted->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OmissionAccepted)
        ->and($accepted->adjustmentStatus->isAcknowledged())->toBeTrue()
        ->and($accepted->adjustmentStatus->isSettled())->toBeFalse()
        ->and($finalized->hasOpenRevisionFinancialPosition())->toBeTrue();
});

it('reports the own payment of a revision as registered and settles it only at Finalization', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 8);

    Scenario::pay($scenario, $revision, '80000.00');
    $registered = revisionExposurePosition($revision);

    expect($registered->historicalPaidAmount)->toBe('0.00')
        ->and($registered->remainingPayableAmount)->toBe('0.00')
        ->and($registered->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::RevisionPaymentRegistered)
        ->and($registered->adjustmentStatus->isIdentified())->toBeFalse()
        ->and($registered->adjustmentStatus->isSettled())->toBeFalse();

    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision);
    $settled = revisionExposurePosition($finalized);

    expect($finalized->status)->toBe('finalized')
        ->and($settled->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SettledByRevisionPayment)
        ->and($settled->adjustmentStatus->isSettled())->toBeTrue()
        ->and($settled->adjustmentStatus->regularizationLabel())->toBe('Regularização concluída');
});

it('asks only the balance left over an earlier payment below the approved amount and never copies that payment', function (string $source, string $sourceStatusAfter) {
    $scenario = Scenario::plan();
    $may = revisionExposureUnderpaidSource($scenario, $source);
    $paymentBefore = $may->payments()->sole()->only(['id', 'measurement_id', 'plan_set_id', 'amount', 'pay_date', 'financial_assessment']);
    $revision = Scenario::effective($scenario, $may, 8);
    $position = revisionExposurePosition($revision);

    // A diferença da revisão é negativa (−R$ 20.000,00), mas a família pagou
    // menos do que a revisão aprova: falta pagar, não sobra.
    expect($may->fresh()->status)->toBe($sourceStatusAfter)
        ->and($revision->payments()->count())->toBe(0)
        ->and($position->financialDifferenceAmount)->toBe('-20000.00')
        ->and($position->historicalPaidAmount)->toBe('60000.00')
        ->and($position->remainingPayableAmount)->toBe('20000.00')
        ->and($position->outstandingOverpaymentAmount)->toBe('0.00')
        ->and($position->unacknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::AwaitingSupplementaryPayment);

    Scenario::pay($scenario, $revision, '20000.00');
    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision);

    expect($finalized->status)->toBe('finalized')
        ->and(revisionExposurePosition($finalized)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SettledBySupplementaryPayment)
        ->and($may->payments()->sole()->only(['id', 'measurement_id', 'plan_set_id', 'amount', 'pay_date', 'financial_assessment']))->toEqual($paymentBefore)
        ->and(MeasurementPayment::query()->orderBy('id')->get()->map(fn (MeasurementPayment $payment): array => [(int) $payment->measurement_id, $payment->amount])->all())
        ->toBe([[$may->id, '60000.00'], [$revision->id, '20000.00']]);
})->with([
    'finalized source (case A)' => ['finalized', 'finalized'],
    'paid source awaiting finalization (case C)' => ['paid_awaiting_finalization', 'superseded'],
]);

it('records no exposure, exception or pending regularization when the revision keeps the approved amount', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 10);
    $position = revisionExposurePosition($revision);

    expect($position->financialDifferenceAmount)->toBe('0.00')
        ->and($position->openBalanceAmount)->toBe('0.00')
        ->and($position->outstandingOverpaymentAmount)->toBe('0.00')
        ->and($position->remainingPayableAmount)->toBe('0.00')
        ->and($position->previouslyAcknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($position->unacknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::NoAdjustment)
        ->and($position->adjustmentStatus->isIdentified())->toBeFalse()
        ->and($position->adjustmentStatus->acknowledgmentLabel())->toBe('Não se aplica')
        ->and($position->adjustmentStatus->regularizationLabel())->toBe('Não se aplica');

    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision);

    expect($finalized->status)->toBe('finalized')
        ->and(revisionExposurePosition($finalized)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::NoAdjustment)
        ->and(revisionExposureAudits($finalized, 'measurement_revision_financial_difference_acknowledged')->exists())->toBeFalse()
        ->and(revisionExposureAudits($finalized, 'measurement_revision_financial_exception_unresolved')->exists())->toBeFalse()
        ->and(app(MeasurementRevisionService::class)->overview($finalized)['has_unresolved_difference'])->toBeFalse()
        ->and(revisionExposureAcknowledgments($finalized))->toBe([])
        ->and($finalized->hasOpenRevisionFinancialPosition())->toBeFalse();
});

it('keeps a rule-covered excess of the revision own payment out of the amount to decide while refusing new payments', function () {
    $scenario = Scenario::plan();
    $workflow = app(MeasurementWorkflow::class);
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 8);
    $rule = revisionExposureOverpaymentRule($scenario);

    Scenario::pay($scenario, $revision, '85000.00', $rule->id, 'Antecipação prevista no contrato da emissão.');
    $position = revisionExposurePosition($revision);

    // Sem pagamento anterior, o excesso é divergência do próprio pagamento:
    // entra no valor pago a maior em aberto, nunca no que a etapa Pagamento
    // precisa decidir.
    expect($position->historicalPaidAmount)->toBe('0.00')
        ->and($position->ownPaidAmount)->toBe('85000.00')
        ->and($position->outstandingOverpaymentAmount)->toBe('5000.00')
        ->and($position->previouslyAcknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($position->unacknowledgedOverpaymentAmount)->toBe('0.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPendingDecision)
        ->and(revisionExposureMessage(fn () => Scenario::pay($scenario, $revision, '1000.00'), 'payments.0.amount'))
        ->toContain('nenhum novo pagamento é registrado enquanto houver valor pago a maior em aberto');

    $workflow->approve($revision->fresh(), $scenario['actor']);
    Scenario::receiptsApproved($scenario, $revision);

    expect(revisionExposureMessage(fn () => $workflow->finalize($revision->fresh(), $scenario['actor']), 'accept_financial_exceptions'))
        ->toContain('Confirme expressamente o aceite das divergências');

    $workflow->finalize($revision->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    $accepted = revisionExposurePosition($revision);

    expect($revision->fresh()->status)->toBe('finalized')
        ->and($accepted->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered)
        ->and($accepted->outstandingOverpaymentAmount)->toBe('5000.00')
        ->and($accepted->unacknowledgedOverpaymentAmount)->toBe('0.00');
});

it('marks the exposure unmeasurable and refuses new payments when the family paid a development without a construction fund', function () {
    $scenario = revisionExposurePlanWithoutFund();
    $may = Scenario::finalized($scenario, '2026-05', 10, '50000.00');
    $revision = Scenario::effective($scenario, $may, 8);
    $position = revisionExposurePosition($revision);

    expect($position->hasFinancialReference())->toBeFalse()
        ->and($position->revisedApprovedAmount)->toBeNull()
        ->and($position->historicalPaidAmount)->toBe('50000.00')
        ->and($position->openBalanceAmount)->toBeNull()
        ->and($position->outstandingOverpaymentAmount)->toBeNull()
        ->and($position->remainingPayableAmount)->toBeNull()
        ->and($position->previouslyAcknowledgedOverpaymentAmount)->toBeNull()
        ->and($position->unacknowledgedOverpaymentAmount)->toBeNull()
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::ReferenceUnavailable)
        ->and($position->isExposureUnmeasurable())->toBeTrue()
        ->and(revisionExposureMessage(fn () => Scenario::pay($scenario, $revision, '1000.00'), 'payments.0.amount'))
        ->toContain('não tem referência financeira')
        ->and($revision->payments()->count())->toBe(0)
        ->and(Measurement::query()->withOpenRevisionFinancialPosition()->pluck('id')->all())->toBe([$revision->id]);
});

it('measures no exposure and still accepts the payment of a revision without a construction fund when nothing was paid before', function () {
    $scenario = revisionExposurePlanWithoutFund();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 8);
    $position = revisionExposurePosition($revision);

    expect($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::ReferenceUnavailable)
        ->and($position->historicalPaidAmount)->toBe('0.00')
        ->and($position->isExposureUnmeasurable())->toBeFalse()
        ->and(Scenario::pay($scenario, $revision, '30000.00')->amount)->toBe('30000.00')
        ->and($revision->fresh()->hasOpenRevisionFinancialPosition())->toBeFalse();
});

it('flags in the list query only the effective revisions that leave the family position open, finalized ones included', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $june = Scenario::finalized($scenario, '2026-06', 10);
    $overpaid = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $may, 8), 'Valor pago a maior tratado fora do sistema.', acceptFinancialExceptions: true);
    $unchanged = Scenario::closePaymentAndFinalize($scenario, Scenario::effective($scenario, $june, 10));
    $listed = Measurement::query()
        ->whereKey([$overpaid->id, $unchanged->id])
        ->withOpenRevisionFinancialPositionCount()
        ->get()
        ->keyBy('id');

    expect($overpaid->status)->toBe('finalized')
        ->and($unchanged->status)->toBe('finalized')
        ->and(Measurement::query()->withOpenRevisionFinancialPosition()->pluck('id')->all())->toBe([$overpaid->id])
        ->and((int) $listed[$overpaid->id]->open_revision_financial_positions_count)->toBe(1)
        ->and((int) $listed[$unchanged->id]->open_revision_financial_positions_count)->toBe(0)
        ->and($listed[$overpaid->id]->hasOpenRevisionFinancialPosition())->toBeTrue()
        ->and($listed[$unchanged->id]->hasOpenRevisionFinancialPosition())->toBeFalse()
        ->and($overpaid->fresh()->hasOpenRevisionFinancialPosition())->toBeTrue()
        ->and($unchanged->fresh()->hasOpenRevisionFinancialPosition())->toBeFalse()
        ->and($may->fresh()->hasOpenRevisionFinancialPosition())->toBeFalse();
});

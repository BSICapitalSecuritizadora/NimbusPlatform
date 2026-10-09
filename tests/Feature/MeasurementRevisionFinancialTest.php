<?php

use App\DTOs\Measurements\MeasurementRevisionPlanSetPosition;
use App\Enums\MeasurementRevisionAdjustmentStatus;
use App\Enums\MeasurementRevisionDifferenceType;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\MeasurementRevisionDifference;
use App\Services\MeasurementFinancialReconciliationService;
use App\Services\MeasurementRevisionService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementPhysicalProgressScenario;
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
 * A posição única do empreendimento do cenário.
 */
function revisionPosition(Measurement $revision): MeasurementRevisionPlanSetPosition
{
    $positions = app(MeasurementRevisionService::class)->positions($revision->fresh());

    expect($positions)->toHaveCount(1);

    return $positions[0];
}

/**
 * A mensagem de validação de um campo, sem passar pelo `toThrow` -- que
 * compara o texto inteiro e se perde com os dois-pontos das mensagens.
 */
function revisionValidationMessage(callable $attempt, string $field): string
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return (string) collect($exception->errors()[$field] ?? [])->first();
    }

    throw new RuntimeException("A tentativa não foi recusada no campo {$field}.");
}

it('records no financial difference and needs no new payment when the revision keeps the approved amount', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 10);
    $difference = MeasurementRevisionDifference::query()->where('measurement_id', $revision->id)->sole();

    expect($difference->difference_type)->toBe(MeasurementRevisionDifferenceType::NoDifference)
        ->and((string) $difference->financial_difference_amount)->toBe('0.00')
        ->and((string) $difference->historical_paid_amount)->toBe('100000.00')
        ->and((string) $difference->unresolved_overpayment_amount)->toBe('0.00')
        ->and(revisionPosition($revision)->openBalanceAmount)->toBe('0.00')
        ->and(revisionValidationMessage(fn () => Scenario::pay($scenario, $revision, '1000.00'), 'payments.0.amount'))
        ->toContain('Não há valor a pagar nesta revisão');

    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision);

    expect($finalized->status)->toBe('finalized')
        ->and($finalized->payments()->count())->toBe(0)
        ->and(revisionPosition($finalized)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::NoAdjustment)
        ->and($may->payments()->sole()->amount)->toBe('100000.00');
});

it('asks only for the supplementary amount on a positive difference, never the paid amount again', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);
    $position = revisionPosition($revision);

    expect($position->differenceType)->toBe(MeasurementRevisionDifferenceType::PositiveDifference)
        ->and($position->financialDifferenceAmount)->toBe('20000.00')
        ->and($position->historicalPaidAmount)->toBe('100000.00')
        ->and($position->openBalanceAmount)->toBe('20000.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::AwaitingSupplementaryPayment)
        ->and(revisionValidationMessage(fn () => Scenario::pay($scenario, $revision, '25000.00'), 'payments.0.amount'))
        ->toContain('passa do saldo em aberto da revisão');

    Scenario::pay($scenario, $revision, '20000.00');
    $line = app(MeasurementFinancialReconciliationService::class)->forMeasurement($revision->fresh())->line($scenario['planSet']->id);

    expect($line->historicalPaidAmount)->toBe('100000.00')
        ->and($line->registeredAmount)->toBe('20000.00')
        ->and($line->expectedBalance)->toBe('0.00');

    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision);

    expect($finalized->status)->toBe('finalized')
        ->and(revisionPosition($finalized)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SettledBySupplementaryPayment)
        ->and(MeasurementPayment::query()->where('measurement_id', $may->id)->pluck('amount')->all())->toBe(['100000.00']);
});

it('requires a justification and the Finalizer acceptance to leave a positive difference unpaid', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);
    $workflow = app(MeasurementWorkflow::class);

    expect(revisionValidationMessage(fn () => $workflow->approve($revision->fresh(), $scenario['actor']), 'notes'))
        ->toContain('Justifique a ausência de pagamento');

    $workflow->approve($revision->fresh(), $scenario['actor'], 'Complemento pago na competência seguinte, por decisão do comitê.');

    expect(revisionValidationMessage(fn () => $workflow->finalize($revision->fresh(), $scenario['actor']), 'accept_financial_exceptions'))
        ->toContain('Confirme expressamente');

    $workflow->finalize($revision->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    $position = revisionPosition($revision);

    expect($revision->fresh()->status)->toBe('finalized')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OmissionAccepted)
        ->and($position->adjustmentStatus->isUnresolved())->toBeTrue()
        ->and(app(MeasurementRevisionService::class)->overview($revision->fresh())['has_unresolved_difference'])->toBeTrue();
});

it('never refunds nor rewrites payments on a negative difference and requires an explicit financial decision', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $paymentBefore = $may->payments()->sole()->only(['id', 'amount', 'pay_date', 'plan_set_id', 'measurement_id']);
    $revision = Scenario::effective($scenario, $may, 8);
    $workflow = app(MeasurementWorkflow::class);
    $difference = MeasurementRevisionDifference::query()->where('measurement_id', $revision->id)->sole();

    expect($difference->difference_type)->toBe(MeasurementRevisionDifferenceType::NegativeDifference)
        ->and((string) $difference->financial_difference_amount)->toBe('-20000.00')
        ->and((string) $difference->unresolved_overpayment_amount)->toBe('20000.00')
        ->and(revisionPosition($revision)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPendingDecision)
        ->and(revisionValidationMessage(fn () => Scenario::pay($scenario, $revision, '1000.00'), 'payments.0.amount'))
        ->toContain('pago a maior')
        ->and(revisionValidationMessage(fn () => $workflow->approve($revision->fresh(), $scenario['actor']), 'notes'))
        ->toContain('Registre a decisão financeira sobre o valor pago a maior');

    $workflow->approve($revision->fresh(), $scenario['actor'], 'Valor pago a maior será tratado fora do sistema com a construtora.');
    $approval = Activity::query()->where('description', 'measurement_stage_approved')->where('subject_id', $revision->id)->latest('id')->first();

    expect($approval->properties['revision_overpayments'][0]['unresolved_overpayment_amount'])->toBe('20000.00')
        ->and(revisionValidationMessage(fn () => $workflow->finalize($revision->fresh(), $scenario['actor']), 'accept_financial_exceptions'))
        ->toContain('valor pago a maior desta revisão');

    $workflow->finalize($revision->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    $finalized = Activity::query()->where('description', 'measurement_finalized')->where('subject_id', $revision->id)->sole();

    expect($revision->fresh()->status)->toBe('finalized')
        ->and($finalized->properties['revision_overpayments_accepted'][0]['decision'])->toBe('Valor pago a maior será tratado fora do sistema com a construtora.')
        ->and(revisionPosition($revision)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentAcceptedUnrecovered)
        ->and($may->payments()->sole()->only(['id', 'amount', 'pay_date', 'plan_set_id', 'measurement_id']))->toEqual($paymentBefore)
        ->and(MeasurementPayment::query()->count())->toBe(1)
        ->and(app(MeasurementRevisionService::class)->overview($revision->fresh())['has_unresolved_difference'])->toBeTrue();
});

it('settles the revision of an unpaid measurement with its own payment', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 8);
    $position = revisionPosition($revision);

    expect($may->fresh()->status)->toBe('superseded')
        ->and($position->historicalPaidAmount)->toBe('0.00')
        ->and($position->unresolvedOverpaymentAmount)->toBe('0.00')
        ->and($position->openBalanceAmount)->toBe('80000.00')
        ->and(app(MeasurementWorkflow::class)->hasValidPayment($revision->fresh()))->toBeFalse()
        ->and(revisionValidationMessage(fn () => app(MeasurementWorkflow::class)->approve($revision->fresh(), $scenario['actor']), 'payments'))
        ->toContain('Cadastre ao menos um pagamento válido');

    Scenario::pay($scenario, $revision, '80000.00');
    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision);

    expect($finalized->status)->toBe('finalized')
        ->and(revisionPosition($finalized)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::SettledByRevisionPayment)
        ->and(app(MeasurementRevisionService::class)->overview($finalized)['has_unresolved_difference'])->toBeFalse()
        ->and($may->payments()->count())->toBe(0);
});

it('lets a revision with nothing to pay leave the Payment stage without a payment of its own', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 0, 'Não houve avanço em maio.');
    $position = revisionPosition($revision);

    expect($position->revisedApprovedAmount)->toBe('0.00')
        ->and($position->openBalanceAmount)->toBe('0.00')
        ->and($position->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::NoAdjustment)
        ->and(app(MeasurementWorkflow::class)->hasValidPayment($revision->fresh()))->toBeTrue()
        ->and(revisionValidationMessage(fn () => Scenario::pay($scenario, $revision, '1.00'), 'payments.0.amount'))
        ->toContain('Não há valor a pagar nesta revisão');

    $finalized = Scenario::closePaymentAndFinalize($scenario, $revision);

    expect($finalized->status)->toBe('finalized')
        ->and($finalized->payments()->count())->toBe(0)
        ->and($may->payments()->count())->toBe(0);
});

it('keeps flagging an overpayment accepted before, and leaves the replaced revision without an adjustment of its own', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $first = Scenario::effective($scenario, $may, 8);
    app(MeasurementWorkflow::class)->approve($first->fresh(), $scenario['actor'], 'Decisão: valor a maior tratado fora do sistema.');
    app(MeasurementWorkflow::class)->finalize($first->fresh(), $scenario['actor'], acceptFinancialExceptions: true);
    $second = Scenario::effective($scenario, $first->fresh(), 9);
    $service = app(MeasurementRevisionService::class);

    expect(revisionPosition($second)->unresolvedOverpaymentAmount)->toBe('0.00')
        ->and(revisionPosition($second)->openBalanceAmount)->toBe('-10000.00')
        ->and(revisionPosition($second)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::OverpaymentPreviouslyAccepted)
        ->and($service->overview($second->fresh())['has_unresolved_difference'])->toBeTrue()
        ->and(revisionPosition($first)->adjustmentStatus)->toBe(MeasurementRevisionAdjustmentStatus::PositionSuperseded)
        ->and($service->overview($first->fresh())['has_unresolved_difference'])->toBeFalse();
});

it('counts only the new excess of a second revision after an overpayment was already accepted', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $first = Scenario::effective($scenario, $may, 8);
    app(MeasurementWorkflow::class)->approve($first->fresh(), $scenario['actor'], 'Decisão: valor a maior tratado fora do sistema.');
    app(MeasurementWorkflow::class)->finalize($first->fresh(), $scenario['actor'], acceptFinancialExceptions: true);

    $second = Scenario::effective($scenario, $first->fresh(), 7);
    $position = revisionPosition($second);

    expect($second->revisionNumber())->toBe(2)
        ->and($position->previousApprovedAmount)->toBe('80000.00')
        ->and($position->revisedApprovedAmount)->toBe('70000.00')
        ->and($position->historicalPaidAmount)->toBe('100000.00')
        ->and($position->settledMeasurementId)->toBe($first->id)
        ->and($position->settledApprovedAmount)->toBe('80000.00')
        ->and($position->unresolvedOverpaymentAmount)->toBe('10000.00');
});

it('never deletes nor rewrites a registered payment', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $payment = $may->payments()->sole();

    expect(fn () => $payment->fresh()->delete())->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $payment->fresh()->forceFill(['amount' => '1.00'])->save())->toThrow(MeasurementWorkflowException::class)
        ->and(fn () => $payment->fresh()->forceFill(['pay_date' => '2026-01-01'])->save())->toThrow(MeasurementWorkflowException::class)
        ->and($payment->fresh()->amount)->toBe('100000.00');
});

it('recomputes the difference when the effective revision is approved again by Engineering and keeps the previous computation', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);
    $workflow = app(MeasurementWorkflow::class);

    // Pagamento → Compliance → Gestão → Engenharia.
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever o percentual.');
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever o percentual.');
    $workflow->reject($revision->fresh(), $scenario['actor'], 'Rever o percentual.');

    expect(MeasurementRevisionDifference::query()->where('measurement_id', $revision->id)->current()->exists())->toBeFalse();

    MeasurementPhysicalProgressScenario::approveEngineering($scenario, $revision, 11);
    $rows = MeasurementRevisionDifference::query()->where('measurement_id', $revision->id)->orderBy('computation')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->superseded_at)->not->toBeNull()
        ->and((string) $rows[0]->financial_difference_amount)->toBe('20000.00')
        ->and($rows[1]->superseded_at)->toBeNull()
        ->and($rows[1]->computation)->toBe(2)
        ->and((string) $rows[1]->financial_difference_amount)->toBe('10000.00')
        ->and($rows[1]->engineering_snapshot_sha256)->toBe(MeasurementRevisionService::canonicalSnapshotHash($revision->fresh()->engineering_snapshot));
});

it('keeps difference rows immutable', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $revision = Scenario::effective($scenario, $may, 12);
    $difference = MeasurementRevisionDifference::query()->where('measurement_id', $revision->id)->sole();

    expect(fn () => $difference->fresh()->forceFill(['financial_difference_amount' => '1.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $difference->fresh()->delete())->toThrow(LogicException::class)
        ->and((string) $difference->fresh()->financial_difference_amount)->toBe('20000.00');
});

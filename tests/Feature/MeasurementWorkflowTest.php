<?php

use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function makeWorkflowMeasurement(array $operationOverrides = []): Measurement
{
    $operation = Operation::factory()->create($operationOverrides);

    return Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'pending',
        'current_stage' => 1,
    ]);
}

it('opens the first stage review when review starts', function () {
    $reviewer = User::factory()->create();
    $measurement = makeWorkflowMeasurement(['responsible_user_id' => $reviewer->id]);

    app(MeasurementWorkflow::class)->startReview($measurement);
    $measurement->refresh();

    expect($measurement->status)->toBe('in_review')
        ->and($measurement->current_stage)->toBe(1)
        ->and($measurement->reviewForStage(1)?->reviewer_user_id)->toBe($reviewer->id)
        ->and($measurement->reviewForStage(1)?->status)->toBe('pending');
});

it('advances to the next configured stage on approval', function () {
    $stage1 = User::factory()->create();
    $stage2 = User::factory()->create();
    $actor = User::factory()->create();
    $measurement = makeWorkflowMeasurement([
        'responsible_user_id' => $stage1->id,
        'stage2_reviewer_user_id' => $stage2->id,
        'stage3_reviewer_user_id' => null,
    ]);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement);
    $workflow->approve($measurement->fresh(), $actor);

    $measurement->refresh();

    expect($measurement->current_stage)->toBe(2)
        ->and($measurement->status)->toBe('in_review')
        ->and($measurement->reviewForStage(1)?->status)->toBe('approved')
        ->and($measurement->reviewForStage(2)?->status)->toBe('pending');
});

it('moves to awaiting payment when the last stage is approved', function () {
    $stage1 = User::factory()->create();
    $actor = User::factory()->create();
    $measurement = makeWorkflowMeasurement(['responsible_user_id' => $stage1->id]);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement);
    $workflow->approve($measurement->fresh(), $actor);

    expect($measurement->fresh()->status)->toBe('awaiting_payment');
});

it('closes the measurement when the engineering stage rejects it', function () {
    Notification::fake();

    $actor = User::factory()->create();
    $notified = User::factory()->create();
    $measurement = makeWorkflowMeasurement(['responsible_user_id' => $actor->id]);
    $measurement->operation->rejectionNotifyUsers()->attach($notified->id);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement);
    $workflow->reject($measurement->fresh(), $actor, 'Documentação incompleta');

    $measurement->refresh();

    expect($measurement->status)->toBe('rejected')
        ->and($measurement->reviewForStage(1)?->status)->toBe('rejected')
        ->and($measurement->reviewForStage(1)?->notes)->toBe('Documentação incompleta');

    Notification::assertSentTo($notified, MeasurementWorkflowNotification::class);
});

it('returns to the previous stage when a later stage rejects', function () {
    $stage1 = User::factory()->create();
    $stage2 = User::factory()->create();
    $measurement = makeWorkflowMeasurement([
        'responsible_user_id' => $stage1->id,
        'stage2_reviewer_user_id' => $stage2->id,
    ]);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement);
    $workflow->approve($measurement->fresh(), $stage1); // now at stage 2
    expect($measurement->fresh()->current_stage)->toBe(2);

    $workflow->reject($measurement->fresh(), $stage2, 'Faltam documentos');
    $measurement->refresh();

    expect($measurement->status)->toBe('in_review')
        ->and($measurement->current_stage)->toBe(1)
        ->and($measurement->reviewForStage(2)?->status)->toBe('rejected')
        ->and($measurement->reviewForStage(1)?->status)->toBe('pending');
});

it('returns the payment stage to compliance on rejection', function () {
    $actor = User::factory()->create();
    $measurement = makeWorkflowMeasurement(['responsible_user_id' => $actor->id]);
    $measurement->forceFill(['status' => 'awaiting_payment', 'current_stage' => 3])->save();

    app(MeasurementWorkflow::class)->reject($measurement->fresh(), $actor, 'Valor divergente');
    $measurement->refresh();

    expect($measurement->status)->toBe('in_review')
        ->and($measurement->current_stage)->toBe(3)
        ->and($measurement->reviewForStage(3)?->status)->toBe('pending');
});

it('lets the finalization stage send the measurement back to any stage', function () {
    $actor = User::factory()->create();
    $measurement = makeWorkflowMeasurement(['responsible_user_id' => $actor->id]);
    $measurement->forceFill(['status' => 'awaiting_receipt', 'current_stage' => 3])->save();

    app(MeasurementWorkflow::class)->returnToStage($measurement->fresh(), $actor, 1, 'Revisar engenharia');
    $measurement->refresh();

    expect($measurement->status)->toBe('in_review')
        ->and($measurement->current_stage)->toBe(1)
        ->and($measurement->reviewForStage(1)?->status)->toBe('pending');
});

it('pauses and resumes restoring the previous status', function () {
    $actor = User::factory()->create();
    $measurement = makeWorkflowMeasurement(['responsible_user_id' => $actor->id]);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement);
    $workflow->pause($measurement->fresh(), $actor, 'Aguardando esclarecimento');

    $measurement->refresh();
    expect($measurement->status)->toBe('paused')
        ->and($measurement->reviewForStage(1)?->isPaused())->toBeTrue()
        ->and($measurement->pauses()->whereNull('resumed_at')->count())->toBe(1);

    $workflow->resume($measurement->fresh(), $actor);
    $measurement->refresh();

    expect($measurement->status)->toBe('in_review')
        ->and($measurement->reviewForStage(1)?->isPaused())->toBeFalse()
        ->and($measurement->pauses()->whereNull('resumed_at')->count())->toBe(0);
});

it('propagates the realized progress reported during validation to the schedule line', function () {
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);

    MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 1,
        'planned_cumulative_percent' => 30,
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
        'measurement_date' => '2026-05-01',
    ]);
    $line2 = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 2,
        'planned_cumulative_percent' => 60,
        'realized_monthly_percent' => 0,
        'realized_cumulative_percent' => 0,
        'measurement_date' => '2026-06-01',
    ]);

    MeasurementPlanLine::query()->where('id', $line2->id)->update(['realized_cumulative_percent' => 25]);

    $line3 = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 3,
        'planned_cumulative_percent' => 80,
        'measurement_date' => '2026-07-01',
    ]);

    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-07-01',
    ]);

    app(MeasurementWorkflow::class)->recordRealizedProgress($measurement, [
        $planSet->id => 18,
    ]);

    $line3->refresh();

    expect($line3->realized_monthly_percent)->toBe('18.00')
        ->and($line3->realized_cumulative_percent)->toBe('43.00')
        ->and($line3->measurement_id)->toBe($measurement->id)
        ->and($line3->evolution_diff_percent)->toBe('-37.00')
        ->and($line3->evolution_trend)->toBe(MeasurementPlanLine::TREND_BEHIND);
});

it('targets the schedule line chosen on each development asset', function () {
    $operation = Operation::factory()->create();
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);

    $chosenLine = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 2,
        'planned_cumulative_percent' => 50,
        'measurement_date' => '2026-05-01',
    ]);
    $otherMonthLine = MeasurementPlanLine::factory()->create([
        'plan_set_id' => $planSet->id,
        'operation_id' => $operation->id,
        'sequence_number' => 3,
        'measurement_date' => '2026-06-01',
        'realized_monthly_percent' => 0,
    ]);

    // Reference month points elsewhere, but the asset explicitly chose $chosenLine.
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'reference_month' => '2026-06-01',
    ]);
    $measurement->assets()->create([
        'plan_set_id' => $planSet->id,
        'plan_line_id' => $chosenLine->id,
        'storage_path' => 'measurements/a.pdf',
        'filename' => 'a.pdf',
    ]);

    app(MeasurementWorkflow::class)->recordRealizedProgress($measurement, [
        $planSet->id => 12,
    ]);

    expect($chosenLine->fresh()->realized_monthly_percent)->toBe('12.00')
        ->and($chosenLine->fresh()->measurement_id)->toBe($measurement->id)
        ->and($otherMonthLine->fresh()->realized_monthly_percent)->toBe('0.00');
});

it('registers one payment per development', function () {
    $actor = User::factory()->create();
    $operation = Operation::factory()->create(['responsible_user_id' => $actor->id]);
    $planA = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $planB = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);

    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_payment',
        'current_stage' => 1,
    ]);

    $payments = app(MeasurementWorkflow::class)->registerPayments($measurement, $actor, [
        ['plan_set_id' => $planA->id, 'amount' => 100000, 'pay_date' => '2026-05-10', 'method' => 'PIX'],
        ['plan_set_id' => $planB->id, 'amount' => 50000, 'pay_date' => '2026-05-10', 'method' => 'PIX'],
    ]);

    expect($payments)->toHaveCount(2)
        ->and($measurement->fresh()->status)->toBe('awaiting_receipt')
        ->and($measurement->payments()->where('plan_set_id', $planA->id)->value('amount'))->toBe('100000.00')
        ->and($measurement->payments()->where('plan_set_id', $planB->id)->value('amount'))->toBe('50000.00');
});

it('ignores payment rows without an amount', function () {
    $actor = User::factory()->create();
    $operation = Operation::factory()->create(['responsible_user_id' => $actor->id]);
    $planA = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);
    $planB = MeasurementPlanSet::factory()->create(['operation_id' => $operation->id]);

    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'status' => 'awaiting_payment',
        'current_stage' => 1,
    ]);

    $payments = app(MeasurementWorkflow::class)->registerPayments($measurement, $actor, [
        ['plan_set_id' => $planA->id, 'amount' => 100000, 'pay_date' => '2026-05-10'],
        ['plan_set_id' => $planB->id, 'amount' => null, 'pay_date' => '2026-05-10'],
    ]);

    expect($payments)->toHaveCount(1)
        ->and($measurement->payments()->count())->toBe(1);
});

it('registers a payment then attaches a receipt and finalizes', function () {
    $actor = User::factory()->create();
    $measurement = makeWorkflowMeasurement(['responsible_user_id' => $actor->id]);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->startReview($measurement);
    $workflow->approve($measurement->fresh(), $actor);

    $payment = $workflow->registerPayment($measurement->fresh(), $actor, [
        'pay_date' => '2026-05-10',
        'amount' => 150000.50,
        'method' => 'PIX',
    ]);

    expect($measurement->fresh()->status)->toBe('awaiting_receipt')
        ->and($payment->operation_id)->toBe($measurement->operation_id)
        ->and($payment->created_by)->toBe($actor->id);

    $workflow->attachReceipt($payment, 'receipts/test.pdf');
    expect($payment->fresh()->hasReceipt())->toBeTrue();

    $workflow->finalize($measurement->fresh(), $actor);
    $measurement->refresh();

    expect($measurement->status)->toBe('finalized')
        ->and($measurement->analyzed_by)->toBe($actor->id)
        ->and($measurement->analyzed_at)->not->toBeNull();
});

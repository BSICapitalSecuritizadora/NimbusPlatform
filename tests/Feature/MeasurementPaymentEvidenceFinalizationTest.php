<?php

use App\Enums\MeasurementReceiptReviewStatus;
use App\Exceptions\MeasurementSegregationException;
use App\Exceptions\MeasurementWorkflowException;
use App\Exceptions\OperationLifecycleException;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\Measurements\Pages\EditMeasurement;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Models\Measurement;
use App\Models\MeasurementFileMigration;
use App\Models\MeasurementPayment;
use App\Models\MeasurementPaymentReceiptEvidence;
use App\Models\User;
use App\Notifications\MeasurementWorkflowNotification;
use App\Services\MeasurementPendingService;
use App\Services\MeasurementReceiptEvidenceService;
use App\Services\MeasurementSegregationService;
use App\Services\MeasurementWorkflow;
use App\Services\Security\ClamAvFileScanner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementGovernanceScenario as Governance;
use Tests\Support\MeasurementReceiptEvidenceScenario;

/**
 * Fase 4B-2: o comprovante entra com o pagamento, nasce pendente e é
 * conferido na Finalização por quem não registrou o pagamento; a rejeição
 * não muda o pagamento, e a substituição é uma versão nova. Cada papel do
 * fluxo é de uma pessoa (MeasurementGovernanceScenario); quando um teste dá
 * dois papéis à mesma pessoa, é o que está sob teste.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * A exceção da ação, ou `null` -- lida inteira, não por `toThrow()`: as
 * mensagens têm dois-pontos.
 */
function evidenceThrown(callable $action): ?Throwable
{
    try {
        $action();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * Um empreendimento (Fundo de R$ 1.000.000,00), medido em 10% em junho:
 * R$ 100.000,00 a pagar.
 *
 * @return array<string, mixed>
 */
function evidenceSingle(): array
{
    return Governance::plan([['fund' => '1000000.00', 'name' => 'Torre Norte']]);
}

function evidenceReceipt(string $name = 'comprovante.pdf'): UploadedFile
{
    return MeasurementReceiptEvidenceScenario::file($name, '%PDF-1.7 comprovante '.$name.' '.uniqid('', true));
}

/**
 * @param  array<string, mixed>  $scenario
 * @param  list<array<string, mixed>>  $rows
 */
function evidenceRegister(array $scenario, Measurement $measurement, array $rows, ?UploadedFile $receipt = null, ?User $by = null): mixed
{
    return app(MeasurementWorkflow::class)->registerPayments($measurement->fresh(), $by ?? $scenario['payer'], $rows, receipt: $receipt);
}

function evidenceCurrent(MeasurementPayment $payment): MeasurementPaymentReceiptEvidence
{
    return $payment->fresh()->currentReceiptEvidence()->firstOrFail();
}

function evidenceReview(MeasurementPaymentReceiptEvidence $evidence, User $actor, MeasurementReceiptReviewStatus $decision = MeasurementReceiptReviewStatus::Approved, ?string $reason = null): MeasurementPaymentReceiptEvidence
{
    return app(MeasurementReceiptEvidenceService::class)->review($evidence->fresh(), $actor, $decision, true, rejectionReason: $reason);
}

/**
 * @param  array<string, mixed>  $scenario
 * @param  array<string, int>  $columns
 */
function evidenceAssign(array $scenario, array $columns): void
{
    $scenario['operation']->forceFill($columns)->saveQuietly();
}

// ── A. Pagamento comum ──────────────────────────────────────────────────────

it('registers the payment with its receipt pending review and finalizes after an independent approval', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);

    $payment = evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], evidenceReceipt('ted-junho.pdf'))->sole();
    $evidence = evidenceCurrent($payment);

    expect($evidence->version)->toBe(1)
        ->and($evidence->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and((int) $evidence->uploaded_by)->toBe($s['payer']->id)
        ->and($evidence->original_filename)->toBe('ted-junho.pdf')
        ->and($evidence->sha256)->toHaveLength(64)
        ->and($evidence->storage_disk)->toBe('local');
    Storage::disk('local')->assertExists($evidence->storage_path);

    $upload = Activity::query()->where('log_name', 'measurement_evidence')->where('description', 'receipt_evidence_uploaded')
        ->where('subject_id', $evidence->id)->sole();
    expect($upload->properties['context'])->toBe('payment_registration')
        ->and((int) $upload->causer_id)->toBe($s['payer']->id)
        ->and((int) $upload->properties['expected_responsible_user_id'])->toBe($s['payer']->id);

    // O pagamento é histórico: o comprovante não o torna editável.
    expect(fn () => $payment->fresh()->update(['amount' => '1.00']))->toThrow(MeasurementWorkflowException::class);

    $measurement = Governance::approvePaymentStage($s, $measurement);
    expect($measurement->status)->toBe('awaiting_receipt');
    Notification::assertSentTo($s['finalizer'], MeasurementWorkflowNotification::class, fn ($notification): bool => $notification->event === 'receipts_awaiting_review');
    Notification::assertNotSentTo($s['uploader'], MeasurementWorkflowNotification::class, fn ($notification): bool => $notification->event === 'payment_approved');

    evidenceReview($evidence, $s['finalizer']);
    expect($measurement->fresh()->status)->toBe('approved');
    Notification::assertSentTo($s['finalizer'], MeasurementWorkflowNotification::class, fn ($notification): bool => $notification->event === 'ready_to_finalize');

    expect(Governance::finalize($s, $measurement)->status)->toBe('finalized')
        ->and($payment->fresh()->amount)->toBe('100000.00');
});

// ── B. Sem comprovante ──────────────────────────────────────────────────────

it('refuses a payment without receipt and persists nothing', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $revision = (int) $measurement->workflow_revision;

    $refusal = evidenceThrown(fn () => evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')]));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and($refusal->errors())->toBe(['payments.0.receipt' => [MeasurementWorkflow::PAYMENT_RECEIPT_REQUIRED]])
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(MeasurementPaymentReceiptEvidence::query()->count())->toBe(0)
        ->and((int) $measurement->fresh()->workflow_revision)->toBe($revision);
});

// ── C. Arquivo inválido ─────────────────────────────────────────────────────

it('refuses an invalid receipt file without payment, evidence or stored file', function (Closure $receipt, ?Closure $arrange = null) {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $files = Storage::disk('local')->allFiles('nimbus_docs/measurements/receipts');
    $arrange?->call($this);

    $refusal = evidenceThrown(fn () => evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], $receipt()));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($refusal->errors()))->toBe(['receipt'])
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(MeasurementPaymentReceiptEvidence::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('nimbus_docs/measurements/receipts'))->toBe($files);
})->with([
    'extensão não aceita' => [fn () => UploadedFile::fake()->createWithContent('comprovante.txt', 'texto qualquer')],
    'acima do tamanho' => [fn () => UploadedFile::fake()->create('comprovante.pdf', 20, 'application/pdf'), function (): void {
        config()->set('uploads.measurement_receipt.max_kb', 10);
    }],
    'PDF falso' => [fn () => UploadedFile::fake()->createWithContent('comprovante.pdf', 'isto não é um PDF')],
    'antivírus recusa' => [fn () => evidenceReceipt(), function (): void {
        $this->mock(ClamAvFileScanner::class, function ($mock): void {
            $mock->shouldReceive('isEnabled')->andReturnTrue();
            $mock->shouldReceive('scanStream')->andReturn(ClamAvFileScanner::RESULT_INFECTED);
            $mock->shouldReceive('scan')->andReturn(ClamAvFileScanner::RESULT_INFECTED);
        });
    }],
]);

it('refuses a forged receipt path in the payment modal', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);

    $this->actingAs($s['payer']);
    Livewire\Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->set('mountedActions.0.data.receipt', ['forjado' => 'nimbus_docs/measurements/receipts/outra-operacao.pdf'])
        ->callMountedAction()
        ->assertHasFormErrors(['receipt']);

    expect(MeasurementPayment::query()->count())->toBe(0);
});

// ── D. Autoconferência ──────────────────────────────────────────────────────

it('refuses the payment registrar approving the receipt as Finalizer and accepts an independent one', function () {
    $s = evidenceSingle();
    evidenceAssign($s, ['payment_finalizer_user_id' => $s['payer']->id]);
    $s['payer']->givePermissionTo('measurements.finalize');
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $payment = evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], evidenceReceipt())->sole();
    Governance::approvePaymentStage($s, $measurement);

    $refusal = evidenceThrown(fn () => evidenceReview(evidenceCurrent($payment), $s['payer']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toBe(MeasurementSegregationService::RECEIPT_SELF_APPROVAL.' Você enviou a versão v1 do comprovante.')
        ->and(evidenceCurrent($payment)->review_status)->toBe(MeasurementReceiptReviewStatus::Pending);

    // O Finalizador independente que a operação passa a ter confere.
    evidenceAssign($s, ['payment_finalizer_user_id' => $s['finalizer']->id]);
    evidenceReview(evidenceCurrent($payment), $s['finalizer']);

    expect(evidenceCurrent($payment)->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        ->and((int) evidenceCurrent($payment)->reviewer_user_id)->toBe($s['finalizer']->id);
});

it('refuses the administrator override from approving the receipt the administrator registered', function () {
    $s = evidenceSingle();
    evidenceAssign($s, ['payment_manager_user_id' => $s['admin']->id]);
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $payment = app(MeasurementWorkflow::class)->registerPayments($measurement->fresh(), $s['admin'], [Governance::row($s, 0, '100000.00')], overrideReason: null, receipt: evidenceReceipt())->sole();
    Governance::approvePaymentStage($s, $measurement, by: $s['admin']);

    $refusal = evidenceThrown(fn () => app(MeasurementReceiptEvidenceService::class)->review(evidenceCurrent($payment), $s['admin'], MeasurementReceiptReviewStatus::Approved, true, overrideReason: 'Conferência no fechamento do mês.'));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and(evidenceCurrent($payment)->review_status)->toBe(MeasurementReceiptReviewStatus::Pending);
});

// ── E. Comprovante rejeitado ────────────────────────────────────────────────

it('keeps the payment intact when the receipt is rejected and finalizes after an approved replacement', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $payment = evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], evidenceReceipt('agendamento.pdf'))->sole();
    $paymentState = $payment->fresh()->getRawOriginal();
    Governance::approvePaymentStage($s, $measurement);
    $first = evidenceCurrent($payment);

    expect(evidenceThrown(fn () => evidenceReview($first, $s['finalizer'], MeasurementReceiptReviewStatus::Rejected)))
        ->toBeInstanceOf(ValidationException::class);

    evidenceReview($first, $s['finalizer'], MeasurementReceiptReviewStatus::Rejected, 'O arquivo é o agendamento, não o comprovante da transferência.');
    Notification::assertSentTo($s['uploader'], MeasurementWorkflowNotification::class, fn ($notification): bool => $notification->event === 'receipt_rejected');

    expect($payment->fresh()->getRawOriginal())->toBe($paymentState)
        ->and($measurement->fresh()->status)->toBe('awaiting_receipt')
        ->and(evidenceThrown(fn () => Governance::finalize($s, $measurement)))->toBeInstanceOf(MeasurementWorkflowException::class);

    $replacement = app(MeasurementReceiptEvidenceService::class)->upload(
        $payment->fresh(),
        $s['uploader'],
        evidenceReceipt('ted-junho.pdf'),
        $first->id,
        'Comprovante da transferência no lugar do agendamento.',
    );
    Notification::assertSentTo($s['finalizer'], MeasurementWorkflowNotification::class, fn ($notification): bool => $notification->event === 'receipt_replaced');

    expect($replacement->version)->toBe(2)
        ->and((int) $replacement->supersedes_id)->toBe($first->id)
        ->and($first->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Rejected)
        ->and($first->fresh()->rejection_reason)->toBe('O arquivo é o agendamento, não o comprovante da transferência.');
    Storage::disk('local')->assertExists($first->storage_path);

    evidenceReview($replacement, $s['finalizer']);

    expect(Governance::finalize($s, $measurement)->status)->toBe('finalized')
        ->and($payment->fresh()->getRawOriginal())->toBe($paymentState)
        ->and($payment->receiptEvidences()->count())->toBe(2);
});

// ── F. Vários pagamentos, um comprovante cada ───────────────────────────────

it('blocks the Finalization until every payment receipt is approved', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10, 10]);
    $payments = evidenceRegister($s, $measurement, [
        [...Governance::row($s, 0, '100000.00'), 'receipt' => evidenceReceipt('norte.pdf')],
        [...Governance::row($s, 1, '50000.00'), 'receipt' => evidenceReceipt('sul.pdf')],
    ]);
    Governance::approvePaymentStage($s, $measurement);

    [$north, $south] = [evidenceCurrent($payments[0]), evidenceCurrent($payments[1])];
    expect($north->storage_path)->not->toBe($south->storage_path)
        ->and($north->original_filename)->toBe('norte.pdf')
        ->and($south->original_filename)->toBe('sul.pdf');

    evidenceReview($north, $s['finalizer']);
    $refusal = evidenceThrown(fn () => Governance::finalize($s, $measurement));

    expect($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toContain('aguardando aprovação documental')
        ->and($measurement->fresh()->status)->toBe('awaiting_receipt');

    evidenceReview($south, $s['finalizer']);

    expect(Governance::finalize($s, $measurement)->status)->toBe('finalized');
});

it('requires a receipt on every row when each development was paid by its own transfer', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10, 10]);

    $refusal = evidenceThrown(fn () => evidenceRegister($s, $measurement, [
        [...Governance::row($s, 0, '100000.00'), 'receipt' => evidenceReceipt('norte.pdf')],
        Governance::row($s, 1, '50000.00'),
    ]));

    expect($refusal)->toBeInstanceOf(ValidationException::class)
        ->and(array_keys($refusal->errors()))->toBe(['payments.1.receipt'])
        ->and(MeasurementPayment::query()->count())->toBe(0);
});

// ── G. Empreendimentos diferentes, uma transferência ────────────────────────

it('links one transfer receipt to each payment without inventing transfers or mixing developments', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10, 10]);
    $payments = evidenceRegister($s, $measurement, [
        Governance::row($s, 0, '100000.00'),
        Governance::row($s, 1, '50000.00'),
    ], evidenceReceipt('ted-unica.pdf'));

    expect($payments)->toHaveCount(2)
        ->and((int) $payments[0]->plan_set_id)->toBe($s['planSets'][0]->id)
        ->and($payments[0]->amount)->toBe('100000.00')
        ->and((int) $payments[1]->plan_set_id)->toBe($s['planSets'][1]->id)
        ->and($payments[1]->amount)->toBe('50000.00');

    [$north, $south] = [evidenceCurrent($payments[0]), evidenceCurrent($payments[1])];
    expect($north->id)->not->toBe($south->id)
        ->and($north->storage_path)->toBe($south->storage_path)
        ->and($north->sha256)->toBe($south->sha256)
        ->and(Storage::disk('local')->allFiles(dirname($north->storage_path)))->toHaveCount(1);

    $shared = Activity::query()->where('log_name', 'measurement_evidence')->where('subject_id', $north->id)->sole();
    expect($shared->properties['shared_with_payment_ids'])->toBe([$payments[0]->id, $payments[1]->id]);

    // Cada pagamento é conferido por si: rejeitar um não mexe no outro.
    Governance::approvePaymentStage($s, $measurement);
    evidenceReview($north, $s['finalizer']);
    evidenceReview($south, $s['finalizer'], MeasurementReceiptReviewStatus::Rejected, 'O comprovante não mostra o valor da Torre Sul.');

    expect($north->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved)
        ->and(evidenceThrown(fn () => Governance::finalize($s, $measurement)))->toBeInstanceOf(MeasurementWorkflowException::class);

    $replacement = app(MeasurementReceiptEvidenceService::class)->upload($payments[1]->fresh(), $s['uploader'], evidenceReceipt('sul-detalhado.pdf'), $south->id, 'Comprovante com o detalhamento da Torre Sul.');
    evidenceReview($replacement, $s['finalizer']);

    expect(Governance::finalize($s, $measurement)->status)->toBe('finalized');
    Storage::disk('local')->assertExists($north->storage_path);
});

// ── H. Pagamento parcial ────────────────────────────────────────────────────

it('requires an independent Finalizer to accept the outstanding balance of a partial payment', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $payment = evidenceRegister($s, $measurement, [Governance::row($s, 0, '60000.00', ['financial_justification' => Governance::JUSTIFICATION])], evidenceReceipt())->sole();

    expect($payment->financial_assessment['reference']['status'])->toBe('under')
        ->and($payment->financial_assessment['requires_acceptance'])->toBeTrue();

    $measurement = Governance::readyToFinalize($s, $measurement);
    $segregation = app(MeasurementSegregationService::class);

    expect($segregation->financialAcceptanceReasons($measurement))->toBe(['partial_payment_outstanding_balance']);

    // O Gestor de Pagamento que também é Finalizador não aceita o próprio
    // saldo em aberto.
    evidenceAssign($s, ['payment_finalizer_user_id' => $s['payer']->id]);
    $s['payer']->givePermissionTo('measurements.finalize');
    $refusal = evidenceThrown(fn () => Governance::finalize($s, $measurement, accept: true, by: $s['payer']));

    expect($refusal)->toBeInstanceOf(MeasurementSegregationException::class)
        ->and($refusal->getMessage())->toStartWith(MeasurementSegregationService::FINANCIAL_EXCEPTION_SELF_ACCEPTANCE)
        ->and(app(MeasurementWorkflow::class)->finalizationBlockReason($measurement->fresh(), $s['payer']))->not->toBeNull()
        ->and($measurement->fresh()->status)->toBe('approved');

    evidenceAssign($s, ['payment_finalizer_user_id' => $s['finalizer']->id]);
    $measurement = Governance::finalize($s, $measurement, accept: true);
    $finalization = Activity::query()->where('log_name', 'measurement_workflow')->where('description', 'measurement_finalized')->sole();

    expect($measurement->status)->toBe('finalized')
        ->and($finalization->properties['segregation']['financial_exceptions']['required_because'])->toBe(['partial_payment_outstanding_balance'])
        ->and($finalization->properties['segregation']['financial_exceptions']['makers'])->toContain(['user_id' => $s['payer']->id, 'act' => 'registered_payment'])
        ->and((int) $finalization->properties['segregation']['financial_exceptions']['checker_user_id'])->toBe($s['finalizer']->id)
        ->and($finalization->properties['financial_exceptions_accepted'][0]['assessment']['reference']['status'])->toBe('under')
        // O saldo não pago continua à vista: o aceite não o regulariza.
        ->and($payment->fresh()->amount)->toBe('60000.00');
});

it('keeps an exact payment free of independent acceptance', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], evidenceReceipt());
    $measurement = Governance::readyToFinalize($s, $measurement);

    expect(app(MeasurementSegregationService::class)->financialExceptionMakers($measurement))->toBe([])
        ->and(app(MeasurementWorkflow::class)->finalizationBlockReason($measurement, $s['payer']))->toBeNull();
});

// ── I. R0 sem desembolso ────────────────────────────────────────────────────

it('finalizes a no-disbursement original without any receipt', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [0]);

    app(MeasurementWorkflow::class)->approve($measurement->fresh(), $s['payer'], 'Sem avanço físico na competência.', expectedStage: MeasurementWorkflow::STAGE_PAYMENT, withoutDisbursement: true);

    expect($measurement->fresh()->status)->toBe('approved');
    Notification::assertSentTo($s['finalizer'], MeasurementWorkflowNotification::class, fn ($notification): bool => $notification->event === 'ready_to_finalize_without_disbursement');

    $measurement = Governance::finalize($s, $measurement, acknowledgeNoDisbursement: true);

    expect($measurement->status)->toBe('finalized')
        ->and(MeasurementPayment::query()->count())->toBe(0)
        ->and(MeasurementPaymentReceiptEvidence::query()->count())->toBe(0);
});

// ── J. Revisão R1 ───────────────────────────────────────────────────────────

it('keeps each family member payment with its own receipt through an R1', function () {
    $s = evidenceSingle();
    $r0 = Governance::finalized($s, '2026-06', [10]);
    $r0Payment = $r0->payments()->sole();
    $r0Evidence = evidenceCurrent($r0Payment);
    $r0State = $r0Payment->getRawOriginal();

    $r1 = Governance::revisionAtCompliance($s, $r0, [12]);
    app(MeasurementWorkflow::class)->approve($r1->fresh(), $s['compliance']);
    $r1Payment = Governance::pay($s, $r1->fresh(), [Governance::row($s, 0, '20000.00')])->sole();
    Governance::readyToFinalize($s, $r1);
    $r1 = Governance::finalize($s, $r1);

    expect($r1->status)->toBe('finalized')
        ->and($r0Payment->fresh()->getRawOriginal())->toBe($r0State)
        ->and($r0Payment->receiptEvidences()->count())->toBe(1)
        ->and(evidenceCurrent($r0Payment)->id)->toBe($r0Evidence->id)
        ->and((int) $r1Payment->measurement_id)->toBe($r1->id)
        ->and($r1Payment->receiptEvidences()->count())->toBe(1)
        ->and(evidenceCurrent($r1Payment)->review_status)->toBe(MeasurementReceiptReviewStatus::Approved);
});

// ── K. Medição paga não volta à Engenharia ──────────────────────────────────

it('does not return a paid measurement with a rejected receipt to Engineering but accepts the replacement', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $payment = evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], evidenceReceipt())->sole();
    Governance::approvePaymentStage($s, $measurement);
    evidenceReview(evidenceCurrent($payment), $s['finalizer'], MeasurementReceiptReviewStatus::Rejected, 'Comprovante ilegível.');

    $refusal = evidenceThrown(fn () => app(MeasurementWorkflow::class)->returnToStage($measurement->fresh(), $s['finalizer'], MeasurementWorkflow::STAGE_ENGINEERING, 'Refazer a medição por causa do comprovante.'));

    expect($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(MeasurementWorkflow::PAID_ENGINEERING_RETURN_REFUSAL)
        ->and($measurement->fresh()->current_stage)->toBe(MeasurementWorkflow::STAGE_FINALIZATION);

    $replacement = app(MeasurementReceiptEvidenceService::class)->upload($payment->fresh(), $s['uploader'], evidenceReceipt('legivel.pdf'), evidenceCurrent($payment)->id, 'Comprovante legível.');

    expect($replacement->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($replacement->version)->toBe(2);
});

// ── L/M. Pendências e conferente independente ───────────────────────────────

it('does not present a segregated Finalizer as the decision maker and asks for an independent one', function () {
    $s = evidenceSingle();
    evidenceAssign($s, ['payment_finalizer_user_id' => $s['payer']->id]);
    $s['payer']->givePermissionTo('measurements.finalize');
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], evidenceReceipt());
    Governance::approvePaymentStage($s, $measurement);

    Notification::assertNotSentTo($s['payer'], MeasurementWorkflowNotification::class, fn ($notification): bool => $notification->event === 'receipts_awaiting_review');
    Notification::assertSentTo([$s['coordinator'], $s['admin']], MeasurementWorkflowNotification::class, fn ($notification): bool => $notification->event === 'independent_finalizer_required');

    $summary = app(MeasurementPendingService::class)->summaryFor($s['payer']);
    $item = collect($summary['items'])->firstWhere('responsibility', 'finalizer');

    expect($summary['count'])->toBe(0)
        ->and($summary['blocked_count'])->toBe(1)
        ->and($item['blocked'])->toBeTrue()
        ->and($item['needs_independent_reviewer'])->toBeFalse()
        ->and($item['action_label'])->toBe('Aguardando conferente independente')
        ->and($item['blocked_reason'])->toStartWith(MeasurementSegregationService::RECEIPT_SELF_APPROVAL);

    // O administrador independente vê a conferência como ação dele.
    $adminSummary = app(MeasurementPendingService::class)->summaryFor($s['admin']);
    expect(collect($adminSummary['items'])->firstWhere('measurement_id', $measurement->id)['blocked'] ?? null)->toBeFalse();
});

it('flags the missing independent Finalizer instead of bypassing segregation', function () {
    $s = evidenceSingle();
    evidenceAssign($s, ['payment_finalizer_user_id' => $s['payer']->id]);
    $s['payer']->givePermissionTo('measurements.finalize');
    $s['admin']->removeRole('admin');
    $s['superAdmin']->removeRole('super-admin');
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $payment = evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], evidenceReceipt())->sole();
    Governance::approvePaymentStage($s, $measurement);

    $segregation = app(MeasurementSegregationService::class);
    $item = collect(app(MeasurementPendingService::class)->summaryFor($s['payer'])['items'])->firstWhere('responsibility', 'finalizer');

    expect($segregation->independentFinalizerExists($measurement->fresh()))->toBeFalse()
        ->and($item['needs_independent_reviewer'])->toBeTrue()
        ->and($item['action_label'])->toBe('Sem conferente independente — designe outro responsável')
        ->and($item['blocked_reason'])->toContain(MeasurementSegregationService::NO_INDEPENDENT_FINALIZER)
        ->and(evidenceThrown(fn () => evidenceReview(evidenceCurrent($payment), $s['payer'])))->toBeInstanceOf(MeasurementSegregationException::class)
        ->and(evidenceCurrent($payment)->review_status)->toBe(MeasurementReceiptReviewStatus::Pending);
});

// ── N. Medição recusada é imutável ──────────────────────────────────────────

it('keeps a rejected original measurement read-only on every update path', function () {
    $s = evidenceSingle();
    $measurement = Measurement::factory()->create([
        'operation_id' => $s['operation']->id,
        'reference_month' => '2026-06-01',
        'status' => 'pending',
        'current_stage' => MeasurementWorkflow::STAGE_ENGINEERING,
        'notes' => 'Medição original.',
        'storage_path' => null,
        'filename' => null,
        'uploaded_by' => $s['coordinator']->id,
    ]);
    app(MeasurementWorkflow::class)->startReview($measurement, $s['coordinator']);
    app(MeasurementWorkflow::class)->reject($measurement->fresh(), $s['engineer'], 'Arquivo de outra obra.');
    $measurement = $measurement->fresh();

    expect($measurement->status)->toBe('rejected')
        ->and($measurement->acceptsEdits())->toBeFalse()
        ->and($s['coordinator']->can('update', $measurement))->toBeFalse()
        ->and($s['coordinator']->can('view', $measurement))->toBeTrue();

    $refusal = evidenceThrown(fn () => $measurement->update(['notes' => 'Reescrita depois da recusa.']));
    expect($refusal)->toBeInstanceOf(MeasurementWorkflowException::class)
        ->and($refusal->getMessage())->toBe(Measurement::TERMINAL_CONTENT_CHANGE_REFUSAL);

    // O super-admin passa pela policy, e o model segura a gravação forjada.
    $this->actingAs($s['superAdmin']);
    Livewire\Livewire::test(EditMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->set('data.notes', 'Forjada pelo editar.')
        ->call('save');

    expect($measurement->fresh()->notes)->toBe('Medição original.');

    $this->actingAs($s['coordinator'])
        ->get(MeasurementResource::getUrl('edit', ['record' => $measurement]))
        ->assertRedirect(MeasurementResource::getUrl('view', ['record' => $measurement]));
});

// ── O. Autoria protegida ────────────────────────────────────────────────────

it('keeps the asset authorship the segregation reads under protected retention', function () {
    $workflowDays = (int) config('audit.retention_workflow_days');
    $disposableDays = (int) config('audit.retention_disposable_days');
    $kept = Activity::query()->create([
        'log_name' => 'measurement_assets',
        'description' => 'measurement_asset_replaced',
        'properties' => json_encode(['measurement_id' => 1]),
        'created_at' => now()->subDays($disposableDays + 10),
        'updated_at' => now()->subDays($disposableDays + 10),
    ]);

    $this->artisan('audit:clean-filtered')->assertExitCode(0);

    expect(config('audit.protected_logs'))->toContain('measurement_assets', 'measurement_evidence', 'measurement_workflow')
        ->and($workflowDays)->toBeGreaterThan($disposableDays)
        ->and(Activity::query()->whereKey($kept->getKey())->exists())->toBeTrue();
});

// ── Exclusão de emissão ─────────────────────────────────────────────────────

it('refuses deleting an emission whose operation has measurement history', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $this->actingAs($s['superAdmin']);

    $refusal = evidenceThrown(fn () => $s['emission']->fresh()->delete());

    expect($refusal)->toBeInstanceOf(OperationLifecycleException::class)
        ->and($refusal->getMessage())->toBe(OperationLifecycleException::emissionMeasurementHistory()->getMessage())
        ->and($s['emission']->fresh())->not->toBeNull()
        ->and($measurement->fresh())->not->toBeNull();
});

// ── Manutenção de arquivos legados ──────────────────────────────────────────

it('leaves the legacy file journal untouched on a dry-run that fails', function () {
    $journal = MeasurementFileMigration::query()->create([
        'migratable_type' => MeasurementPayment::class,
        'migratable_id' => 999999,
        'file_role' => 'receipts',
        'source_disk' => 'public',
        'source_path' => 'measurements/receipts/legado.pdf',
        'source_sha256' => str_repeat('a', 64),
        'destination_disk' => 'local',
        'destination_path' => 'nimbus_docs/measurements/receipts/legado.pdf',
        'recovery_path' => 'nimbus_docs/measurements/receipts/legado.recovery.pdf',
        'state' => MeasurementFileMigration::STATE_PREPARED,
    ]);
    $state = $journal->fresh()->getRawOriginal();
    config()->set('filesystems.private_disk', 'local');
    Storage::fake('public');

    $this->artisan('measurements:secure-legacy-files');

    expect($journal->fresh()->getRawOriginal())->toBe($state);
});

// ── Interface ───────────────────────────────────────────────────────────────

/**
 * A chave do item do Repeater de pagamentos do empreendimento.
 */
function evidenceUiItemKey(Testable $component, int $planSetId): string
{
    return (string) collect($component->get('mountedActions.0.data.payments') ?? [])
        ->search(fn (array $item): bool => (int) ($item['plan_set_id'] ?? 0) === $planSetId);
}

it('registers payments of two developments paid by one transfer with a single receipt in the modal', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10, 10]);
    $this->actingAs($s['payer']);

    $component = Livewire\Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->set('mountedActions.0.data.pay_date', Governance::PAY_DATE);

    expect($component->get('mountedActions.0.data.single_transfer'))->toBeTrue();
    $north = evidenceUiItemKey($component, $s['planSets'][0]->id);
    $south = evidenceUiItemKey($component, $s['planSets'][1]->id);

    $component->set("mountedActions.0.data.payments.{$north}.amount", '100.000,00')
        ->set("mountedActions.0.data.payments.{$south}.amount", '50.000,00')
        ->callMountedAction()
        ->assertHasFormErrors(['receipt' => 'required']);

    expect(MeasurementPayment::query()->count())->toBe(0);

    $component->set('mountedActions.0.data.receipt', evidenceReceipt('ted-unica.pdf'))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $payments = MeasurementPayment::query()->orderBy('id')->get();
    expect($payments)->toHaveCount(2)
        ->and(evidenceCurrent($payments[0])->sha256)->toBe(evidenceCurrent($payments[1])->sha256)
        ->and(evidenceCurrent($payments[0])->original_filename)->toBe('ted-unica.pdf');
});

it('asks for the receipt of each development when each one was paid by its own transfer', function () {
    $s = Governance::plan();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10, 10]);
    $this->actingAs($s['payer']);

    $component = Livewire\Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->mountAction('registerPayment')
        ->set('mountedActions.0.data.pay_date', Governance::PAY_DATE)
        ->set('mountedActions.0.data.single_transfer', false);
    $north = evidenceUiItemKey($component, $s['planSets'][0]->id);
    $south = evidenceUiItemKey($component, $s['planSets'][1]->id);

    $component->set("mountedActions.0.data.payments.{$north}.amount", '100.000,00')
        ->set("mountedActions.0.data.payments.{$south}.amount", '50.000,00')
        ->set("mountedActions.0.data.payments.{$north}.receipt", evidenceReceipt('norte.pdf'))
        ->callMountedAction()
        ->assertHasFormErrors(["payments.{$south}.receipt" => 'required']);

    expect(MeasurementPayment::query()->count())->toBe(0);

    $component->set("mountedActions.0.data.payments.{$south}.receipt", evidenceReceipt('sul.pdf'))
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $payments = MeasurementPayment::query()->orderBy('id')->get();
    expect(evidenceCurrent($payments[0])->original_filename)->toBe('norte.pdf')
        ->and(evidenceCurrent($payments[1])->original_filename)->toBe('sul.pdf');
});

it('keeps the Finalization disabled until every receipt is approved, and shows the receipts to review', function () {
    $s = evidenceSingle();
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    $payment = evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], evidenceReceipt('ted-junho.pdf'))->sole();
    Governance::approvePaymentStage($s, $measurement);
    $this->actingAs($s['finalizer']);

    Livewire\Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionVisible('reviewReceipt')
        ->assertActionVisible('finalize')
        ->assertActionDisabled('finalize');

    evidenceReview(evidenceCurrent($payment), $s['finalizer']);

    $component = Livewire\Livewire::test(ViewMeasurement::class, ['record' => $measurement->getRouteKey()])
        ->assertActionHidden('reviewReceipt')
        ->assertActionEnabled('finalize');

    // O quadro de comprovantes da Finalização: cada pagamento, a versão, quem
    // enviou e quem conferiu, com o link autorizado -- nunca o caminho privado.
    $review = view('filament.infolists.measurement-finalization-evidences', ['measurement' => $measurement->fresh()])->render();
    expect($component->instance()->getAction('finalize')->getModalHeading())->toBe('Conferência e Finalização')
        ->and($review)->toContain('Pagamento #'.$payment->id)
        ->and($review)->toContain('Aprovado documentalmente')
        ->and($review)->toContain('enviado por '.$s['payer']->name)
        ->and($review)->toContain('conferido por '.$s['finalizer']->name)
        ->and($review)->toContain(route('admin.measurements.receipt-evidences.download', ['payment' => $payment, 'evidence' => evidenceCurrent($payment)]))
        ->and($review)->not->toContain(evidenceCurrent($payment)->storage_path);

    $component->callAction('finalize')->assertHasNoActionErrors();

    expect($measurement->fresh()->status)->toBe('finalized');
});

it('shows the Finalization of the payment workspace as blocked for the segregated Finalizer', function () {
    $s = evidenceSingle();
    evidenceAssign($s, ['payment_finalizer_user_id' => $s['payer']->id]);
    $s['payer']->givePermissionTo('measurements.finalize');
    $measurement = Governance::awaitingPayment($s, '2026-06', [10]);
    evidenceRegister($s, $measurement, [Governance::row($s, 0, '100000.00')], evidenceReceipt());
    Governance::approvePaymentStage($s, $measurement);
    $this->actingAs($s['payer']);

    $component = Livewire\Livewire::test(ListPaymentWorkspace::class)
        ->assertTableActionVisible('finalize', $measurement)
        ->assertTableActionDisabled('finalize', $measurement);

    expect($component->instance()->getTable()->getAction('finalize')->record($measurement)->getLabel())->toBe('Aguardando conferente independente');
});

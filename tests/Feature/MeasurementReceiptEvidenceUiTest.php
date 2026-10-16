<?php

use App\Enums\MeasurementReceiptReviewStatus;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Filament\Widgets\Dashboard\MyPendingsWidget;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementPendingService;
use App\Services\MeasurementReceiptEvidenceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementReceiptEvidenceScenario as Scenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

/**
 * O comprovante legado materializado, como em {@see Scenario::legacy()}. A
 * Fase 4B-2 passou a criar a v1 no registro do pagamento, o que impediria a
 * materialização: o pagamento legado é anterior à evidência, então a do
 * registro sai direto da tabela antes de gravar as colunas legadas.
 *
 * @return array{actor: User, finalizer: User, operation: Operation, measurement: Measurement, payment: MeasurementPayment}
 */
function receiptEvidenceUiTestLegacy(bool $finalized = true): array
{
    $scenario = Scenario::open();
    $payment = $scenario['payment'];
    foreach (DB::table('measurement_payment_receipt_evidences')->where('measurement_payment_id', $payment->id)->get() as $row) {
        Storage::disk($row->storage_disk)->delete($row->storage_path);
    }
    DB::table('measurement_payment_receipt_evidences')->where('measurement_payment_id', $payment->id)->delete();

    $path = 'nimbus_docs/measurements/receipts/legacy-'.$payment->id.'.pdf';
    $bytes = '%PDF-1.7 manual de logotipos controlado';
    Storage::disk('local')->put($path, $bytes);
    DB::table('measurement_payments')->where('id', $payment->id)->update([
        'receipt_disk' => 'local', 'receipt_path' => $path,
        'receipt_sha256' => hash('sha256', $bytes),
        'receipt_mime_type' => 'application/pdf', 'receipt_size' => strlen($bytes),
        'receipt_uploaded_by' => $scenario['actor']->id, 'receipt_uploaded_at' => '2026-09-07 12:30:00',
    ]);

    if ($finalized) {
        $scenario['measurement']->forceFill(['status' => 'finalized', 'current_stage' => 5, 'analyzed_at' => '2026-09-08 15:00:00', 'analyzed_by' => $scenario['actor']->id])->save();
        $scenario['measurement']->reviews()->updateOrCreate(['stage' => 5], ['status' => 'approved', 'reviewer_user_id' => $scenario['actor']->id, 'reviewed_at' => '2026-09-08 15:00:00']);
        activity('measurement_workflow')->performedOn($scenario['measurement'])->causedBy($scenario['actor'])
            ->withProperties(['stage' => 5, 'from_status' => 'approved', 'to_status' => 'finalized', 'operation_id' => $scenario['operation']->id, 'measurement_id' => $scenario['measurement']->id, 'responsibility' => 'payment_finalizer_user_id', 'expected_responsible_user_id' => $scenario['actor']->id, 'workflow_revision' => $scenario['measurement']->workflow_revision, 'admin_override' => false, 'delegated' => false])
            ->createdAt(Carbon::parse('2026-09-08 15:00:00'))->log('measurement_finalized');
    }

    Scenario::backfill();
    $scenario['payment']->refresh();
    $scenario['measurement']->refresh();

    return $scenario;
}

it('replaces the registration receipt through Filament as pending and requires human confirmation before approval', function () {
    // Fase 4B-2: a v1 chega com o registro do pagamento; o envio na Finalização é a nova versão, com o motivo.
    $scenario = Scenario::open();
    $first = $scenario['payment']->currentReceiptEvidence;
    expect($first->version)->toBe(1);
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->assertActionVisible('attachReceipt')
        ->assertActionHidden('correctReceipt')
        ->callAction('attachReceipt', data: [
            'payment_id' => $scenario['payment']->id,
            'receipt' => Scenario::file(),
            'expected_revision' => $scenario['measurement']->workflow_revision,
            'expected_evidence_id' => $first->id,
            'correction_reason' => 'Comprovante com o banco legível',
        ])->assertHasNoActionErrors();

    $evidence = $scenario['payment']->fresh()->currentReceiptEvidence;
    expect($evidence->version)->toBe(2)
        ->and($evidence->supersedes_id)->toBe($first->id)
        ->and($evidence->uploaded_by)->toBe($scenario['actor']->id)
        ->and($evidence->correction_reason)->toBe('Comprovante com o banco legível')
        ->and($evidence->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($scenario['measurement']->fresh()->status)->toBe('awaiting_receipt');

    // Fase 4B-1: quem enviou o comprovante não o aprova -- confere o Finalizador.
    $this->actingAs($scenario['finalizer']);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->assertSee('Pendente de conferência documental')
        ->callAction('reviewReceipt', data: [
            'evidence_id' => $evidence->id, 'decision' => 'approved', 'confirmed' => false,
        ])->assertHasActionErrors(['confirmed']);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->callAction('reviewReceipt', data: [
            'evidence_id' => $evidence->id, 'decision' => 'approved', 'confirmed' => true,
        ])->assertHasNoActionErrors();
    expect($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Approved);
});

it('requires a rejection reason in the review action', function () {
    $scenario = Scenario::open();
    $evidence = $scenario['payment']->currentReceiptEvidence;
    // Fase 4B-1: a conferência é do Finalizador, distinto de quem envia.
    $this->actingAs($scenario['finalizer']);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->callAction('reviewReceipt', data: [
            'evidence_id' => $evidence->id, 'decision' => 'rejected', 'confirmed' => true, 'rejection_reason' => '',
        ])->assertHasActionErrors(['rejection_reason']);
    expect($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending);
});

it('shows finalized separately from the correction and renders each version with its secure link', function () {
    $scenario = receiptEvidenceUiTestLegacy();
    $this->actingAs($scenario['actor']);
    $first = $scenario['payment']->currentReceiptEvidence;
    $before = $scenario['measurement']->getRawOriginal();

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->assertSee('Finalizada')
        ->assertSee('Sem pendência')
        ->assertSee('Nome original não registrado no fluxo legado')
        ->assertSee('Legado — sem revisão documental individual')
        ->assertActionHidden('attachReceipt')
        ->assertActionVisible('correctReceipt')
        ->callAction('correctReceipt', data: [
            'payment_id' => $scenario['payment']->id,
            'expected_evidence_id' => $first->id,
            'receipt' => Scenario::file('correcao.pdf'),
            'correction_reason' => 'Documento correspondente ao pagamento',
        ])->assertHasNoActionErrors();

    $second = $scenario['payment']->fresh()->currentReceiptEvidence;
    // Fase 4B-1: quem enviou a correção não a aprova -- confere o Finalizador.
    $this->actingAs($scenario['finalizer']);
    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->assertSee('Finalizada')
        ->assertSee('Correção documental pendente')
        ->assertSee('v2 · Atual')
        ->assertSee('v1 · Substituída por v2')
        ->assertSee('correcao.pdf')
        ->assertSee(route('admin.measurements.receipt-evidences.download', ['payment' => $scenario['payment'], 'evidence' => $first]), false)
        ->assertSee(route('admin.measurements.receipt-evidences.download', ['payment' => $scenario['payment'], 'evidence' => $second]), false)
        ->assertActionVisible('reviewReceipt')
        ->callAction('reviewReceipt', data: [
            'evidence_id' => $second->id, 'decision' => 'approved', 'confirmed' => true,
        ])->assertHasNoActionErrors();

    expect($scenario['measurement']->fresh()->getRawOriginal())->toBe($before);
    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->assertSee('Finalizada')->assertSee('Correção documental regularizada');
});

it('hides documentary actions from a participant with permissions but no matching responsibility', function () {
    $scenario = receiptEvidenceUiTestLegacy();
    $service = app(MeasurementReceiptEvidenceService::class);
    $service->correctFinalizedReceipt($scenario['payment'], $scenario['actor'], Scenario::file(), 'Correção', $scenario['payment']->currentReceiptEvidence->id);
    $participant = User::factory()->withTwoFactor()->create();
    $participant->givePermissionTo(['measurements.view', 'measurements.receipts', 'measurements.finalize']);
    $scenario['operation']->forceFill(['assigned_user_id' => $participant->id])->save();
    $this->actingAs($participant);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->assertSuccessful()
        ->assertActionHidden('attachReceipt')
        ->assertActionHidden('correctReceipt')
        ->assertActionHidden('reviewReceipt');
});

it('distinguishes receipt upload and review filters including finalized corrections with legacy payments', function (bool $finalized) {
    $scenario = $finalized ? receiptEvidenceUiTestLegacy() : Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    if ($finalized) {
        $payment = MeasurementPayment::factory()->create([
            'measurement_id' => $scenario['measurement']->id,
            'operation_id' => $scenario['operation']->id,
            'plan_set_id' => $scenario['payment']->plan_set_id,
        ]);
        $evidence = $service->correctFinalizedReceipt($payment, $scenario['actor'], Scenario::file(), 'Novo documento', null);
    } else {
        // Fase 4B-2: a v1 pendente vem do registro do pagamento.
        $evidence = $scenario['payment']->currentReceiptEvidence;
    }
    $this->actingAs($scenario['actor']);

    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('operational_pending', 'awaiting_receipt_review')
        ->assertCanSeeTableRecords([$scenario['measurement']])
        ->assertSee($finalized ? 'Conferir correção documental' : 'Conferir comprovante');
    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('operational_pending', 'awaiting_receipt')
        ->assertCanNotSeeTableRecords([$scenario['measurement']]);
    Livewire::test(ListMeasurements::class)
        ->assertSee($finalized ? 'Conferir correção documental' : 'Conferir comprovante');

    // Fase 4B-1: a conferência é do Finalizador, distinto de quem envia.
    $service->review($evidence, $scenario['finalizer'], MeasurementReceiptReviewStatus::Rejected, true, rejectionReason: 'Substituir');
    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('operational_pending', 'awaiting_receipt')
        ->assertCanSeeTableRecords([$scenario['measurement']]);
    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('operational_pending', 'awaiting_receipt_review')
        ->assertCanNotSeeTableRecords([$scenario['measurement']]);
})->with([false, true]);

it('renders both documentary actions for a user holding upload and review responsibilities', function () {
    $scenario = Scenario::open();
    // Fase 4B-1: o cenário separa o Finalizador; aqui a mesma pessoa volta a
    // responder pelo envio e pela conferência -- a segregação é por ato (quem
    // enviou não aprova), não por papel.
    $scenario['operation']->forceFill(['payment_finalizer_user_id' => $scenario['actor']->id])->save();
    MeasurementPayment::factory()->create([
        'measurement_id' => $scenario['measurement']->id,
        'operation_id' => $scenario['operation']->id,
        'plan_set_id' => $scenario['payment']->plan_set_id,
    ]);
    // Fase 4B-2: a v1 do registro foi enviada pelo próprio ator -- a conferência
    // dela aparece bloqueada para ele, não como ação sua.
    $summary = app(MeasurementPendingService::class)->summaryFor($scenario['actor']);
    expect($summary['count'])->toBe(1)
        ->and($summary['blocked_count'])->toBe(1);

    // Com a versão atual enviada por outra pessoa, o ator responde pelas duas ações.
    $uploader = User::factory()->withTwoFactor()->create();
    $uploader->givePermissionTo(['measurements.view', 'measurements.receipts']);
    $scenario['operation']->forceFill(['payment_receipt_uploader_user_id' => $uploader->id])->save();
    app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $uploader, Scenario::file(), $scenario['payment']->currentReceiptEvidence->id, 'Comprovante legível');
    $scenario['operation']->forceFill(['payment_receipt_uploader_user_id' => $scenario['actor']->id])->save();

    $summary = app(MeasurementPendingService::class)->summaryFor($scenario['actor']);
    expect($summary['count'])->toBe(2)
        ->and($summary['blocked_count'])->toBe(0)
        ->and(collect($summary['items'])->pluck('responsibility')->unique())->toHaveCount(2);

    $this->actingAs($scenario['actor']);
    Livewire::test(MyPendingsWidget::class)
        ->assertSee('Enviar comprovante')
        ->assertSee('Conferir comprovante');
});

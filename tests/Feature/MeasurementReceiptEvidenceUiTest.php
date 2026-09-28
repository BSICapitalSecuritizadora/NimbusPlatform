<?php

use App\Enums\MeasurementReceiptReviewStatus;
use App\Filament\Resources\Measurements\Pages\ListMeasurements;
use App\Filament\Resources\Measurements\Pages\ViewMeasurement;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Filament\Widgets\Dashboard\MyPendingsWidget;
use App\Models\MeasurementPayment;
use App\Models\User;
use App\Services\MeasurementPendingService;
use App\Services\MeasurementReceiptEvidenceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementReceiptEvidenceScenario as Scenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

it('uploads through Filament as pending and requires human confirmation before approval', function () {
    $scenario = Scenario::open();
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->assertActionVisible('attachReceipt')
        ->assertActionHidden('correctReceipt')
        ->callAction('attachReceipt', data: [
            'payment_id' => $scenario['payment']->id,
            'receipt' => Scenario::file(),
            'expected_revision' => $scenario['measurement']->workflow_revision,
            'expected_evidence_id' => null,
        ])->assertHasNoActionErrors();

    $evidence = $scenario['payment']->fresh()->currentReceiptEvidence;
    expect($evidence->review_status)->toBe(MeasurementReceiptReviewStatus::Pending)
        ->and($scenario['measurement']->fresh()->status)->toBe('awaiting_receipt');

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
    $evidence = app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    $this->actingAs($scenario['actor']);

    Livewire::test(ViewMeasurement::class, ['record' => $scenario['measurement']->id])
        ->callAction('reviewReceipt', data: [
            'evidence_id' => $evidence->id, 'decision' => 'rejected', 'confirmed' => true, 'rejection_reason' => '',
        ])->assertHasActionErrors(['rejection_reason']);
    expect($evidence->fresh()->review_status)->toBe(MeasurementReceiptReviewStatus::Pending);
});

it('shows finalized separately from the correction and renders each version with its secure link', function () {
    $scenario = Scenario::legacy();
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
    $scenario = Scenario::legacy();
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
    $scenario = $finalized ? Scenario::legacy() : Scenario::open();
    $service = app(MeasurementReceiptEvidenceService::class);
    if ($finalized) {
        $payment = MeasurementPayment::factory()->create([
            'measurement_id' => $scenario['measurement']->id,
            'operation_id' => $scenario['operation']->id,
            'plan_set_id' => $scenario['payment']->plan_set_id,
        ]);
        $evidence = $service->correctFinalizedReceipt($payment, $scenario['actor'], Scenario::file(), 'Novo documento', null);
    } else {
        $evidence = $service->upload($scenario['payment'], $scenario['actor'], Scenario::file());
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

    $service->review($evidence, $scenario['actor'], MeasurementReceiptReviewStatus::Rejected, true, rejectionReason: 'Substituir');
    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('operational_pending', 'awaiting_receipt')
        ->assertCanSeeTableRecords([$scenario['measurement']]);
    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('operational_pending', 'awaiting_receipt_review')
        ->assertCanNotSeeTableRecords([$scenario['measurement']]);
})->with([false, true]);

it('renders both documentary actions for a user holding upload and review responsibilities', function () {
    $scenario = Scenario::open();
    app(MeasurementReceiptEvidenceService::class)->upload($scenario['payment'], $scenario['actor'], Scenario::file());
    MeasurementPayment::factory()->create([
        'measurement_id' => $scenario['measurement']->id,
        'operation_id' => $scenario['operation']->id,
        'plan_set_id' => $scenario['payment']->plan_set_id,
    ]);
    $summary = app(MeasurementPendingService::class)->summaryFor($scenario['actor']);
    expect($summary['count'])->toBe(2)
        ->and(collect($summary['items'])->pluck('responsibility')->unique())->toHaveCount(2);

    $this->actingAs($scenario['actor']);
    Livewire::test(MyPendingsWidget::class)
        ->assertSee('Enviar comprovante')
        ->assertSee('Conferir comprovante');
});

<?php

use App\Enums\MeasurementResponsibility;
use App\Filament\Resources\Measurements\MeasurementResource;
use App\Filament\Resources\PaymentWorkspaces\Pages\ListPaymentWorkspace;
use App\Models\Measurement;
use App\Models\MeasurementPayment;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementOperationalReadModel;
use App\Services\MeasurementSlaService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    config(['measurements.sla.calendar_code' => null]);

    foreach (range(1, 5) as $stage) {
        SlaConfiguration::factory()->forStage($stage, 5)->create();
    }
});

/** @param list<string> $permissions */
function p2WorkspaceUser(array $permissions = [], bool $withTwoFactor = false): User
{
    $factory = User::factory();

    if ($withTwoFactor) {
        $factory = $factory->withTwoFactor();
    }

    $user = $factory->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo(array_values(array_unique([
        'operations.view',
        'measurements.view',
        ...$permissions,
    ])));

    return $user;
}

/**
 * @param  array<string, mixed>  $operationAttributes
 * @param  array<string, mixed>  $measurementAttributes
 */
function p2WorkspaceMeasurement(
    array $operationAttributes,
    array $measurementAttributes = [],
): Measurement {
    $operation = Operation::factory()->create($operationAttributes);
    $measurement = Measurement::factory()->create(array_merge([
        'operation_id' => $operation->getKey(),
        'status' => 'awaiting_payment',
        'current_stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'reference_month' => '2026-08-01',
    ], $measurementAttributes));

    if (! in_array($measurement->status, ['finalized', 'rejected'], true)) {
        $measurement->reviews()->create([
            'stage' => $measurement->current_stage,
            'status' => 'pending',
            'created_at' => now()->subDay(),
        ]);
    }

    return $measurement->fresh(['operation', 'reviews', 'pauses']);
}

it('lists only payments whose measurements are visible in the user scope', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $otherManager = p2WorkspaceUser(['measurements.pay']);
    $visible = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);
    $hidden = p2WorkspaceMeasurement(['payment_manager_user_id' => $otherManager->getKey()]);

    $this->actingAs($manager);

    Livewire::test(ListPaymentWorkspace::class)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden]);
});

it('does not turn a global payment permission into portfolio visibility', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $outsider = p2WorkspaceUser(['measurements.pay']);
    $measurement = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);

    expect(app(MeasurementOperationalReadModel::class)->paymentQueryFor($outsider)->whereKey($measurement)->exists())
        ->toBeFalse();
});

it('shows a payment-manager assignment as direct participation', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $measurement = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);

    $direct = app(MeasurementOperationalReadModel::class)
        ->applyAssignmentFilter(Measurement::query()->visibleTo($manager), $manager, 'direct');

    expect($direct->whereKey($measurement)->exists())->toBeTrue();
});

it('allows an effective payment-manager delegate to see the workspace row', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $delegate = p2WorkspaceUser(['measurements.pay']);
    $measurement = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);
    ResponsibilityDelegation::factory()->active()->forStage(
        MeasurementWorkflow::STAGE_PAYMENT,
        $measurement->operation,
        MeasurementResponsibility::PaymentManager,
    )->create([
        'delegator_user_id' => $manager->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    expect(app(MeasurementOperationalReadModel::class)->paymentQueryFor($delegate)->whereKey($measurement)->exists())
        ->toBeTrue();

    $this->actingAs($delegate);
    Livewire::test(ListPaymentWorkspace::class)
        ->assertCanSeeTableRecords([$measurement])
        ->assertSee('Delegações ativas');
});

it('labels effective operational delegates without replacing permanent responsibilities', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $uploader = p2WorkspaceUser(['measurements.receipts']);
    $finalizer = p2WorkspaceUser(['measurements.finalize']);
    $managerDelegate = p2WorkspaceUser(['measurements.pay']);
    $uploaderDelegate = p2WorkspaceUser(['measurements.receipts']);
    $finalizerDelegate = p2WorkspaceUser(['measurements.finalize']);
    $measurement = p2WorkspaceMeasurement([
        'payment_manager_user_id' => $manager->getKey(),
        'payment_receipt_uploader_user_id' => $uploader->getKey(),
        'payment_finalizer_user_id' => $finalizer->getKey(),
    ]);

    foreach ([
        [MeasurementResponsibility::PaymentManager, $manager, $managerDelegate],
        [MeasurementResponsibility::ReceiptUploader, $uploader, $uploaderDelegate],
        [MeasurementResponsibility::Finalizer, $finalizer, $finalizerDelegate],
    ] as [$responsibility, $delegator, $delegate]) {
        ResponsibilityDelegation::factory()->active()->forStage(
            $responsibility->stage(),
            $measurement->operation,
            $responsibility,
        )->create([
            'delegator_user_id' => $delegator->getKey(),
            'delegate_user_id' => $delegate->getKey(),
        ]);
    }

    $loaded = app(MeasurementOperationalReadModel::class)->queryFor($manager)->findOrFail($measurement->getKey());
    $label = app(MeasurementOperationalReadModel::class)->operationalDelegationLabel($loaded, $manager);

    expect($label)
        ->toContain('Gestor de Pagamento: '.$managerDelegate->name)
        ->toContain('Uploader de Comprovante: '.$uploaderDelegate->name)
        ->toContain('Finalizador: '.$finalizerDelegate->name)
        ->and($loaded->operation->payment_manager_user_id)->toBe($manager->getKey())
        ->and($loaded->operation->payment_receipt_uploader_user_id)->toBe($uploader->getKey())
        ->and($loaded->operation->payment_finalizer_user_id)->toBe($finalizer->getKey());
});

it('rejects expired and revoked payment delegations from visibility', function (string $state) {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $delegate = p2WorkspaceUser(['measurements.pay']);
    $measurement = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);
    $factory = ResponsibilityDelegation::factory()->forStage(
        MeasurementWorkflow::STAGE_PAYMENT,
        $measurement->operation,
        MeasurementResponsibility::PaymentManager,
    );

    ($state === 'expired' ? $factory->expired() : $factory->active()->revoked())->create([
        'delegator_user_id' => $manager->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    expect(app(MeasurementOperationalReadModel::class)->paymentQueryFor($delegate)->whereKey($measurement)->exists())
        ->toBeFalse();
})->with(['expired', 'revoked']);

it('keeps payment manager receipt uploader and finalizer actions separated', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $uploader = p2WorkspaceUser(['measurements.receipts']);
    $finalizer = p2WorkspaceUser(['measurements.finalize']);
    $operation = [
        'payment_manager_user_id' => $manager->getKey(),
        'payment_receipt_uploader_user_id' => $uploader->getKey(),
        'payment_finalizer_user_id' => $finalizer->getKey(),
    ];
    $paymentStage = p2WorkspaceMeasurement($operation);
    MeasurementPayment::factory()->create([
        'operation_id' => $paymentStage->operation_id,
        'measurement_id' => $paymentStage->getKey(),
        'created_by' => $manager->getKey(),
    ]);
    $receiptStage = p2WorkspaceMeasurement($operation, [
        'status' => 'awaiting_receipt',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    MeasurementPayment::factory()->create([
        'operation_id' => $receiptStage->operation_id,
        'measurement_id' => $receiptStage->getKey(),
        'created_by' => $manager->getKey(),
    ]);
    $finalizationStage = p2WorkspaceMeasurement($operation, [
        'status' => 'approved',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);

    $this->actingAs($manager);
    Livewire::test(ListPaymentWorkspace::class)
        ->assertTableActionVisible('register_payments', $paymentStage)
        ->assertTableActionVisible('approve_payment_stage', $paymentStage)
        ->assertTableActionHidden('attach_receipt', $receiptStage)
        ->assertTableActionHidden('finalize', $finalizationStage);

    $this->actingAs($uploader);
    Livewire::test(ListPaymentWorkspace::class)
        ->assertTableActionHidden('register_payments', $paymentStage)
        ->assertTableActionVisible('attach_receipt', $receiptStage)
        ->assertTableActionHidden('finalize', $finalizationStage);

    $this->actingAs($finalizer);
    Livewire::test(ListPaymentWorkspace::class)
        ->assertTableActionHidden('register_payments', $paymentStage)
        ->assertTableActionHidden('attach_receipt', $receiptStage)
        ->assertTableActionVisible('finalize', $finalizationStage);
});

it('filters the workspace by workflow stage and operation', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $payment = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);
    $finalization = p2WorkspaceMeasurement(
        ['payment_manager_user_id' => $manager->getKey()],
        ['status' => 'awaiting_receipt', 'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION],
    );
    $this->actingAs($manager);

    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('stage', MeasurementWorkflow::STAGE_PAYMENT)
        ->assertCanSeeTableRecords([$payment])
        ->assertCanNotSeeTableRecords([$finalization]);

    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('operation_id', $finalization->operation_id)
        ->assertCanSeeTableRecords([$finalization])
        ->assertCanNotSeeTableRecords([$payment]);
});

it('filters the workspace with the canonical SLA service', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $overdue = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);
    $onTime = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);
    $overdue->reviews()->update(['created_at' => now()->subDays(30)]);
    $onTime->reviews()->update(['created_at' => now()]);
    $this->actingAs($manager);

    Livewire::test(ListPaymentWorkspace::class)
        ->filterTable('sla_status', MeasurementSlaService::STATUS_OVERDUE)
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$onTime]);
});

it('does not leak invisible operation or emission metadata through search', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $hiddenManager = p2WorkspaceUser(['measurements.pay']);
    $visible = p2WorkspaceMeasurement([
        'title' => 'Operação Alfa Visível',
        'payment_manager_user_id' => $manager->getKey(),
    ]);
    $hidden = p2WorkspaceMeasurement([
        'title' => 'Operação Ômega Confidencial',
        'payment_manager_user_id' => $hiddenManager->getKey(),
    ]);
    $this->actingAs($manager);

    Livewire::test(ListPaymentWorkspace::class)
        ->set('tableSearch', 'Ômega Confidencial')
        ->assertCanNotSeeTableRecords([$visible, $hidden])
        ->assertDontSee('Operação Ômega Confidencial');
});

it('renders calendar unavailable without inventing another SLA meaning', function () {
    config(['measurements.sla.calendar_code' => 'CALENDAR_NOT_MATERIALIZED']);
    $manager = p2WorkspaceUser(['measurements.pay']);
    $measurement = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);
    $loaded = app(MeasurementOperationalReadModel::class)->queryFor($manager)->findOrFail($measurement->getKey());

    expect(app(MeasurementOperationalReadModel::class)->slaLabel($loaded))
        ->toBe('Calendário indisponível');
});

it('denies a forged measurement identifier in the resource backend', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $outsider = p2WorkspaceUser(['measurements.pay'], withTwoFactor: true);
    $measurement = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);

    expect($outsider->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('viewAny', Measurement::class))->toBeTrue()
        ->and(Gate::forUser($outsider)->denies('view', $measurement))->toBeTrue();

    $this->actingAs($outsider)
        ->get(MeasurementResource::getUrl('view', ['record' => $measurement]))
        ->assertNotFound();
});

it('does not expose bulk mutations or mutate workflow state while browsing', function () {
    $manager = p2WorkspaceUser(['measurements.pay']);
    $measurement = p2WorkspaceMeasurement(['payment_manager_user_id' => $manager->getKey()]);
    MeasurementPayment::factory()->create([
        'operation_id' => $measurement->operation_id,
        'measurement_id' => $measurement->getKey(),
        'created_by' => $manager->getKey(),
    ]);
    $measurementState = $measurement->fresh()->only(['status', 'current_stage', 'workflow_revision']);
    $reviewState = $measurement->reviews()
        ->orderBy('id')
        ->get(['id', 'stage', 'status', 'reviewer_user_id', 'notes'])
        ->toArray();
    $paymentState = $measurement->payments()
        ->orderBy('id')
        ->get([
            'id',
            'measurement_id',
            'amount',
            'pay_date',
            'receipt_path',
            'receipt_disk',
            'receipt_sha256',
            'receipt_uploaded_by',
            'receipt_uploaded_at',
        ])
        ->toArray();
    $this->actingAs($manager);

    $component = Livewire::test(ListPaymentWorkspace::class);
    $table = $component->instance()->getTable();
    $actionGroup = collect($table->getRecordActions())
        ->first(fn (Action|ActionGroup $action): bool => $action instanceof ActionGroup);
    $finalizeAction = $actionGroup instanceof ActionGroup
        ? ($actionGroup->getFlatActions()['finalize'] ?? null)
        : null;

    expect($table->getBulkActions())->toBeEmpty()
        ->and($finalizeAction)->toBeInstanceOf(Action::class)
        ->and($finalizeAction?->isBulk())->toBeFalse()
        ->and($finalizeAction?->getActionFunction())->toBeNull()
        ->and($finalizeAction?->record($measurement)->getUrl())->toBe(
            MeasurementResource::getUrl('view', ['record' => $measurement]),
        );

    expect($measurement->fresh()->only(['status', 'current_stage', 'workflow_revision']))->toBe($measurementState)
        ->and($measurement->reviews()
            ->orderBy('id')
            ->get(['id', 'stage', 'status', 'reviewer_user_id', 'notes'])
            ->toArray())->toBe($reviewState)
        ->and($measurement->payments()
            ->orderBy('id')
            ->get([
                'id',
                'measurement_id',
                'amount',
                'pay_date',
                'receipt_path',
                'receipt_disk',
                'receipt_sha256',
                'receipt_uploaded_by',
                'receipt_uploaded_at',
            ])
            ->toArray())->toBe($paymentState);
});

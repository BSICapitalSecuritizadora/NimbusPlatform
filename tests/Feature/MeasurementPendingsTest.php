<?php

use App\Enums\MeasurementResponsibility;
use App\Filament\Widgets\Dashboard\MyPendingsWidget;
use App\Models\Measurement;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\SlaConfiguration;
use App\Models\User;
use App\Services\MeasurementPendingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['measurements.sla.calendar_code' => null]);
    SlaConfiguration::factory()->forStage(1, 5)->create();
    SlaConfiguration::factory()->forStage(4, 2)->create();
    SlaConfiguration::factory()->forStage(5, 3)->create();
});

function pendingUser(string $permission = 'measurements.review'): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo(['operations.view', 'measurements.view', $permission]);

    return $user;
}

function pendingMeasurement(
    User $responsible,
    MeasurementResponsibility $responsibility = MeasurementResponsibility::EngineeringReviewer,
    string $status = 'in_review',
): Measurement {
    $operation = Operation::factory()->create([
        $responsibility->operationColumn() => $responsible->getKey(),
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => $status,
        'current_stage' => $responsibility->stage(),
    ]);
    $measurement->reviews()->create([
        'stage' => $responsibility->stage(),
        'status' => 'pending',
        'created_at' => now()->subDay(),
    ]);

    return $measurement;
}

it('shows an actionable review only to its direct responsible user', function () {
    $responsible = pendingUser();
    $outsider = pendingUser();
    $measurement = pendingMeasurement($responsible);

    $responsibleSummary = app(MeasurementPendingService::class)->summaryFor($responsible);
    $outsiderSummary = app(MeasurementPendingService::class)->summaryFor($outsider);

    expect($responsibleSummary['count'])->toBe(1)
        ->and($responsibleSummary['items'][0]['measurement_id'])->toBe($measurement->getKey())
        ->and($outsiderSummary['count'])->toBe(0);
});

it('shows active delegated work with the original responsible identity and exact link', function () {
    $responsible = pendingUser();
    $delegate = pendingUser();
    $measurement = pendingMeasurement($responsible);
    $delegation = ResponsibilityDelegation::factory()->active()->forStage(
        1,
        $measurement->operation,
        MeasurementResponsibility::EngineeringReviewer,
    )->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $summary = app(MeasurementPendingService::class)->summaryFor($delegate);
    $item = $summary['items'][0];

    expect($summary['delegated_count'])->toBe(1)
        ->and($item['delegated'])->toBeTrue()
        ->and($item['delegation_id'])->toBe($delegation->getKey())
        ->and($item['delegator_name'])->toBe($responsible->name)
        ->and($item['url'])->toContain('/measurements/'.$measurement->getKey());
});

it('does not show future expired revoked or out-of-scope delegations', function (string $state) {
    $responsible = pendingUser();
    $delegate = pendingUser();
    $measurement = pendingMeasurement($responsible);
    $attributes = [
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_STAGE,
        'scope_operation_id' => $measurement->operation_id,
        'scope_stage' => $state === 'out-of-scope' ? 2 : 1,
        'scope_responsibility' => $state === 'out-of-scope'
            ? MeasurementResponsibility::ManagementReviewer->value
            : MeasurementResponsibility::EngineeringReviewer->value,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ];

    if ($state === 'future') {
        $attributes['starts_at'] = now()->addDay();
        $attributes['ends_at'] = now()->addDays(2);
    } elseif ($state === 'expired') {
        $attributes['starts_at'] = now()->subDays(3);
        $attributes['ends_at'] = now()->subDay();
    } elseif ($state === 'revoked') {
        $attributes['revoked_at'] = now();
    }

    ResponsibilityDelegation::factory()->create($attributes);

    expect(app(MeasurementPendingService::class)->summaryFor($delegate)['count'])->toBe(0);
})->with(['future', 'expired', 'revoked', 'out-of-scope']);

it('separates payment receipt and finalization responsibilities', function () {
    $paymentManager = pendingUser('measurements.pay');
    $receiptUploader = pendingUser('measurements.receipts');
    $finalizer = pendingUser('measurements.finalize');

    pendingMeasurement($paymentManager, MeasurementResponsibility::PaymentManager, 'awaiting_payment');
    pendingMeasurement($receiptUploader, MeasurementResponsibility::ReceiptUploader, 'awaiting_receipt');
    pendingMeasurement($finalizer, MeasurementResponsibility::Finalizer, 'approved');

    expect(app(MeasurementPendingService::class)->summaryFor($paymentManager)['items'][0]['action_label'])
        ->toBe('Registrar e aprovar pagamento')
        ->and(app(MeasurementPendingService::class)->summaryFor($receiptUploader)['items'][0]['action_label'])
        ->toBe('Enviar comprovante')
        ->and(app(MeasurementPendingService::class)->summaryFor($finalizer)['items'][0]['action_label'])
        ->toBe('Finalizar medição');
});

it('keeps a paused item outside normal approval and exposes only resume', function () {
    $responsible = pendingUser();
    $measurement = pendingMeasurement($responsible, status: 'paused');
    $measurement->pauses()->create([
        'stage' => 1,
        'paused_by' => $responsible->getKey(),
        'pause_reason' => 'Aguardando documento',
        'paused_operation_status' => 'in_review',
        'paused_at' => now()->subHour(),
    ]);

    $summary = app(MeasurementPendingService::class)->summaryFor($responsible);

    expect($summary['items'][0]['action_label'])->toBe('Retomar etapa')
        ->and($summary['items'][0]['sla_status'])->toBe('paused');
});

it('returns curated metadata and renders the delegated UX in the existing widget', function () {
    $responsible = pendingUser();
    $delegate = pendingUser();
    $measurement = pendingMeasurement($responsible);
    ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $summary = app(MeasurementPendingService::class)->summaryFor($delegate);

    expect($summary['items'][0])->not->toHaveKeys(['engineering_snapshot', 'storage_path', 'sha256', 'notes']);

    $this->actingAs($delegate);
    Livewire::test(MyPendingsWidget::class)
        ->assertSuccessful()
        ->assertSee('Medições operacionais')
        ->assertSee('Responsabilidade original de '.$responsible->name)
        ->assertSee('Medição #'.$measurement->getKey());
});

it('eager loads direct pending rows without query growth per measurement', function () {
    $responsible = pendingUser();

    foreach (range(1, 20) as $index) {
        pendingMeasurement($responsible);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $summary = app(MeasurementPendingService::class)->summaryFor($responsible);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($summary['count'])->toBe(20)
        ->and($queryCount)->toBeLessThan(15);
});

it('removes delegated pending work when the delegator becomes ineffective', function (string $condition) {
    $responsible = pendingUser();
    $delegate = pendingUser();
    $measurement = pendingMeasurement($responsible);
    ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    expect(app(MeasurementPendingService::class)->summaryFor($delegate)['count'])->toBe(1);

    match ($condition) {
        'inactive' => $responsible->update(['is_active' => false]),
        'unapproved' => $responsible->update(['approved_at' => null]),
        'permission_removed' => $responsible->revokePermissionTo('measurements.review'),
        'assignment_removed' => $measurement->operation->update([
            'responsible_user_id' => User::factory()->create()->getKey(),
        ]),
    };

    expect(app(MeasurementPendingService::class)->summaryFor($delegate->fresh())['count'])->toBe(0);
})->with(['inactive', 'unapproved', 'permission_removed', 'assignment_removed']);

it('removes delegated pending work when the delegate becomes ineffective', function (string $condition) {
    $responsible = pendingUser();
    $delegate = pendingUser();
    $measurement = pendingMeasurement($responsible);
    ResponsibilityDelegation::factory()->active()->forOperation($measurement->operation)->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    match ($condition) {
        'inactive' => $delegate->update(['is_active' => false]),
        'unapproved' => $delegate->update(['approved_at' => null]),
        'permission_removed' => $delegate->revokePermissionTo('measurements.review'),
    };

    expect(app(MeasurementPendingService::class)->summaryFor($delegate->fresh())['count'])->toBe(0);
})->with(['inactive', 'unapproved', 'permission_removed']);

<?php

use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementEngineeringService;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementReceiptEvidenceScenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function createAuthorizationEditor(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('editor');

    return $user;
}

/**
 * @return array{measurement: Measurement, operation: Operation, reviewer: User, participant: User}
 */
function createAuthorizationScenario(): array
{
    $reviewer = createAuthorizationEditor();
    $participant = createAuthorizationEditor();
    $operation = Operation::factory()->create([
        'assigned_user_id' => $participant->id,
        'stage2_reviewer_user_id' => $reviewer->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'in_review',
        'current_stage' => 2,
    ]);
    $measurement->reviews()->create([
        'stage' => 2,
        'reviewer_user_id' => $reviewer->id,
        'status' => 'pending',
    ]);

    return compact('measurement', 'operation', 'reviewer', 'participant');
}

it('requires both permission and operation-stage responsibility for a decision', function () {
    $scenario = createAuthorizationScenario();
    $withoutPermission = User::factory()->create();
    $scenario['operation']->update(['stage2_reviewer_user_id' => $withoutPermission->id]);

    expect(fn () => app(MeasurementWorkflow::class)->approve(
        $scenario['measurement']->fresh(),
        $withoutPermission,
    ))->toThrow(AuthorizationException::class);

    $scenario['operation']->update(['stage2_reviewer_user_id' => $scenario['reviewer']->id]);

    expect(fn () => app(MeasurementWorkflow::class)->approve(
        $scenario['measurement']->fresh(),
        $scenario['participant'],
    ))->toThrow(AuthorizationException::class);

    app(MeasurementWorkflow::class)->approve($scenario['measurement']->fresh(), $scenario['reviewer']);

    expect($scenario['measurement']->fresh()->current_stage)->toBe(3)
        ->and($scenario['measurement']->fresh()->reviewForStage(2)?->status)->toBe('approved');
});

it('applies the explicit admin and super-admin workflow override', function (string $role) {
    $scenario = createAuthorizationScenario();
    $administrator = User::factory()->withTwoFactor()->create();
    $administrator->assignRole($role);

    app(MeasurementWorkflow::class)->approve($scenario['measurement']->fresh(), $administrator);

    expect($scenario['measurement']->fresh()->current_stage)->toBe(3);
})->with(['admin', 'super-admin']);

it('restricts operation and measurement visibility to participants while preserving admin-wide view', function () {
    $scenario = createAuthorizationScenario();
    $outsider = createAuthorizationEditor();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect(Gate::forUser($outsider)->allows('view', $scenario['operation']))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('view', $scenario['measurement']))->toBeFalse()
        ->and(Operation::query()->visibleTo($outsider)->whereKey($scenario['operation'])->exists())->toBeFalse()
        ->and(Measurement::query()->visibleTo($outsider)->whereKey($scenario['measurement'])->exists())->toBeFalse()
        ->and(Gate::forUser($scenario['participant'])->allows('view', $scenario['operation']))->toBeTrue()
        ->and(Gate::forUser($scenario['reviewer'])->allows('view', $scenario['measurement']))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $scenario['measurement']))->toBeTrue();
});

it('keeps payment manager, receipt uploader and finalizer as separate responsibilities', function () {
    $manager = createAuthorizationEditor();
    $uploader = createAuthorizationEditor();
    $finalizer = createAuthorizationEditor();
    $outsider = createAuthorizationEditor();
    $operation = Operation::factory()->create([
        'payment_manager_user_id' => $manager->id,
        'payment_receipt_uploader_user_id' => $uploader->id,
        'payment_finalizer_user_id' => $finalizer->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'awaiting_receipt',
        'current_stage' => MeasurementWorkflow::STAGE_FINALIZATION,
    ]);
    $measurement->reviews()->create([
        'stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'reviewer_user_id' => $manager->id,
        'status' => 'approved',
        'reviewed_at' => now(),
    ]);
    $payment = $measurement->payments()->create([
        'operation_id' => $operation->id,
        'pay_date' => now(),
        'amount' => 1000,
        'created_by' => $manager->id,
    ]);

    foreach ([$manager, $finalizer, $outsider] as $unauthorized) {
        expect(fn () => app(MeasurementWorkflow::class)->attachReceipt(
            $payment,
            $unauthorized,
            MeasurementReceiptEvidenceScenario::file(),
        ))->toThrow(AuthorizationException::class);
    }

    app(MeasurementWorkflow::class)->attachReceipt(
        $payment,
        $uploader,
        MeasurementReceiptEvidenceScenario::file(),
    );

    expect($payment->fresh()->currentReceiptEvidence->uploaded_by)->toBe($uploader->id)
        ->and($measurement->fresh()->status)->toBe('awaiting_receipt');

    expect(fn () => app(MeasurementWorkflow::class)->deleteReceipt($payment->fresh(), $manager))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(MeasurementWorkflow::class)->deleteReceipt($payment->fresh(), $uploader))
        ->toThrow(MeasurementWorkflowException::class);

    expect($payment->fresh()->hasReceipt())->toBeTrue()
        ->and($measurement->fresh()->status)->toBe('awaiting_receipt');
});

it('allows only the payment manager to register payments in the pending payment stage', function () {
    $manager = createAuthorizationEditor();
    $uploader = createAuthorizationEditor();
    $operation = Operation::factory()->create([
        'payment_manager_user_id' => $manager->id,
        'payment_receipt_uploader_user_id' => $uploader->id,
    ]);
    $planSet = MeasurementPlanSet::factory()->default()->create(['operation_id' => $operation->id]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'awaiting_payment',
        'current_stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'engineering_snapshot' => [
            'schema_version' => MeasurementEngineeringService::SNAPSHOT_SCHEMA_VERSION,
            'measurement_id' => 0,
            'operation_id' => $operation->id,
            'emission_id' => $operation->emission_id,
            'plan_sets' => [['plan_set_id' => $planSet->id, 'is_default' => true]],
        ],
    ]);
    $snapshot = $measurement->engineering_snapshot;
    $snapshot['measurement_id'] = $measurement->id;
    $measurement->forceFill(['engineering_snapshot' => $snapshot])->save();
    $measurement->reviews()->create([
        'stage' => MeasurementWorkflow::STAGE_PAYMENT,
        'reviewer_user_id' => $manager->id,
        'status' => 'pending',
    ]);

    expect(fn () => app(MeasurementWorkflow::class)->registerPayment($measurement, $uploader, [
        'pay_date' => now(),
        'amount' => 100,
    ]))->toThrow(AuthorizationException::class);

    $payment = app(MeasurementWorkflow::class)->registerPayment($measurement->fresh(), $manager, [
        'pay_date' => now(),
        'amount' => 100,
    ]);

    expect($payment->created_by)->toBe($manager->id)
        ->and($measurement->fresh()->status)->toBe('awaiting_payment');
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});

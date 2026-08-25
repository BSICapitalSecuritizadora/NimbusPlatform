<?php

use App\Models\Measurement;
use App\Models\Operation;
use App\Models\User;
use App\Services\MeasurementWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

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
    Storage::disk('local')->put('nimbus_docs/measurements/receipts/separate.pdf', '%PDF-1.7 separate-roles');

    foreach ([$manager, $finalizer, $outsider] as $unauthorized) {
        expect(fn () => app(MeasurementWorkflow::class)->attachReceipt(
            $payment,
            $unauthorized,
            'nimbus_docs/measurements/receipts/separate.pdf',
            'local',
        ))->toThrow(AuthorizationException::class);
    }

    app(MeasurementWorkflow::class)->attachReceipt(
        $payment,
        $uploader,
        'nimbus_docs/measurements/receipts/separate.pdf',
        'local',
    );

    expect($payment->fresh()->receipt_uploaded_by)->toBe($uploader->id)
        ->and($measurement->fresh()->status)->toBe('approved');

    expect(fn () => app(MeasurementWorkflow::class)->deleteReceipt($payment->fresh(), $manager))
        ->toThrow(AuthorizationException::class);

    app(MeasurementWorkflow::class)->deleteReceipt($payment->fresh(), $uploader);

    expect($payment->fresh()->receipt_path)->toBeNull()
        ->and($measurement->fresh()->status)->toBe('awaiting_receipt');
});

it('allows only the payment manager to register payments in the pending payment stage', function () {
    $manager = createAuthorizationEditor();
    $uploader = createAuthorizationEditor();
    $operation = Operation::factory()->create([
        'payment_manager_user_id' => $manager->id,
        'payment_receipt_uploader_user_id' => $uploader->id,
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->id,
        'storage_path' => null,
        'status' => 'awaiting_payment',
        'current_stage' => MeasurementWorkflow::STAGE_PAYMENT,
    ]);
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

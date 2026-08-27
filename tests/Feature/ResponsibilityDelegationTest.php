<?php

use App\Enums\MeasurementResponsibility;
use App\Models\Measurement;
use App\Models\MeasurementPlanLine;
use App\Models\MeasurementPlanSet;
use App\Models\Operation;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementWorkflow;
use App\Services\ResponsibilityDelegationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function delegationActor(string $role = 'admin'): User
{
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->assignRole($role);

    return $user;
}

function makeOperationWithResponsibles(array $overrides = []): Operation
{
    return Operation::factory()->create($overrides);
}

it('creates authorized delegation', function () {
    $delegator = delegationActor('editor');
    $delegator->givePermissionTo('delegations.create');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);

    $op = makeOperationWithResponsibles(['responsible_user_id' => $delegator->id]);

    $service = app(ResponsibilityDelegationService::class);

    $delegation = $service->createDelegation([
        'delegator_user_id' => $delegator->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $op->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Férias',
    ], $delegator);

    expect($delegation->exists)->toBeTrue()
        ->and($delegation->scope_type)->toBe('operation');
});

it('rejects self-delegation', function () {
    $user = delegationActor();
    $user->givePermissionTo('delegations.create');
    $op = makeOperationWithResponsibles(['responsible_user_id' => $user->id]);

    $service = app(ResponsibilityDelegationService::class);

    expect(fn () => $service->createDelegation([
        'delegator_user_id' => $user->id,
        'delegate_user_id' => $user->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now(),
        'ends_at' => now()->addDays(2),
        'reason' => 'Teste',
    ], $user))->toThrow(ValidationException::class);
});

it('rejects inactive delegate', function () {
    $delegator = delegationActor();
    $delegator->givePermissionTo('delegations.create');
    $delegate = User::factory()->create(['is_active' => false, 'approved_at' => now()]);
    $op = makeOperationWithResponsibles(['responsible_user_id' => $delegator->id]);

    $service = app(ResponsibilityDelegationService::class);

    expect(fn () => $service->createDelegation([
        'delegator_user_id' => $delegator->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now(),
        'ends_at' => now()->addDays(2),
        'reason' => 'Teste',
    ], $delegator))->toThrow(ValidationException::class);
});

it('rejects invalid period where ends before starts', function () {
    $delegator = delegationActor();
    $delegator->givePermissionTo('delegations.create');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $op = makeOperationWithResponsibles(['responsible_user_id' => $delegator->id]);

    $service = app(ResponsibilityDelegationService::class);

    expect(fn () => $service->createDelegation([
        'delegator_user_id' => $delegator->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(2),
        'reason' => 'Teste',
    ], $delegator))->toThrow(ValidationException::class);
});

it('rejects already expired delegation at creation', function () {
    $delegator = delegationActor();
    $delegator->givePermissionTo('delegations.create');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);

    $service = app(ResponsibilityDelegationService::class);

    expect(fn () => $service->createDelegation([
        'delegator_user_id' => $delegator->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(5),
        'reason' => 'Teste',
    ], $delegator))->toThrow(ValidationException::class);
});

it('rejects overlapping delegation', function () {
    $delegator = delegationActor();
    $delegator->givePermissionTo('delegations.create');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $op = makeOperationWithResponsibles(['responsible_user_id' => $delegator->id]);

    $service = app(ResponsibilityDelegationService::class);

    $service->createDelegation([
        'delegator_user_id' => $delegator->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $op->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Primeira',
    ], $delegator);

    expect(fn () => $service->createDelegation([
        'delegator_user_id' => $delegator->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $op->id,
        'starts_at' => now(),
        'ends_at' => now()->addDays(3),
        'reason' => 'Segunda',
    ], $delegator))->toThrow(ValidationException::class);
});

it('active delegation grants stage decision', function () {
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $responsible->assignRole('editor');
    $responsible->givePermissionTo(['measurements.review', 'measurements.create', 'operations.view']);

    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->assignRole('editor');
    $delegate->givePermissionTo(['measurements.review', 'operations.view', 'measurements.view', 'delegations.create']);

    $op = makeOperationWithResponsibles(['responsible_user_id' => $responsible->id]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $op->id]);
    $line = MeasurementPlanLine::factory()->create(['plan_set_id' => $planSet->id, 'operation_id' => $op->id, 'measurement_date' => now()->format('Y-m-d')]);

    $measurement = Measurement::factory()->create([
        'operation_id' => $op->id,
        'reference_month' => now()->format('Y-m-d'),
        'status' => 'in_review',
        'current_stage' => 1,
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);

    // Create active delegation via admin (so delegator authority is validated)
    $admin = delegationActor('admin');
    $service = app(ResponsibilityDelegationService::class);
    $service->createDelegation([
        'delegator_user_id' => $responsible->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_STAGE,
        'scope_stage' => 1,
        'scope_operation_id' => $op->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Delegação para teste',
    ], $admin);

    $auth = app(MeasurementAuthorizationService::class);

    expect($auth->canDecideStage($delegate, $measurement, 1))->toBeTrue();
});

it('expired delegation does not grant action', function () {
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');

    $op = makeOperationWithResponsibles(['responsible_user_id' => $responsible->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $op->id, 'status' => 'in_review', 'current_stage' => 1]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);

    ResponsibilityDelegation::factory()->create([
        'delegator_user_id' => $responsible->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(5),
        'reason' => 'Expirada',
    ]);

    $auth = app(MeasurementAuthorizationService::class);
    expect($auth->canDecideStage($delegate, $measurement, 1))->toBeFalse();
});

it('revoked delegation does not grant action', function () {
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');

    $op = makeOperationWithResponsibles(['responsible_user_id' => $responsible->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $op->id, 'status' => 'in_review', 'current_stage' => 1]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);

    $delegation = ResponsibilityDelegation::factory()->create([
        'delegator_user_id' => $responsible->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Revogada',
        'revoked_at' => now(),
    ]);

    $auth = app(MeasurementAuthorizationService::class);
    expect($auth->canDecideStage($delegate, $measurement, 1))->toBeFalse();
});

it('delegation does not exceed delegator authority', function () {
    $nonResponsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $nonResponsible->assignRole('editor');
    $nonResponsible->givePermissionTo('delegations.create');

    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $op = makeOperationWithResponsibles(['responsible_user_id' => User::factory()->create()->id]);

    $service = app(ResponsibilityDelegationService::class);

    expect(fn () => $service->createDelegation([
        'delegator_user_id' => $nonResponsible->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $op->id,
        'starts_at' => now(),
        'ends_at' => now()->addDays(3),
        'reason' => 'Tentativa sem autoridade',
    ], $nonResponsible))->toThrow(ValidationException::class);
});

it('direct responsibility still works', function () {
    $user = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $user->givePermissionTo('measurements.review');

    $op = makeOperationWithResponsibles(['responsible_user_id' => $user->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $op->id, 'status' => 'in_review', 'current_stage' => 1]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);

    $auth = app(MeasurementAuthorizationService::class);
    expect($auth->canDecideStage($user, $measurement, 1))->toBeTrue();
});

it('admin override still works', function () {
    $admin = delegationActor('admin');
    $op = makeOperationWithResponsibles(['responsible_user_id' => User::factory()->create()->id]);
    $measurement = Measurement::factory()->create(['operation_id' => $op->id, 'status' => 'in_review', 'current_stage' => 1]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);

    $auth = app(MeasurementAuthorizationService::class);
    expect($auth->canDecideStage($admin, $measurement, 1))->toBeTrue();
});

it('rejects 3-user transitive cycle A->B, B->C, C->A', function () {
    $a = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $a->assignRole('editor');
    $a->givePermissionTo(['delegations.create', 'measurements.review']);
    $b = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $b->assignRole('editor');
    $b->givePermissionTo(['delegations.create', 'measurements.review']);
    $c = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $c->assignRole('editor');
    $c->givePermissionTo(['delegations.create', 'measurements.review']);
    $op = makeOperationWithResponsibles(['responsible_user_id' => $a->id, 'stage2_reviewer_user_id' => $b->id, 'stage3_reviewer_user_id' => $c->id]);
    // A delegates global to B (A has authority via responsible)
    $service = app(ResponsibilityDelegationService::class);
    $admin = delegationActor('admin');
    $service->createDelegation(['delegator_user_id' => $a->id, 'delegate_user_id' => $b->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'A->B'], $admin);
    $service->createDelegation(['delegator_user_id' => $b->id, 'delegate_user_id' => $c->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'B->C'], $admin);
    expect(fn () => $service->createDelegation(['delegator_user_id' => $c->id, 'delegate_user_id' => $a->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'C->A cycle'], $admin))->toThrow(ValidationException::class);
});

it('rejects 4-user transitive cycle', function () {
    $users = [];
    for ($i = 0; $i < 4; $i++) {
        $u = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
        $u->assignRole('editor');
        $u->givePermissionTo(['delegations.create', 'measurements.review']);
        $users[] = $u;
    }
    $op = makeOperationWithResponsibles(['responsible_user_id' => $users[0]->id, 'stage2_reviewer_user_id' => $users[1]->id, 'stage3_reviewer_user_id' => $users[2]->id, 'payment_manager_user_id' => $users[3]->id]);
    $admin = delegationActor('admin');
    $service = app(ResponsibilityDelegationService::class);
    $service->createDelegation(['delegator_user_id' => $users[0]->id, 'delegate_user_id' => $users[1]->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => '0->1'], $admin);
    $service->createDelegation(['delegator_user_id' => $users[1]->id, 'delegate_user_id' => $users[2]->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => '1->2'], $admin);
    $service->createDelegation(['delegator_user_id' => $users[2]->id, 'delegate_user_id' => $users[3]->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => '2->3'], $admin);
    expect(fn () => $service->createDelegation(['delegator_user_id' => $users[3]->id, 'delegate_user_id' => $users[0]->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => '3->0 cycle'], $admin))->toThrow(ValidationException::class);
});

it('expired edge does not create cycle', function () {
    $a = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $a->assignRole('editor');
    $a->givePermissionTo(['delegations.create', 'measurements.review']);
    $b = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $b->assignRole('editor');
    $b->givePermissionTo(['delegations.create', 'measurements.review']);
    $c = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $c->assignRole('editor');
    $c->givePermissionTo(['delegations.create', 'measurements.review']);
    $op = makeOperationWithResponsibles(['responsible_user_id' => $a->id]);
    $admin = delegationActor('admin');
    $service = app(ResponsibilityDelegationService::class);
    // B->A expired (ends 5 days ago) — should not block A->B
    ResponsibilityDelegation::factory()->create(['delegator_user_id' => $b->id, 'delegate_user_id' => $a->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(5), 'reason' => 'expired']);
    // A->B should succeed because expired edge not considered
    $delegation = $service->createDelegation(['delegator_user_id' => $a->id, 'delegate_user_id' => $b->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'A->B ok'], $admin);
    expect($delegation->exists)->toBeTrue();
});

it('revoked edge does not create cycle', function () {
    $a = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $a->assignRole('editor');
    $a->givePermissionTo(['delegations.create', 'measurements.review']);
    $b = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $b->assignRole('editor');
    $b->givePermissionTo(['delegations.create', 'measurements.review']);
    $admin = delegationActor('admin');
    $service = app(ResponsibilityDelegationService::class);
    $revoked = ResponsibilityDelegation::factory()->create(['delegator_user_id' => $b->id, 'delegate_user_id' => $a->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $admin->id]);
    $op = makeOperationWithResponsibles(['responsible_user_id' => $a->id]);
    $delegation = $service->createDelegation(['delegator_user_id' => $a->id, 'delegate_user_id' => $b->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'A->B ok after revoke'], $admin);
    expect($delegation->exists)->toBeTrue();
});

it('future active-period edge is considered for cycle', function () {
    $a = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $a->assignRole('editor');
    $a->givePermissionTo(['delegations.create', 'measurements.review']);
    $b = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $b->assignRole('editor');
    $b->givePermissionTo(['delegations.create', 'measurements.review']);
    $admin = delegationActor('admin');
    $service = app(ResponsibilityDelegationService::class);
    // B->A future (starts tomorrow, ends in 5 days) — NimbusOps considers future non-expired edges for cycle
    ResponsibilityDelegation::factory()->create(['delegator_user_id' => $b->id, 'delegate_user_id' => $a->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(5), 'reason' => 'future B->A']);
    expect(fn () => $service->createDelegation(['delegator_user_id' => $a->id, 'delegate_user_id' => $b->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'A->B cycle via future'], $admin))->toThrow(ValidationException::class);
});

it('unrelated chain does not block valid delegation', function () {
    $a = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $a->assignRole('editor');
    $a->givePermissionTo(['delegations.create', 'measurements.review']);
    $b = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $b->assignRole('editor');
    $b->givePermissionTo(['delegations.create', 'measurements.review']);
    $c = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $c->assignRole('editor');
    $c->givePermissionTo(['delegations.create', 'measurements.review']);
    $d = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $d->assignRole('editor');
    $d->givePermissionTo(['delegations.create', 'measurements.review']);
    $admin = delegationActor('admin');
    makeOperationWithResponsibles(['responsible_user_id' => $a->id]);
    makeOperationWithResponsibles(['responsible_user_id' => $c->id]);
    $service = app(ResponsibilityDelegationService::class);
    $service->createDelegation(['delegator_user_id' => $a->id, 'delegate_user_id' => $b->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'A->B'], $admin);
    // C->D is unrelated, should succeed
    $delegation = $service->createDelegation(['delegator_user_id' => $c->id, 'delegate_user_id' => $d->id, 'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'C->D'], $admin);
    expect($delegation->exists)->toBeTrue();
});

it('delegator without required permission cannot delegate', function () {
    $delegator = User::factory()->create(['is_active' => true, 'approved_at' => now()]);

    // No measurements.review permission
    $delegator->givePermissionTo('delegations.create');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $op = makeOperationWithResponsibles(['responsible_user_id' => $delegator->id]);
    $service = app(ResponsibilityDelegationService::class);
    expect(fn () => $service->createDelegation(['delegator_user_id' => $delegator->id, 'delegate_user_id' => $delegate->id, 'scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 1, 'scope_operation_id' => $op->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'no perm'], $delegator))->toThrow(ValidationException::class);
});

it('delegator with permission and record authority can delegate', function () {
    $delegator = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegator->assignRole('editor');
    $delegator->givePermissionTo(['delegations.create', 'measurements.review']);
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $op = makeOperationWithResponsibles(['responsible_user_id' => $delegator->id]);
    $service = app(ResponsibilityDelegationService::class);
    $delegation = $service->createDelegation(['delegator_user_id' => $delegator->id, 'delegate_user_id' => $delegate->id, 'scope_type' => ResponsibilityDelegation::SCOPE_STAGE, 'scope_stage' => 1, 'scope_operation_id' => $op->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'reason' => 'with perm'], $delegator);
    expect($delegation->exists)->toBeTrue();
});

it('audit captures effective actor and delegation source', function () {
    Storage::fake('local');

    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->assignRole('editor');
    $delegate->givePermissionTo(['measurements.review', 'measurements.create', 'operations.view', 'measurements.view']);
    $responsible->givePermissionTo(['measurements.review']);

    $op = makeOperationWithResponsibles([
        'responsible_user_id' => $responsible->id,
        'stage2_reviewer_user_id' => $responsible->id,
        'stage3_reviewer_user_id' => $responsible->id,
        'payment_manager_user_id' => $responsible->id,
    ]);
    $planSet = MeasurementPlanSet::factory()->create(['operation_id' => $op->id]);
    $line1 = MeasurementPlanLine::factory()->create(['plan_set_id' => $planSet->id, 'operation_id' => $op->id, 'sequence_number' => 1, 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 30, 'initial_realized_cumulative_percent' => 0, 'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0, 'measurement_date' => '2026-08-01']);
    MeasurementPlanLine::factory()->create(['plan_set_id' => $planSet->id, 'operation_id' => $op->id, 'sequence_number' => 2, 'planned_monthly_percent' => 10, 'planned_cumulative_percent' => 60, 'initial_realized_cumulative_percent' => 0, 'realized_monthly_percent' => 0, 'realized_cumulative_percent' => 0, 'measurement_date' => '2026-09-01']);

    $measurement = Measurement::factory()->create([
        'operation_id' => $op->id,
        'reference_month' => '2026-08-01',
        'status' => 'in_review',
        'current_stage' => 1,
    ]);
    $path = "nimbus_docs/measurements/workflow/{$measurement->id}.pdf";
    Storage::disk('local')->put($path, '%PDF-1.7 workflow-test');
    $measurement->assets()->create([
        'plan_set_id' => $planSet->id,
        'plan_line_id' => $line1->id,
        'storage_path' => $path,
        'storage_disk' => 'local',
    ]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);

    $admin = delegationActor('admin');
    $service = app(ResponsibilityDelegationService::class);
    $delegation = $service->createDelegation([
        'delegator_user_id' => $responsible->id,
        'delegate_user_id' => $delegate->id,
        'scope_type' => ResponsibilityDelegation::SCOPE_GLOBAL,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(5),
        'reason' => 'Auditoria',
    ], $admin);

    $workflow = app(MeasurementWorkflow::class);
    $workflow->approve($measurement, $delegate, 'Aprovação delegada', [$planSet->id => 10.0]);

    $activity = Activity::where('log_name', 'measurement_workflow')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe((int) $delegate->getKey())
        ->and($activity->properties['delegated'])->toBeTrue()
        ->and((int) $activity->properties['delegation_id'])->toBe((int) $delegation->getKey())
        ->and((int) $activity->properties['delegator_user_id'])->toBe((int) $responsible->getKey())
        ->and((int) $activity->properties['actual_actor_user_id'])->toBe((int) $delegate->getKey());
});

it('does not authorize a future delegation or permanently grant RBAC permission', function () {
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $responsible->givePermissionTo('measurements.review');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    $operation = Operation::factory()->create(['responsible_user_id' => $responsible->getKey()]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->getKey(), 'status' => 'in_review', 'current_stage' => 1]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);
    ResponsibilityDelegation::factory()->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);

    expect(app(MeasurementAuthorizationService::class)->canDecideStage($delegate, $measurement, 1))->toBeFalse()
        ->and($delegate->getDirectPermissions()->pluck('name'))->not->toContain('delegations.manage');
});

it('revokes immediately with preserved history and reason', function () {
    $delegator = delegationActor('editor');
    $delegator->givePermissionTo(['measurements.review', 'delegations.revoke']);
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo('measurements.review');
    $operation = Operation::factory()->create(['responsible_user_id' => $delegator->getKey()]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->getKey(), 'status' => 'in_review', 'current_stage' => 1]);
    $measurement->reviews()->create(['stage' => 1, 'status' => 'pending']);
    $delegation = ResponsibilityDelegation::factory()->active()->create([
        'delegator_user_id' => $delegator->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    app(ResponsibilityDelegationService::class)->revokeDelegation($delegation, $delegator, 'Retorno antecipado');

    expect($delegation->fresh()->revocation_reason)->toBe('Retorno antecipado')
        ->and($delegation->fresh()->revoked_at)->not->toBeNull()
        ->and(app(MeasurementAuthorizationService::class)->canDecideStage($delegate, $measurement, 1))->toBeFalse();
});

it('prevents redelegation of authority that was only received', function () {
    $a = delegationActor('editor');
    $a->givePermissionTo('measurements.review');
    $b = delegationActor('editor');
    $b->givePermissionTo(['measurements.review', 'delegations.create']);
    $c = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $operation = Operation::factory()->create(['responsible_user_id' => $a->getKey()]);
    ResponsibilityDelegation::factory()->active()->forStage(1, $operation)->create([
        'delegator_user_id' => $a->getKey(),
        'delegate_user_id' => $b->getKey(),
    ]);

    expect(fn () => app(ResponsibilityDelegationService::class)->createDelegation([
        'delegator_user_id' => $b->getKey(),
        'delegate_user_id' => $c->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_STAGE,
        'scope_operation_id' => $operation->getKey(),
        'scope_stage' => 1,
        'starts_at' => now(),
        'ends_at' => now()->addDay(),
        'reason' => 'Tentativa de redelegação',
    ], $b))->toThrow(ValidationException::class);
});

it('rejects overlap to a different delegate for the same delegator and scope', function () {
    $delegator = delegationActor('editor');
    $delegator->givePermissionTo(['measurements.review', 'delegations.create']);
    $firstDelegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $secondDelegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $operation = Operation::factory()->create(['responsible_user_id' => $delegator->getKey()]);
    $service = app(ResponsibilityDelegationService::class);
    $base = [
        'delegator_user_id' => $delegator->getKey(),
        'scope_type' => ResponsibilityDelegation::SCOPE_OPERATION,
        'scope_operation_id' => $operation->getKey(),
        'starts_at' => now(),
        'ends_at' => now()->addDays(2),
        'reason' => 'Cobertura',
    ];
    $service->createDelegation($base + ['delegate_user_id' => $firstDelegate->getKey()], $delegator);

    expect(fn () => $service->createDelegation(
        $base + ['delegate_user_id' => $secondDelegate->getKey()],
        $delegator,
    ))->toThrow(ValidationException::class);
});

it('keeps receipt uploader and finalizer scopes isolated at stage five', function () {
    $receiptOwner = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $receiptOwner->givePermissionTo('measurements.receipts');
    $finalizer = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $finalizer->givePermissionTo('measurements.finalize');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo(['measurements.receipts', 'measurements.finalize']);
    $operation = Operation::factory()->create([
        'payment_receipt_uploader_user_id' => $receiptOwner->getKey(),
        'payment_finalizer_user_id' => $finalizer->getKey(),
    ]);
    $measurement = Measurement::factory()->create(['operation_id' => $operation->getKey(), 'status' => 'awaiting_receipt', 'current_stage' => 5]);
    $measurement->reviews()->create(['stage' => 5, 'status' => 'pending']);
    ResponsibilityDelegation::factory()->active()->forStage(
        5,
        $operation,
        MeasurementResponsibility::ReceiptUploader,
    )->create([
        'delegator_user_id' => $receiptOwner->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    $authorization = app(MeasurementAuthorizationService::class);

    expect($authorization->canManageReceipts($delegate, $measurement))->toBeTrue()
        ->and($authorization->canFinalize($delegate, $measurement))->toBeFalse();
});

it('includes an active delegate in policy and SQL visibility only inside scope', function () {
    $responsible = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $responsible->givePermissionTo('measurements.review');
    $delegate = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo(['operations.view', 'measurements.view', 'measurements.review']);
    $coveredOperation = Operation::factory()->create(['responsible_user_id' => $responsible->getKey()]);
    $otherOperation = Operation::factory()->create(['responsible_user_id' => $responsible->getKey()]);
    $covered = Measurement::factory()->create(['operation_id' => $coveredOperation->getKey()]);
    $outside = Measurement::factory()->create(['operation_id' => $otherOperation->getKey()]);
    ResponsibilityDelegation::factory()->active()->forOperation($coveredOperation)->create([
        'delegator_user_id' => $responsible->getKey(),
        'delegate_user_id' => $delegate->getKey(),
    ]);

    expect(app(MeasurementAuthorizationService::class)->canViewMeasurement($delegate, $covered))->toBeTrue()
        ->and(Measurement::query()->visibleTo($delegate)->whereKey($covered->getKey())->exists())->toBeTrue()
        ->and(Measurement::query()->visibleTo($delegate)->whereKey($outside->getKey())->exists())->toBeFalse();
});

/**
 * @return array{delegator: User, delegate: User, operation: Operation, measurement: Measurement, delegation: ResponsibilityDelegation}
 */
function p11DelegationScenario(
    MeasurementResponsibility $responsibility = MeasurementResponsibility::EngineeringReviewer,
): array {
    $delegator = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $delegator->givePermissionTo($responsibility->permission());
    $delegate = User::factory()->withTwoFactor()->create(['is_active' => true, 'approved_at' => now()]);
    $delegate->givePermissionTo([
        'operations.view',
        'measurements.view',
        $responsibility->permission(),
    ]);
    $operation = Operation::factory()->create([
        $responsibility->operationColumn() => $delegator->getKey(),
    ]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => match ($responsibility) {
            MeasurementResponsibility::PaymentManager => 'awaiting_payment',
            MeasurementResponsibility::ReceiptUploader => 'awaiting_receipt',
            MeasurementResponsibility::Finalizer => 'approved',
            default => 'in_review',
        },
        'current_stage' => $responsibility->stage(),
    ]);
    $measurement->reviews()->create([
        'stage' => $responsibility->stage(),
        'status' => 'pending',
    ]);
    $delegation = ResponsibilityDelegation::factory()
        ->active()
        ->forStage($responsibility->stage(), $operation, $responsibility)
        ->create([
            'delegator_user_id' => $delegator->getKey(),
            'delegate_user_id' => $delegate->getKey(),
        ]);

    return compact('delegator', 'delegate', 'operation', 'measurement', 'delegation');
}

it('separates delegated visibility and action from generic entity mutation', function () {
    $scenario = p11DelegationScenario(MeasurementResponsibility::ManagementReviewer);
    $delegate = $scenario['delegate'];
    $delegate->givePermissionTo([
        'operations.update',
        'operations.delete',
        'operations.manage-responsibilities',
        'measurements.update',
        'measurements.delete',
    ]);
    $authorization = app(MeasurementAuthorizationService::class);

    expect($authorization->canViewOperation($delegate, $scenario['operation']))->toBeTrue()
        ->and($authorization->canViewMeasurement($delegate, $scenario['measurement']))->toBeTrue()
        ->and($authorization->canDecideStage($delegate, $scenario['measurement'], 2))->toBeTrue()
        ->and(app(MeasurementWorkflow::class)->canApprove($scenario['measurement'], $delegate))->toBeTrue()
        ->and(Gate::forUser($delegate)->allows('update', $scenario['operation']))->toBeFalse()
        ->and(Gate::forUser($delegate)->allows('delete', $scenario['operation']))->toBeFalse()
        ->and(Gate::forUser($delegate)->allows('manageResponsibilities', $scenario['operation']))->toBeFalse()
        ->and(Gate::forUser($delegate)->allows('update', $scenario['measurement']))->toBeFalse();
});

it('keeps generic update available to direct participants and administrators', function () {
    $direct = User::factory()->create(['is_active' => true, 'approved_at' => now()]);
    $direct->givePermissionTo(['operations.update', 'measurements.update']);
    $operation = Operation::factory()->create(['assigned_user_id' => $direct->getKey()]);
    $measurement = Measurement::factory()->create([
        'operation_id' => $operation->getKey(),
        'status' => 'pending',
    ]);
    $admin = delegationActor('admin');

    expect(Gate::forUser($direct)->allows('update', $operation))->toBeTrue()
        ->and(Gate::forUser($direct)->allows('update', $measurement))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $operation))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $measurement))->toBeTrue();
});

it('does not grant generic update through operation or global delegation scopes', function (string $scope) {
    $scenario = p11DelegationScenario();
    $delegate = $scenario['delegate'];
    $delegate->givePermissionTo(['operations.update', 'measurements.update']);
    $scenario['delegation']->delete();

    $factory = ResponsibilityDelegation::factory()->active();

    if ($scope === ResponsibilityDelegation::SCOPE_OPERATION) {
        $factory = $factory->forOperation($scenario['operation']);
    }

    $factory->create([
        'delegator_user_id' => $scenario['delegator']->getKey(),
        'delegate_user_id' => $delegate->getKey(),
        'scope_type' => $scope,
        'scope_operation_id' => $scope === ResponsibilityDelegation::SCOPE_OPERATION
            ? $scenario['operation']->getKey()
            : null,
    ]);

    expect(Gate::forUser($delegate)->allows('view', $scenario['operation']))->toBeTrue()
        ->and(Gate::forUser($delegate)->allows('update', $scenario['operation']))->toBeFalse()
        ->and(Gate::forUser($delegate)->allows('update', $scenario['measurement']))->toBeFalse();
})->with([
    ResponsibilityDelegation::SCOPE_OPERATION,
    ResponsibilityDelegation::SCOPE_GLOBAL,
]);

it('keeps payment and receipt actions scoped away from generic measurement update', function (
    MeasurementResponsibility $responsibility,
) {
    $scenario = p11DelegationScenario($responsibility);
    $delegate = $scenario['delegate'];
    $delegate->givePermissionTo('measurements.update');
    $workflow = app(MeasurementWorkflow::class);
    $canExecuteSpecificAction = match ($responsibility) {
        MeasurementResponsibility::PaymentManager => $workflow->canRegisterPayment($scenario['measurement'], $delegate),
        MeasurementResponsibility::ReceiptUploader => $workflow->canManageReceipts($scenario['measurement'], $delegate),
        default => false,
    };

    expect($canExecuteSpecificAction)->toBeTrue()
        ->and(Gate::forUser($delegate)->allows('update', $scenario['measurement']))->toBeFalse();
})->with([
    MeasurementResponsibility::PaymentManager,
    MeasurementResponsibility::ReceiptUploader,
]);

it('invalidates delegated action policy and SQL visibility when the delegator becomes ineffective', function (
    string $condition,
) {
    $scenario = p11DelegationScenario();
    $authorization = app(MeasurementAuthorizationService::class);

    expect($authorization->canDecideStage($scenario['delegate'], $scenario['measurement'], 1))->toBeTrue();

    match ($condition) {
        'inactive' => $scenario['delegator']->update(['is_active' => false]),
        'unapproved' => $scenario['delegator']->update(['approved_at' => null]),
        'permission_removed' => $scenario['delegator']->revokePermissionTo('measurements.review'),
        'assignment_removed' => $scenario['operation']->update([
            'responsible_user_id' => User::factory()->create()->getKey(),
        ]),
    };

    $operation = $scenario['operation']->fresh();
    $measurement = $scenario['measurement']->fresh();

    expect($authorization->canDecideStage($scenario['delegate']->fresh(), $measurement, 1))->toBeFalse()
        ->and($authorization->canViewOperation($scenario['delegate']->fresh(), $operation))->toBeFalse()
        ->and(Operation::query()->visibleTo($scenario['delegate']->fresh())->whereKey($operation->getKey())->exists())->toBeFalse()
        ->and(Measurement::query()->visibleTo($scenario['delegate']->fresh())->whereKey($measurement->getKey())->exists())->toBeFalse()
        ->and(app(ResponsibilityDelegationService::class)->effectiveStatus($scenario['delegation']->fresh()))->toBe('ineffective');
})->with(['inactive', 'unapproved', 'permission_removed', 'assignment_removed']);

it('invalidates delegated action and visibility when the delegate becomes ineffective', function (string $condition) {
    $scenario = p11DelegationScenario();
    $authorization = app(MeasurementAuthorizationService::class);

    match ($condition) {
        'inactive' => $scenario['delegate']->update(['is_active' => false]),
        'unapproved' => $scenario['delegate']->update(['approved_at' => null]),
        'permission_removed' => $scenario['delegate']->revokePermissionTo('measurements.review'),
    };

    $delegate = $scenario['delegate']->fresh();

    expect($authorization->canDecideStage($delegate, $scenario['measurement']->fresh(), 1))->toBeFalse()
        ->and($authorization->canViewOperation($delegate, $scenario['operation']->fresh()))->toBeFalse()
        ->and(Operation::query()->visibleTo($delegate)->whereKey($scenario['operation']->getKey())->exists())->toBeFalse()
        ->and(Measurement::query()->visibleTo($delegate)->whereKey($scenario['measurement']->getKey())->exists())->toBeFalse();
})->with(['inactive', 'unapproved', 'permission_removed']);

it('restores delegation only while every effective invariant still holds', function (string $condition) {
    $scenario = p11DelegationScenario();
    $authorization = app(MeasurementAuthorizationService::class);

    if ($condition === 'inactive') {
        $scenario['delegator']->update(['is_active' => false]);
        expect($authorization->canDecideStage($scenario['delegate'], $scenario['measurement'], 1))->toBeFalse();
        $scenario['delegator']->update(['is_active' => true]);
    } else {
        $scenario['delegator']->update(['approved_at' => null]);
        expect($authorization->canDecideStage($scenario['delegate'], $scenario['measurement'], 1))->toBeFalse();
        $scenario['delegator']->update(['approved_at' => now()]);
    }

    expect($authorization->canDecideStage($scenario['delegate']->fresh(), $scenario['measurement']->fresh(), 1))->toBeTrue();

    $scenario['operation']->update(['responsible_user_id' => User::factory()->create()->getKey()]);

    expect($authorization->canDecideStage($scenario['delegate']->fresh(), $scenario['measurement']->fresh(), 1))->toBeFalse();
})->with(['inactive', 'unapproved']);

it('revalidates delegated download authorization after delegator invalidation', function () {
    Storage::fake('local');
    $scenario = p11DelegationScenario();
    $path = 'nimbus_docs/measurements/p11/delegated.pdf';
    Storage::disk('local')->put($path, '%PDF-1.7 delegated');
    $scenario['measurement']->forceFill([
        'storage_path' => $path,
        'storage_disk' => 'local',
        'filename' => 'delegated.pdf',
        'mime_type' => 'application/pdf',
    ])->save();
    $route = route('admin.measurements.file.download', $scenario['measurement']);

    $this->actingAs($scenario['delegate'])
        ->get($route)
        ->assertOk();

    $scenario['delegator']->update(['is_active' => false]);

    $this->actingAs($scenario['delegate']->fresh())
        ->get($route)
        ->assertForbidden();
});

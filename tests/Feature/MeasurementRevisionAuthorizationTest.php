<?php

use App\Enums\AccessPermission;
use App\Exceptions\MeasurementWorkflowException;
use App\Models\Measurement;
use App\Models\ResponsibilityDelegation;
use App\Models\User;
use App\Services\MeasurementAuthorizationService;
use App\Services\MeasurementRevisionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\MeasurementRevisionScenario as Scenario;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

function revisionOutsider(array $permissions = []): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->givePermissionTo(['measurements.view', ...$permissions]);

    return $user;
}

it('requires the revise permission besides participating in the operation', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $scenario['actor']->revokePermissionTo(AccessPermission::MeasurementsRevise->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(app(MeasurementAuthorizationService::class)->canReviseMeasurement($scenario['actor']->fresh(), $may->fresh()))->toBeFalse()
        ->and(fn () => app(MeasurementRevisionService::class)->create($may->fresh(), $scenario['actor']->fresh(), 'Correção.'))
        ->toThrow(AuthorizationException::class);

    expect(Measurement::query()->where('revision_number', '>', 0)->exists())->toBeFalse();
});

it('refuses users who only see the operation by delegation or have the permission without participating', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $outsider = revisionOutsider([AccessPermission::MeasurementsRevise->value, 'measurements.review']);
    $delegate = revisionOutsider([AccessPermission::MeasurementsRevise->value, 'measurements.review']);
    ResponsibilityDelegation::factory()->active()->forOperation($scenario['operation'])->create([
        'delegator_user_id' => $scenario['actor']->id,
        'delegate_user_id' => $delegate->id,
    ]);
    $authorization = app(MeasurementAuthorizationService::class);

    expect($authorization->canReviseMeasurement($outsider, $may->fresh()))->toBeFalse()
        ->and($authorization->canReviseMeasurement($delegate, $may->fresh()))->toBeFalse()
        ->and(fn () => app(MeasurementRevisionService::class)->create($may->fresh(), $delegate, 'Correção.'))
        ->toThrow(AuthorizationException::class);
});

it('refuses revisions while the operation is not in progress', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    DB::table('operations')->where('id', $scenario['operation']->id)->update(['status' => 'completed']);

    expect(app(MeasurementAuthorizationService::class)->canReviseMeasurement($scenario['actor'], $may->fresh()->load('operation')))->toBeFalse()
        ->and(fn () => app(MeasurementRevisionService::class)->create($may->fresh(), $scenario['actor'], 'Correção.'))
        ->toThrow(AuthorizationException::class);
});

it('records the administrative bypass as the authority of a super-admin who does not participate', function () {
    $scenario = Scenario::plan();
    $may = Scenario::finalized($scenario, '2026-05', 10);
    $superAdmin = User::factory()->withTwoFactor()->create();
    $superAdmin->assignRole('super-admin');

    $revision = app(MeasurementRevisionService::class)->create($may->fresh(), $superAdmin, 'Correção administrativa.');
    $created = Activity::query()->where('description', 'measurement_revision_created')->where('subject_id', $revision->id)->sole();

    expect($created->properties['admin_override'])->toBeTrue()
        ->and($created->properties['delegated'])->toBeFalse()
        ->and($created->properties['actual_actor_user_id'])->toBe($superAdmin->id)
        ->and($created->causer_id)->toBe($superAdmin->id);

    // O participante direto age pela própria participação.
    app(MeasurementRevisionService::class)->cancel($revision->fresh(), $scenario['actor'], 'Aberta pela administração por engano.');
    $cancelled = Activity::query()->where('description', 'measurement_revision_cancelled')->where('subject_id', $revision->id)->sole();

    expect($cancelled->properties['admin_override'])->toBeFalse();
});

it('keeps the invariants for the super-admin too, whose policies are bypassed', function () {
    $scenario = Scenario::plan();
    $may = Scenario::awaitingPayment($scenario, '2026-05', 10);
    Scenario::pay($scenario, $may, Scenario::expected(10));
    $superAdmin = User::factory()->withTwoFactor()->create();
    $superAdmin->assignRole('super-admin');

    expect(fn () => app(MeasurementRevisionService::class)->create($may->fresh(), $superAdmin, 'Correção.'))
        ->toThrow(MeasurementWorkflowException::class, MeasurementRevisionService::ELIGIBILITY_REFUSAL);
});

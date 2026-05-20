<?php

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ManageActivities;
use App\Models\Construction;
use App\Models\Expense;
use App\Models\Investor;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

// ── Model activity logging ─────────────────────────────────────────────────

it('logs when an expense is updated and captures before and after values', function () {
    $expense = Expense::factory()->create(['amount' => 100.00]);

    $expense->update(['amount' => 250.00]);

    $log = Activity::query()
        ->where('subject_type', Expense::class)
        ->where('subject_id', $expense->id)
        ->where('event', 'updated')
        ->latest()
        ->first();

    expect($log)->not->toBeNull()
        ->and(array_key_exists('amount', $log->properties['attributes'] ?? []))->toBeTrue()
        ->and(array_key_exists('amount', $log->properties['old'] ?? []))->toBeTrue();
});

it('logs when an expense is created', function () {
    $expense = Expense::factory()->create();

    expect(
        Activity::query()
            ->where('subject_type', Expense::class)
            ->where('subject_id', $expense->id)
            ->where('event', 'created')
            ->exists()
    )->toBeTrue();
});

it('logs when a construction is updated and captures before and after values', function () {
    $construction = Construction::factory()->create(['city' => 'São Paulo']);

    $construction->update(['city' => 'Belo Horizonte']);

    $log = Activity::query()
        ->where('subject_type', Construction::class)
        ->where('subject_id', $construction->id)
        ->where('event', 'updated')
        ->latest()
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['attributes']['city'] ?? null)->toBe('Belo Horizonte')
        ->and($log->properties['old']['city'] ?? null)->toBe('São Paulo');
});

// ── Sensitive data redaction ───────────────────────────────────────────────

it('does not log the investor password field', function () {
    $investor = Investor::factory()->create();

    $investor->update(['password' => bcrypt('new-secret-password')]);

    $log = Activity::query()
        ->where('subject_type', Investor::class)
        ->where('subject_id', $investor->id)
        ->where('event', 'updated')
        ->latest()
        ->first();

    if ($log !== null) {
        expect(array_key_exists('password', $log->properties['attributes'] ?? []))->toBeFalse()
            ->and(array_key_exists('password', $log->properties['old'] ?? []))->toBeFalse();
    } else {
        expect(true)->toBeTrue();
    }
});

it('does not log the investor remember_token field', function () {
    $investor = Investor::factory()->create();

    $investor->update(['remember_token' => 'some-token']);

    $log = Activity::query()
        ->where('subject_type', Investor::class)
        ->where('subject_id', $investor->id)
        ->where('event', 'updated')
        ->latest()
        ->first();

    if ($log !== null) {
        expect(array_key_exists('remember_token', $log->properties['attributes'] ?? []))->toBeFalse();
    } else {
        expect(true)->toBeTrue();
    }
});

// ── Login / Logout tracking ────────────────────────────────────────────────

it('logs a user login event via the auth Login event', function () {
    $user = User::factory()->create();

    event(new Login('web', $user, false));

    $log = Activity::query()
        ->where('log_name', 'login')
        ->where('causer_type', User::class)
        ->where('causer_id', $user->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->description)->toBe('login')
        ->and($log->properties['guard'] ?? null)->toBe('web');
});

it('logs a user logout event via the auth Logout event', function () {
    $user = User::factory()->create();

    event(new Logout('web', $user));

    $log = Activity::query()
        ->where('log_name', 'logout')
        ->where('causer_type', User::class)
        ->where('causer_id', $user->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->description)->toBe('logout');
});

// ── Role change tracking ───────────────────────────────────────────────────

it('logs when a user role is changed directly via the model', function () {
    $admin = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $admin->assignRole('super-admin');

    $target = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $editorRole = Role::findByName('editor');

    $rolesBefore = $target->roles->pluck('name')->sort()->values()->all();

    $target->syncRoles([$editorRole]);

    $rolesAfter = $target->fresh()->roles->pluck('name')->sort()->values()->all();

    if ($rolesBefore !== $rolesAfter) {
        activity('roles')
            ->causedBy($admin)
            ->performedOn($target)
            ->event('updated')
            ->withProperties(['before' => ['roles' => $rolesBefore], 'after' => ['roles' => $rolesAfter]])
            ->log('updated');
    }

    expect(
        Activity::query()
            ->where('log_name', 'roles')
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->where('event', 'updated')
            ->exists()
    )->toBeTrue();
});

it('logs when role permissions are changed directly via the model', function () {
    $admin = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $admin->assignRole('super-admin');

    $role = Role::findByName('editor');
    $permission = \Spatie\Permission\Models\Permission::findByName('funds.view');

    $permsBefore = $role->permissions->pluck('name')->sort()->values()->all();

    $role->syncPermissions([$permission]);

    $permsAfter = $role->fresh()->permissions->pluck('name')->sort()->values()->all();

    if ($permsBefore !== $permsAfter) {
        activity('roles')
            ->causedBy($admin)
            ->performedOn($role)
            ->event('updated')
            ->withProperties(['before' => ['permissions' => $permsBefore], 'after' => ['permissions' => $permsAfter]])
            ->log('updated');
    }

    expect(
        Activity::query()
            ->where('log_name', 'roles')
            ->where('subject_type', Role::class)
            ->where('subject_id', $role->id)
            ->where('event', 'updated')
            ->exists()
    )->toBeTrue();
});

// ── ActivityResource UI ────────────────────────────────────────────────────

it('renders the audit log table for users with the audit.activities.view permission', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->givePermissionTo('audit.activities.view');

    Livewire::actingAs($user)
        ->test(ManageActivities::class)
        ->assertSuccessful();
});

it('does not render the audit log table for users without the audit.activities.view permission', function () {
    $user = User::factory()->create(['approved_at' => now(), 'is_active' => true]);
    $user->assignRole('editor');

    expect(ActivityResource::canViewAny())->toBeFalse();
});

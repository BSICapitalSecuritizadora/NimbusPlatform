<?php

use App\Filament\Pages\MyAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = makeAdminUser();
});

it('changes the password with the correct current password', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('passwordData.current_password', 'password')
        ->set('passwordData.password', 'new-secure-password-123')
        ->set('passwordData.password_confirmation', 'new-secure-password-123')
        ->call('updatePassword')
        ->assertHasNoErrors()
        ->assertNotified('Senha alterada com sucesso.');

    expect(Hash::check('new-secure-password-123', $this->user->refresh()->password))->toBeTrue();
});

it('rejects a wrong current password', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('passwordData.current_password', 'wrong-password')
        ->set('passwordData.password', 'new-secure-password-123')
        ->set('passwordData.password_confirmation', 'new-secure-password-123')
        ->call('updatePassword')
        ->assertHasErrors(['passwordData.current_password']);

    expect(Hash::check('password', $this->user->refresh()->password))->toBeTrue();
});

it('requires the password confirmation', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('passwordData.current_password', 'password')
        ->set('passwordData.password', 'new-secure-password-123')
        ->set('passwordData.password_confirmation', 'different-password-456')
        ->call('updatePassword')
        ->assertHasErrors(['passwordData.password']);

    expect(Hash::check('password', $this->user->refresh()->password))->toBeTrue();
});

it('enforces the application password rules', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('passwordData.current_password', 'password')
        ->set('passwordData.password', 'short')
        ->set('passwordData.password_confirmation', 'short')
        ->call('updatePassword')
        ->assertHasErrors(['passwordData.password']);

    expect(Hash::check('password', $this->user->refresh()->password))->toBeTrue();
});

it('audits password changes without storing secrets', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('passwordData.current_password', 'password')
        ->set('passwordData.password', 'new-secure-password-123')
        ->set('passwordData.password_confirmation', 'new-secure-password-123')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $activity = Activity::query()
        ->where('event', 'password_changed')
        ->where('subject_id', $this->user->id)
        ->first();

    expect($activity)->not->toBeNull()
        ->and(json_encode($activity->properties))->not->toContain('new-secure-password-123');
});

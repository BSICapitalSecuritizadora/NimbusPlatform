<?php

use App\Filament\Pages\MyAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = makeAdminUser();
    $this->user->forceFill([
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ])->save();
});

function currentTwoFactorCode(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
}

it('starts the setup without enabling two-factor authentication', function () {
    $component = Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->assertSet('twoFactorEnabled', false)
        ->call('startTwoFactorSetup')
        ->assertSet('settingUpTwoFactor', true);

    expect($component->get('twoFactorQrCode'))->not->toBeEmpty()
        ->and($component->get('twoFactorManualKey'))->not->toBeEmpty();

    $this->user->refresh();

    expect($this->user->two_factor_secret)->not->toBeNull()
        ->and($this->user->two_factor_confirmed_at)->toBeNull()
        ->and($this->user->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('rejects an invalid confirmation code', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->call('startTwoFactorSetup')
        ->set('twoFactorCode', '000000')
        ->call('confirmTwoFactorSetup')
        ->assertHasErrors(['twoFactorCode']);

    expect($this->user->refresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('enables two-factor authentication with a valid code', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->call('startTwoFactorSetup')
        ->set('twoFactorCode', currentTwoFactorCode($this->user))
        ->call('confirmTwoFactorSetup')
        ->assertHasNoErrors()
        ->assertSet('twoFactorEnabled', true)
        ->assertNotified('Autenticação em dois fatores ativada.');

    $this->user->refresh();

    expect($this->user->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and($this->user->two_factor_confirmed_at)->not->toBeNull();

    $codes = json_decode(decrypt($this->user->two_factor_recovery_codes), true);

    expect($codes)->toBeArray()->toHaveCount(8);
});

it('stores secrets encrypted, never as plain text', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->call('startTwoFactorSetup')
        ->set('twoFactorCode', currentTwoFactorCode($this->user))
        ->call('confirmTwoFactorSetup')
        ->assertHasNoErrors();

    $stored = DB::table('users')->where('id', $this->user->id)->first();

    expect($stored->two_factor_secret)->not->toBe(decrypt($stored->two_factor_secret))
        ->and(json_decode($stored->two_factor_recovery_codes, true))->toBeNull();
});

it('regenerates recovery codes only with the current password', function () {
    enableTwoFactorForTest($this->user);

    $before = $this->user->fresh()->two_factor_recovery_codes;

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('regenerateRecoveryCodes', ['password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertNotified('Novos códigos de recuperação gerados.');

    expect($this->user->fresh()->two_factor_recovery_codes)->not->toBe($before)
        ->and($this->user->fresh()->two_factor_recovery_codes)->not->toBeNull();
});

it('rejects recovery code regeneration with a wrong password', function () {
    enableTwoFactorForTest($this->user);

    $before = $this->user->fresh()->two_factor_recovery_codes;

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('regenerateRecoveryCodes', ['password' => 'wrong-password'])
        ->assertHasActionErrors(['password']);

    expect($this->user->fresh()->two_factor_recovery_codes)->toBe($before);
});

it('disables two-factor authentication only with the current password', function () {
    enableTwoFactorForTest($this->user);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('disableTwoFactor', ['password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertSet('twoFactorEnabled', false)
        ->assertNotified('Autenticação em dois fatores desativada.');

    $this->user->refresh();

    expect($this->user->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and($this->user->two_factor_secret)->toBeNull()
        ->and($this->user->two_factor_recovery_codes)->toBeNull();
});

it('rejects disabling two-factor authentication with a wrong password', function () {
    enableTwoFactorForTest($this->user);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('disableTwoFactor', ['password' => 'wrong-password'])
        ->assertHasActionErrors(['password']);

    expect($this->user->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('does not affect another user two-factor configuration', function () {
    $other = User::factory()->withTwoFactor()->create();
    enableTwoFactorForTest($this->user);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('disableTwoFactor', ['password' => 'password'])
        ->assertHasNoActionErrors();

    expect($other->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('cleans up an abandoned setup on cancel', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->call('startTwoFactorSetup')
        ->call('cancelTwoFactorSetup')
        ->assertSet('settingUpTwoFactor', false);

    $this->user->refresh();

    expect($this->user->two_factor_secret)->toBeNull()
        ->and($this->user->two_factor_recovery_codes)->toBeNull();
});

it('throttles repeated confirmation attempts', function () {
    $component = Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->call('startTwoFactorSetup');

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $component->set('twoFactorCode', '000000')->call('confirmTwoFactorSetup');
    }

    $component->assertNotified('Muitas tentativas. Aguarde um minuto e tente novamente.');

    expect($this->user->refresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('audits two-factor changes without storing secrets', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->call('startTwoFactorSetup')
        ->set('twoFactorCode', currentTwoFactorCode($this->user))
        ->call('confirmTwoFactorSetup')
        ->assertHasNoErrors();

    $activity = Activity::query()
        ->where('event', 'two_factor_enabled')
        ->where('subject_id', $this->user->id)
        ->first();

    expect($activity)->not->toBeNull()
        ->and(json_encode($activity->properties))->not->toContain(decrypt($this->user->fresh()->two_factor_secret));
});

function enableTwoFactorForTest(User $user): void
{
    Livewire::actingAs($user)
        ->test(MyAccount::class)
        ->call('startTwoFactorSetup')
        ->set('twoFactorCode', currentTwoFactorCode($user))
        ->call('confirmTwoFactorSetup')
        ->assertHasNoErrors();
}

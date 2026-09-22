<?php

use App\Filament\Pages\MyAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    config(['session.driver' => 'database']);

    $this->user = makeAdminUser();
});

function seedAccountSession(array $overrides = []): string
{
    $id = $overrides['id'] ?? Str::random(40);

    DB::table('sessions')->insert(array_merge([
        'id' => $id,
        'user_id' => null,
        'ip_address' => '192.0.2.10',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36',
        'payload' => 'payload',
        'last_activity' => time(),
    ], $overrides, ['id' => $id]));

    return $id;
}

it('lists only the sessions of the authenticated user', function () {
    $other = User::factory()->create();
    seedAccountSession(['id' => 'own-session-1111', 'user_id' => $this->user->id]);
    seedAccountSession(['id' => 'other-session-2222', 'user_id' => $other->id]);

    $component = Livewire::actingAs($this->user)->test(MyAccount::class);
    $ids = array_column($component->get('activeSessions'), 'id');

    expect($ids)->toContain('own-session-1111')->not->toContain('other-session-2222');
});

it('flags the current session and describes the device honestly', function () {
    $currentId = session()->getId();
    seedAccountSession(['id' => $currentId, 'user_id' => $this->user->id]);

    $component = Livewire::actingAs($this->user)->test(MyAccount::class);
    $sessions = collect($component->get('activeSessions'));

    expect($sessions->firstWhere('id', $currentId))
        ->toMatchArray(['is_current' => true, 'device' => 'Windows · Chrome']);
});

it('revokes another own session', function () {
    seedAccountSession(['id' => 'revocable-session-1', 'user_id' => $this->user->id]);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('revokeSession', [], ['sessionId' => 'revocable-session-1'])
        ->assertHasNoActionErrors()
        ->assertNotified('Sessão encerrada.');

    expect(DB::table('sessions')->where('id', 'revocable-session-1')->exists())->toBeFalse();
});

it('refuses to revoke the current session', function () {
    $currentId = session()->getId();
    seedAccountSession(['id' => $currentId, 'user_id' => $this->user->id]);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('revokeSession', [], ['sessionId' => $currentId])
        ->assertNotified('Não foi possível encerrar esta sessão.');

    expect(DB::table('sessions')->where('id', $currentId)->exists())->toBeTrue();
});

it('cannot revoke another user session', function () {
    $other = User::factory()->create();
    seedAccountSession(['id' => 'foreign-session-1', 'user_id' => $other->id]);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('revokeSession', [], ['sessionId' => 'foreign-session-1'])
        ->assertNotified('Sessão não encontrada.');

    expect(DB::table('sessions')->where('id', 'foreign-session-1')->exists())->toBeTrue();
});

it('signs out of other sessions with the current password', function () {
    $currentId = session()->getId();
    seedAccountSession(['id' => $currentId, 'user_id' => $this->user->id]);
    seedAccountSession(['id' => 'stale-session-1', 'user_id' => $this->user->id]);
    seedAccountSession(['id' => 'stale-session-2', 'user_id' => $this->user->id]);

    $other = User::factory()->create();
    seedAccountSession(['id' => 'foreign-session-2', 'user_id' => $other->id]);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('signOutOtherSessions', ['password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertNotified('Outras sessões encerradas.');

    expect(DB::table('sessions')->where('id', $currentId)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'stale-session-1')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'stale-session-2')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'foreign-session-2')->exists())->toBeTrue();
});

it('rejects signing out of other sessions with a wrong password', function () {
    seedAccountSession(['id' => 'stale-session-3', 'user_id' => $this->user->id]);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->callAction('signOutOtherSessions', ['password' => 'wrong-password'])
        ->assertHasActionErrors(['password']);

    expect(DB::table('sessions')->where('id', 'stale-session-3')->exists())->toBeTrue();
});

it('explains when the session driver does not support management', function () {
    config(['session.driver' => 'array']);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->assertSee('não está disponível com o driver de sessão atual');
});

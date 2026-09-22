<?php

use App\Filament\Pages\MyAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = makeAdminUser();
});

it('updates the profile fields of the authenticated user', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.name', 'Anderson Cavalcante')
        ->set('profileData.phone', '(11) 99999-9999')
        ->set('profileData.cargo', 'Desenvolvedor')
        ->set('profileData.departamento', 'Tecnologia')
        ->set('profileData.bio', 'Responsável pela plataforma.')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertNotified('Perfil atualizado com sucesso.');

    expect($this->user->refresh())->toMatchArray([
        'name' => 'Anderson Cavalcante',
        'phone' => '(11) 99999-9999',
        'cargo' => 'Desenvolvedor',
        'departamento' => 'Tecnologia',
        'bio' => 'Responsável pela plataforma.',
    ]);
});

it('rejects an email already used by another user', function () {
    $other = User::factory()->create(['email' => 'taken@example.com']);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.email', 'taken@example.com')
        ->call('saveProfile')
        ->assertHasErrors(['profileData.email']);

    expect($this->user->refresh()->email)->not->toBe('taken@example.com');
});

it('resets the email verification when the email changes', function () {
    expect($this->user->email_verified_at)->not->toBeNull();

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.email', 'new-address@example.com')
        ->call('saveProfile')
        ->assertHasNoErrors();

    $this->user->refresh();

    expect($this->user->email)->toBe('new-address@example.com')
        ->and($this->user->email_verified_at)->toBeNull();
});

it('keeps the email verification when the email is unchanged', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.name', 'Same Email User')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($this->user->refresh()->email_verified_at)->not->toBeNull();
});

it('does not touch other users when saving the profile', function () {
    $other = User::factory()->create(['name' => 'Other Person']);

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.name', 'Changed Name')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($other->refresh()->name)->toBe('Other Person');
});

it('uploads an avatar to the public disk', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.avatar', UploadedFile::fake()->image('avatar.jpg', 200, 200))
        ->call('saveProfile')
        ->assertHasNoErrors();

    $path = $this->user->refresh()->avatar_path;

    expect($path)->toStartWith('avatars/'.$this->user->id.'/')
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and($this->user->hasAvatar())->toBeTrue();
});

it('replaces the avatar and deletes the previous file', function () {
    Storage::disk('public')->put('avatars/'.$this->user->id.'/old.jpg', 'old-bytes');
    $this->user->forceFill(['avatar_path' => 'avatars/'.$this->user->id.'/old.jpg'])->save();

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.avatar', UploadedFile::fake()->image('new.jpg', 200, 200))
        ->call('saveProfile')
        ->assertHasNoErrors();

    $path = $this->user->refresh()->avatar_path;

    expect($path)->not->toBe('avatars/'.$this->user->id.'/old.jpg')
        ->and(Storage::disk('public')->exists('avatars/'.$this->user->id.'/old.jpg'))->toBeFalse()
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('removes the avatar and falls back to initials', function () {
    Storage::disk('public')->put('avatars/'.$this->user->id.'/old.jpg', 'old-bytes');
    $this->user->forceFill([
        'name' => 'Anderson Cavalcante',
        'avatar_path' => 'avatars/'.$this->user->id.'/old.jpg',
    ])->save();

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.avatar', null)
        ->call('saveProfile')
        ->assertHasNoErrors();

    $this->user->refresh();

    expect($this->user->avatar_path)->toBeNull()
        ->and($this->user->hasAvatar())->toBeFalse()
        ->and($this->user->avatarUrl())->toBeNull()
        ->and($this->user->initials())->toBe('AC')
        ->and(Storage::disk('public')->exists('avatars/'.$this->user->id.'/old.jpg'))->toBeFalse();
});

it('rejects non-image avatar uploads', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.avatar', UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))
        ->call('saveProfile')
        ->assertHasErrors(['profileData.avatar']);

    expect($this->user->refresh()->avatar_path)->toBeNull();
});

it('rejects oversized avatar uploads', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.avatar', UploadedFile::fake()->image('huge.jpg', 200, 200)->size(3000))
        ->call('saveProfile')
        ->assertHasErrors(['profileData.avatar']);

    expect($this->user->refresh()->avatar_path)->toBeNull();
});

it('rejects a forged avatar path pointing at another user file', function () {
    $other = User::factory()->create();
    Storage::disk('public')->put('avatars/'.$other->id.'/victim.jpg', 'victim-bytes');

    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.avatar', ['avatars/'.$other->id.'/victim.jpg'])
        ->call('saveProfile')
        ->assertHasErrors(['profileData.avatar']);

    expect($this->user->refresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('public')->exists('avatars/'.$other->id.'/victim.jpg'))->toBeTrue();
});

it('audits profile changes through the user activity log', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->set('profileData.name', 'Audited Name')
        ->call('saveProfile')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('activity_log', [
        'subject_type' => User::class,
        'subject_id' => $this->user->id,
    ]);
});

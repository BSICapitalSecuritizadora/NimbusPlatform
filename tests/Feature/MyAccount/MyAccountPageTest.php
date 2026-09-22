<?php

use App\Filament\Pages\MyAccount;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = makeAdminUser();
});

it('redirects guests to the panel login', function () {
    $this->get(MyAccount::getUrl(panel: 'admin'))->assertRedirect('/admin/login');
});

it('renders the three account sections for the authenticated user', function () {
    $this->actingAs($this->user)
        ->get(MyAccount::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Minha Conta')
        ->assertSee('Perfil')
        ->assertSee('Personalização')
        ->assertSee('Segurança')
        ->assertSee('Autenticação em dois fatores')
        ->assertSee('Sessões ativas');
});

it('adds a Minha Conta entry to the user menu before logout', function () {
    $content = $this->actingAs($this->user)
        ->get(MyAccount::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->getContent();

    expect($content)
        ->toContain('Minha Conta')
        ->toContain('/admin/minha-conta');
});

it('does not register the page in the sidebar navigation', function () {
    expect(MyAccount::shouldRegisterNavigation())->toBeFalse();
});

it('falls back to generated initials in the topbar when no avatar exists', function () {
    expect($this->user->getFilamentAvatarUrl())->toBeNull();

    $content = $this->actingAs($this->user)
        ->get(MyAccount::getUrl(panel: 'admin'))
        ->getContent();

    expect($content)->toContain('ui-avatars.com/api/?name=');
});

it('reflects the uploaded avatar in the topbar', function () {
    Storage::disk('public')->put('avatars/'.$this->user->id.'/photo.jpg', 'fake-image-bytes');
    $this->user->forceFill(['avatar_path' => 'avatars/'.$this->user->id.'/photo.jpg'])->save();

    $content = $this->actingAs($this->user)
        ->get(MyAccount::getUrl(panel: 'admin'))
        ->getContent();

    expect($content)->toContain('avatars/'.$this->user->id.'/photo.jpg');
});

it('loads the profile form with the current user data', function () {
    Livewire::actingAs($this->user)
        ->test(MyAccount::class)
        ->assertSet('profileData.name', $this->user->name)
        ->assertSet('profileData.email', $this->user->email)
        ->assertSet('twoFactorEnabled', true);
});

<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('configures real keyboard shortcuts for the global search', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getGlobalSearchKeyBindings())
        ->toBe(['command+k', 'ctrl+k']);
});

it('renders the refined topbar context and platform shortcut', function () {
    $user = User::factory()->withTwoFactor()->create([
        'name' => 'Anderson Cavalcante',
        'cargo' => null,
    ]);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)')
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('bsi-topbar-user-context', false)
        ->assertSee('Anderson Cavalcante')
        ->assertSee('Administrador')
        ->assertSee('CTRL + K');
});

it('prefers the registered job title in the topbar context', function () {
    $user = User::factory()->withTwoFactor()->create([
        'name' => 'Anderson Cavalcante',
        'cargo' => 'Diretor de Operações',
    ]);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Diretor de Operações');
});

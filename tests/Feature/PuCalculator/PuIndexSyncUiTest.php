<?php

use App\Filament\Resources\IndexRates\Pages\ListIndexRates;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows sync actions for an admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListIndexRates::class)
        ->assertOk()
        ->assertActionVisible('syncCdi')
        ->assertActionVisible('syncIpca');
});

it('hides sync actions from users without pu.index.sync', function () {
    // Pode visualizar a tela (pu.dashboard.view), mas não tem pu.index.sync.
    $user = User::factory()->create();
    $user->givePermissionTo('pu.dashboard.view');
    $this->actingAs($user);

    Livewire::test(ListIndexRates::class)
        ->assertOk()
        ->assertActionHidden('syncCdi')
        ->assertActionHidden('syncIpca');
});

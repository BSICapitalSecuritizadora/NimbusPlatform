<?php

use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
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

function makeUserWithRole(string $role): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('lets an editor operate the curve but not homologate or invalidate', function () {
    $this->actingAs(makeUserWithRole('editor'));
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertActionVisible('generatePuDailyCurve')
        ->assertActionVisible('validatePuDailyCurve')
        ->assertActionVisible('exportPuDailyCurve')
        ->assertActionVisible('configurePuCalculation')
        ->assertActionHidden('homologatePuCurve')
        ->assertActionHidden('invalidatePuCurve');
});

it('lets an admin homologate and invalidate the curve', function () {
    $this->actingAs(makeUserWithRole('admin'));
    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()])
        ->assertActionVisible('homologatePuCurve')
        ->assertActionVisible('invalidatePuCurve')
        ->assertActionVisible('puCurvePanel');
});

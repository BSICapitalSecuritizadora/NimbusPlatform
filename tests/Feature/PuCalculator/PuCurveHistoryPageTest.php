<?php

use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Pages\PuCurveHistory;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
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

it('renders the version timeline with status badges and audit', function () {
    $user = makeAdminUser();
    $this->actingAs($user);

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
    EmissionPuCurveVersion::factory()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v1',
        'generated_by' => $user->id,
    ]);
    EmissionPuCurveVersion::factory()->homologated()->create([
        'emission_id' => $emission->id,
        'calculation_version' => 'v2',
        'generated_by' => $user->id,
        'homologated_by' => $user->id,
    ]);

    Livewire::test(PuCurveHistory::class, ['record' => $emission->getRouteKey()])
        ->assertOk()
        ->assertSee('v2')
        ->assertSee('Homologada')
        ->assertSee('Auditoria das ações');
});

it('blocks the history page for users without pu.curve.view', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->assignRole('commercial-representative');
    $this->actingAs($user);

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    expect(PuCurveHistory::canAccess())->toBeFalse();

    $this->get(EmissionResource::getUrl('pu-history', ['record' => $emission]))
        ->assertForbidden();
});

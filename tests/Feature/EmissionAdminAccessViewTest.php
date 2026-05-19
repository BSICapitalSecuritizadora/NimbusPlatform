<?php

use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\EmissionResource\RelationManagers\EmissionAccessesRelationManager;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Models\Emission;
use App\Models\EmissionAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('registers the public access relation manager on emissions', function () {
    expect(EmissionResource::getRelations())->toContain(EmissionAccessesRelationManager::class);
});

it('renders the public access records inside the emission admin relation manager', function () {
    $this->actingAs(makeAdminUser());

    $emission = Emission::factory()->active()->create([
        'name' => 'CRI Dom Aloysio',
        'if_code' => '26C0589381',
    ]);

    $access = EmissionAccess::factory()->for($emission)->create([
        'requester_name' => 'Anderson Cavalcante',
        'requester_email' => 'anderson.cavalcante@bsicapital.com.br',
        'requester_phone' => '(11) 91234-5678',
        'verified_at' => now(),
    ]);

    Livewire::test(EmissionAccessesRelationManager::class, [
        'ownerRecord' => $emission,
        'pageClass' => EditEmission::class,
    ])
        ->loadTable()
        ->assertCanSeeTableRecords([$access])
        ->assertTableColumnExists('requester_name')
        ->assertTableColumnExists('requester_email')
        ->assertTableColumnExists('requester_phone')
        ->assertTableColumnExists('emission.name')
        ->assertTableColumnStateSet('requester_name', 'Anderson Cavalcante', $access)
        ->assertTableColumnStateSet('requester_email', 'anderson.cavalcante@bsicapital.com.br', $access)
        ->assertTableColumnStateSet('requester_phone', '(11) 91234-5678', $access)
        ->assertTableColumnStateSet('emission.name', 'CRI Dom Aloysio', $access);
});

<?php

use App\Domain\PuCalculator\Services\PuAuditLogService;
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

it('renders the empty state and status summary when no curve versions exist', function () {
    $user = makeAdminUser();
    $this->actingAs($user);

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    activity(PuAuditLogService::LOG_NAME)
        ->performedOn($emission)
        ->causedBy($user)
        ->withProperties(['calculation_version' => null])
        ->log('pu_parameters_updated');

    Livewire::test(PuCurveHistory::class, ['record' => $emission->getRouteKey()])
        ->assertOk()
        ->assertSee('Status da Curva')
        ->assertSee('Sem versão gerada')
        ->assertSee('Versões da curva')
        ->assertSee('Nenhuma versão gerada')
        ->assertSee('As versões aparecerão aqui após o processamento da curva de PU.')
        ->assertSee('Auditoria das ações')
        ->assertSee('Parametros atualizados')
        ->assertSee($user->name)
        ->assertActionExists('backToEmission')
        ->assertActionExists('viewDivergenceReport');
});

it('renders empty audit state when no activities exist', function () {
    $user = makeAdminUser();
    $this->actingAs($user);

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    Livewire::test(PuCurveHistory::class, ['record' => $emission->getRouteKey()])
        ->assertOk()
        ->assertSee('Nenhum evento registrado')
        ->assertSee('Nenhum evento de auditoria registrado para esta curva de PU.');
});

it('renders multiple curve versions with respective status badges and details', function () {
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
        ->assertSee('Status da Curva')
        ->assertSee('v2')
        ->assertSee('Homologada')
        ->assertSee('v1')
        ->assertDontSee('Nenhuma versão gerada');
});

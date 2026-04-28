<?php

use App\Filament\Resources\ExpenseServiceProviderTypes\Pages\CreateExpenseServiceProviderType;
use App\Filament\Resources\ExpenseServiceProviderTypes\Pages\EditExpenseServiceProviderType;
use App\Filament\Resources\ExpenseServiceProviderTypes\Pages\ListExpenseServiceProviderTypes;
use App\Models\ExpenseServiceProviderType;
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

it('shows the create type action on the service provider types list page', function () {
    $this->actingAs(makeExpenseServiceProviderTypeAdminUser());

    Livewire::test(ListExpenseServiceProviderTypes::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Cadastrar tipo');
});

it('creates a service provider type', function () {
    $this->actingAs(makeExpenseServiceProviderTypeAdminUser());

    Livewire::test(CreateExpenseServiceProviderType::class)
        ->fillForm([
            'name' => 'Agente de cobrança',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ExpenseServiceProviderType::query()->where('name', 'Agente de cobrança')->exists())
        ->toBeTrue();
});

it('updates a service provider type', function () {
    $this->actingAs(makeExpenseServiceProviderTypeAdminUser());

    $type = ExpenseServiceProviderType::factory()->create([
        'name' => 'Tipo inicial',
    ]);

    Livewire::test(EditExpenseServiceProviderType::class, [
        'record' => $type->getRouteKey(),
    ])
        ->fillForm([
            'name' => 'Tipo ajustado',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($type->fresh())
        ->name->toBe('Tipo ajustado');
});

it('prevents duplicate service provider type names', function () {
    $this->actingAs(makeExpenseServiceProviderTypeAdminUser());

    ExpenseServiceProviderType::factory()->create([
        'name' => 'Tipo repetido',
    ]);

    Livewire::test(CreateExpenseServiceProviderType::class)
        ->fillForm([
            'name' => 'Tipo repetido',
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);
});

function makeExpenseServiceProviderTypeAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

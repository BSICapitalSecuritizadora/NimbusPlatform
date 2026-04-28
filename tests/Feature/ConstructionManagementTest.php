<?php

use App\Filament\Resources\Constructions\Pages\CreateConstruction;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\Pages\ListConstructions;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\ExpenseServiceProvider;
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

it('shows the create construction action on the constructions list page', function () {
    $this->actingAs(makeConstructionAdminUser());

    Livewire::test(ListConstructions::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Cadastrar obra');
});

it('creates multiple constructions for the same emission', function () {
    $this->actingAs(makeConstructionAdminUser());

    $emission = Emission::factory()->create([
        'name' => 'CRI Obras Teste',
    ]);
    $measurementCompany = makeEngineeringMeasurementCompany([
        'name' => 'Engenharia Medidora',
        'cnpj' => '11222333000144',
    ]);

    Livewire::test(CreateConstruction::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'development_name' => 'Residencial Alpha',
            'development_cnpj' => '12.345.678/0001-90',
            'city' => 'São Paulo',
            'state' => 'SP',
            'construction_start_date' => '2026-05-01',
            'construction_end_date' => '2028-12-31',
            'estimated_value' => '1.250.000,75',
            'measurement_company_id' => $measurementCompany->id,
        ])
        ->assertFormSet([
            'measurement_company_cnpj' => '11.222.333/0001-44',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateConstruction::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'development_name' => 'Residencial Beta',
            'development_cnpj' => '98.765.432/0001-10',
            'city' => 'Campinas',
            'state' => 'SP',
            'construction_start_date' => '2026-07-01',
            'construction_end_date' => '2029-01-31',
            'estimated_value' => '2.000.000,00',
            'measurement_company_id' => $measurementCompany->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $constructions = Construction::query()
        ->where('emission_id', $emission->id)
        ->orderBy('development_name')
        ->get();

    expect($constructions)->toHaveCount(2)
        ->and($constructions->pluck('development_name')->all())->toBe([
            'Residencial Alpha',
            'Residencial Beta',
        ])
        ->and($constructions->first()?->development_cnpj)->toBe('12345678000190')
        ->and($constructions->first()?->estimated_value)->toBe('1250000.75')
        ->and($constructions->first()?->measurement_company_id)->toBe($measurementCompany->id);
});

it('requires a measurement company with Engenharia type', function () {
    $this->actingAs(makeConstructionAdminUser());

    $emission = Emission::factory()->create();
    $serviceProviderType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Servicer',
    ]);
    $invalidMeasurementCompany = ExpenseServiceProvider::factory()->create([
        'expense_service_provider_type_id' => $serviceProviderType->id,
    ]);

    Livewire::test(CreateConstruction::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'development_name' => 'Residencial sem engenharia',
            'development_cnpj' => '12.345.678/0001-90',
            'city' => 'São Paulo',
            'state' => 'SP',
            'construction_start_date' => '2026-05-01',
            'construction_end_date' => '2028-12-31',
            'estimated_value' => '1.250.000,75',
            'measurement_company_id' => $invalidMeasurementCompany->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['measurement_company_id']);

    expect(Construction::query()->count())->toBe(0);
});

it('requires the construction mandatory fields', function () {
    $this->actingAs(makeConstructionAdminUser());

    Livewire::test(CreateConstruction::class)
        ->call('create')
        ->assertHasFormErrors([
            'emission_id' => 'required',
            'development_name' => 'required',
            'development_cnpj' => 'required',
            'city' => 'required',
            'state' => 'required',
            'construction_start_date' => 'required',
            'construction_end_date' => 'required',
            'estimated_value' => 'required',
            'measurement_company_id' => 'required',
        ]);
});

it('formats derived values when editing a construction', function () {
    $this->actingAs(makeConstructionAdminUser());

    $measurementCompany = makeEngineeringMeasurementCompany([
        'cnpj' => '11222333000144',
    ]);
    $construction = Construction::factory()->create([
        'measurement_company_id' => $measurementCompany->id,
        'development_cnpj' => '12345678000190',
        'estimated_value' => 1250000.75,
    ]);

    Livewire::test(EditConstruction::class, [
        'record' => $construction->getRouteKey(),
    ])
        ->assertFormSet([
            'development_cnpj' => '12.345.678/0001-90',
            'estimated_value' => '1.250.000,75',
            'measurement_company_cnpj' => '11.222.333/0001-44',
        ]);
});

function makeEngineeringMeasurementCompany(array $attributes = []): ExpenseServiceProvider
{
    $engineeringType = ExpenseServiceProviderType::query()->firstOrCreate([
        'name' => Construction::MEASUREMENT_COMPANY_TYPE_NAME,
    ]);

    return ExpenseServiceProvider::factory()->create(array_merge([
        'expense_service_provider_type_id' => $engineeringType->id,
    ], $attributes));
}

function makeConstructionAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

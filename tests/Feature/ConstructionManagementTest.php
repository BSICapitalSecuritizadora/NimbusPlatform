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
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('shows the create construction action and filters on the constructions list page', function () {
    $this->actingAs(makeConstructionAdminUser());

    Livewire::test(ListConstructions::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Cadastrar obra')
        ->assertTableFilterExists('emission_id')
        ->assertTableFilterExists('development_name')
        ->assertTableFilterExists('measurement_company_id')
        ->assertTableFilterExists('state');
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
            'construction_start_date' => '05/2026',
            'construction_end_date' => '12/2028',
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
            'construction_start_date' => '07/2026',
            'construction_end_date' => '01/2029',
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
        ->and($constructions->first()?->construction_start_date?->toDateString())->toBe('2026-05-01')
        ->and($constructions->first()?->construction_end_date?->toDateString())->toBe('2028-12-01')
        ->and($constructions->first()?->estimated_value)->toBe('1250000.75')
        ->and($constructions->first()?->measurement_company_id)->toBe($measurementCompany->id);
});

it('keeps the selected emission when saving and creating another construction', function () {
    $this->actingAs(makeConstructionAdminUser());

    $emission = Emission::factory()->create();
    $measurementCompany = makeEngineeringMeasurementCompany([
        'cnpj' => '11222333000144',
    ]);

    Livewire::test(CreateConstruction::class)
        ->assertSee('Salvar e criar outro da mesma emissão')
        ->fillForm([
            'emission_id' => $emission->id,
            'development_name' => 'Residencial Alpha',
            'development_cnpj' => '12.345.678/0001-90',
            'city' => 'São Paulo',
            'state' => 'SP',
            'construction_start_date' => '05/2026',
            'construction_end_date' => '12/2028',
            'estimated_value' => '1.250.000,75',
            'measurement_company_id' => $measurementCompany->id,
        ])
        ->call('createAnother')
        ->assertHasNoFormErrors()
        ->assertFormSet([
            'emission_id' => $emission->id,
            'development_name' => null,
        ]);

    expect(Construction::query()->count())->toBe(1);
});

it('allows saving a construction without optional fields', function () {
    $this->actingAs(makeConstructionAdminUser());

    $emission = Emission::factory()->create();
    $measurementCompany = makeEngineeringMeasurementCompany([
        'cnpj' => '11222333000144',
    ]);

    Livewire::test(CreateConstruction::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'development_name' => 'Residencial Gamma',
            'development_cnpj' => null,
            'city' => 'Curitiba',
            'state' => 'PR',
            'construction_start_date' => null,
            'construction_end_date' => null,
            'estimated_value' => null,
            'measurement_company_id' => $measurementCompany->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $construction = Construction::query()->sole();

    expect($construction->emission_id)->toBe($emission->id)
        ->and($construction->development_name)->toBe('Residencial Gamma')
        ->and($construction->development_cnpj)->toBeNull()
        ->and($construction->construction_start_date)->toBeNull()
        ->and($construction->construction_end_date)->toBeNull()
        ->and($construction->estimated_value)->toBeNull()
        ->and($construction->measurement_company_id)->toBe($measurementCompany->id);
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
            'construction_start_date' => '05/2026',
            'construction_end_date' => '12/2028',
            'estimated_value' => '1.250.000,75',
            'measurement_company_id' => $invalidMeasurementCompany->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['measurement_company_id']);

    expect(Construction::query()->count())->toBe(0);
});

it('requires the construction conclusion month to be after or equal to the start month', function () {
    $this->actingAs(makeConstructionAdminUser());

    $emission = Emission::factory()->create();
    $measurementCompany = makeEngineeringMeasurementCompany();

    Livewire::test(CreateConstruction::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'development_name' => 'Residencial com data inválida',
            'development_cnpj' => '12.345.678/0001-90',
            'city' => 'São Paulo',
            'state' => 'SP',
            'construction_start_date' => '05/2026',
            'construction_end_date' => '04/2026',
            'estimated_value' => '1.250.000,75',
            'measurement_company_id' => $measurementCompany->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['construction_end_date']);
});

it('filters constructions by development name', function () {
    $this->actingAs(makeConstructionAdminUser());

    $selectedConstruction = Construction::factory()->create([
        'development_name' => 'Residencial Alpha',
    ]);
    $otherConstruction = Construction::factory()->create([
        'development_name' => 'Residencial Beta',
    ]);

    Livewire::test(ListConstructions::class)
        ->assertCanSeeTableRecords([$selectedConstruction, $otherConstruction])
        ->filterTable('development_name', 'Residencial Alpha')
        ->assertCanSeeTableRecords([$selectedConstruction])
        ->assertCanNotSeeTableRecords([$otherConstruction]);
});

it('filters constructions by engineering measurement company', function () {
    $this->actingAs(makeConstructionAdminUser());

    $selectedMeasurementCompany = makeEngineeringMeasurementCompany([
        'name' => 'Engenharia Alpha',
    ]);
    $otherMeasurementCompany = makeEngineeringMeasurementCompany([
        'name' => 'Engenharia Beta',
    ]);

    $selectedConstruction = Construction::factory()->create([
        'measurement_company_id' => $selectedMeasurementCompany->id,
    ]);
    $otherConstruction = Construction::factory()->create([
        'measurement_company_id' => $otherMeasurementCompany->id,
    ]);

    Livewire::test(ListConstructions::class)
        ->assertCanSeeTableRecords([$selectedConstruction, $otherConstruction])
        ->filterTable('measurement_company_id', $selectedMeasurementCompany->id)
        ->assertCanSeeTableRecords([$selectedConstruction])
        ->assertCanNotSeeTableRecords([$otherConstruction]);
});

it('limits the measurement company filter options to engineering service providers', function () {
    $this->actingAs(makeConstructionAdminUser());

    $engineeringCompany = makeEngineeringMeasurementCompany([
        'name' => 'Engenharia Permitida',
    ]);
    $serviceProviderType = ExpenseServiceProviderType::factory()->create([
        'name' => 'Servicer',
    ]);
    $nonEngineeringCompany = ExpenseServiceProvider::factory()->create([
        'name' => 'Prestador Bloqueado',
        'expense_service_provider_type_id' => $serviceProviderType->id,
    ]);

    Livewire::test(ListConstructions::class)
        ->assertTableFilterExists('measurement_company_id', function (SelectFilter $filter) use ($engineeringCompany, $nonEngineeringCompany): bool {
            $optionIds = $filter->getRelationshipQuery()->pluck('id')->all();

            return $filter->getRelationshipName() === 'measurementCompany'
                && in_array($engineeringCompany->id, $optionIds, true)
                && ! in_array($nonEngineeringCompany->id, $optionIds, true);
        });
});

it('requires the construction mandatory fields', function () {
    $this->actingAs(makeConstructionAdminUser());

    Livewire::test(CreateConstruction::class)
        ->call('create')
        ->assertHasFormErrors([
            'emission_id' => 'required',
            'development_name' => 'required',
            'city' => 'required',
            'state' => 'required',
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
        'construction_start_date' => '2026-04-01',
        'construction_end_date' => '2028-12-01',
        'estimated_value' => 1250000.75,
    ]);

    Livewire::test(EditConstruction::class, [
        'record' => $construction->getRouteKey(),
    ])
        ->assertFormSet([
            'development_cnpj' => '12.345.678/0001-90',
            'construction_start_date' => '04/2026',
            'construction_end_date' => '12/2028',
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

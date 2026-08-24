<?php

use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\CreateConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Pages\EditConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Emission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('creates a unit linked to the construction of the selected emission', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction] = unitEmissionAndConstruction();

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => '305',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $unit = ConstructionUnit::query()->sole();

    expect($unit->construction_id)->toBe($construction->id)
        ->and($unit->block)->toBe('01')
        ->and($unit->unit)->toBe('305')
        ->and($unit->getKey())->toBeInt()
        ->and($unit->construction->emission->id)->toBe($emission->id)
        ->and($unit->display_name)->toBe('Bloco 01 - Unidade 305');
});

it('keeps identifiers exactly as informed', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction] = unitEmissionAndConstruction();

    foreach ([['01', '305'], ['A', 'A101'], ['Torre 01', 'Loja 01'], ['B', 'Cobertura 02']] as [$block, $unit]) {
        Livewire::test(CreateConstructionUnit::class)
            ->fillForm([
                'emission_id' => $emission->id,
                'construction_id' => $construction->id,
                'block' => $block,
                'unit' => $unit,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    expect(ConstructionUnit::query()->orderBy('id')->get()->map(fn (ConstructionUnit $unit): string => $unit->block.'|'.$unit->unit)->all())
        ->toBe(['01|305', 'A|A101', 'Torre 01|Loja 01', 'B|Cobertura 02']);
});

it('rejects a duplicated block and unit inside the same construction', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction] = unitEmissionAndConstruction();

    ConstructionUnit::factory()->forConstruction($construction)->create([
        'block' => '01',
        'unit' => '305',
    ]);

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => '305',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'unit' => 'A unidade 305 do bloco 01 já está cadastrada para este empreendimento.',
        ]);

    expect(ConstructionUnit::query()->count())->toBe(1);
});

it('allows the same block and unit in a different construction', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $camboinhas] = unitEmissionAndConstruction();
    $piratininga = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Conviva Piratininga',
    ]);

    ConstructionUnit::factory()->forConstruction($camboinhas)->create(['block' => '01', 'unit' => '305']);

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $piratininga->id,
            'block' => '01',
            'unit' => '305',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ConstructionUnit::query()->count())->toBe(2);
});

it('enforces uniqueness in the database as well', function () {
    [, $construction] = unitEmissionAndConstruction();

    ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);

    expect(fn () => ConstructionUnit::query()->create([
        'construction_id' => $construction->id,
        'block' => '01',
        'unit' => '305',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('lets the duplicated unit be edited without tripping over itself', function () {
    $this->actingAs(makeAdminUser());

    [, $construction] = unitEmissionAndConstruction();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '305']);

    Livewire::test(EditConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertFormSet([
            'emission_id' => $construction->emission_id,
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => '305',
        ])
        ->fillForm(['unit' => '306'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($unit->refresh()->unit)->toBe('306');
});

it('lists only the constructions of the selected emission', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction] = unitEmissionAndConstruction();
    Construction::factory()->create([
        'emission_id' => Emission::factory()->create()->id,
        'development_name' => 'Empreendimento de Outra Emissão',
    ]);

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm(['emission_id' => $emission->id])
        ->assertFormFieldExists('construction_id', function (Select $field) use ($construction): bool {
            return array_keys($field->getOptions()) === [$construction->id];
        });
});

it('clears the construction when the emission changes', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $construction] = unitEmissionAndConstruction();
    $otherEmission = Emission::factory()->create();

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm(['emission_id' => $emission->id, 'construction_id' => $construction->id])
        ->assertFormSet(['construction_id' => $construction->id])
        ->fillForm(['emission_id' => $otherEmission->id])
        ->assertFormSet(['construction_id' => null]);
});

it('shows the emission, construction, block and unit on the list page', function () {
    $this->actingAs(makeAdminUser());

    [, $construction] = unitEmissionAndConstruction();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '01', 'unit' => '101']);

    Livewire::test(ListConstructionUnits::class)
        ->assertCanSeeTableRecords([$unit])
        ->assertTableColumnExists('construction.emission.name')
        ->assertTableColumnExists('construction.development_name')
        ->assertTableColumnExists('block')
        ->assertTableColumnExists('unit')
        ->assertSee('CRI Conviva')
        ->assertSee('Conviva Camboinhas')
        ->assertActionExists('create');
});

it('searches units by unit, block, construction and emission', function () {
    $this->actingAs(makeAdminUser());

    [, $camboinhas] = unitEmissionAndConstruction();
    [, $outra] = unitEmissionAndConstruction('CRA Outro', 'Residencial Outro');

    $target = ConstructionUnit::factory()->forConstruction($camboinhas)->create(['block' => '01', 'unit' => '305']);
    $other = ConstructionUnit::factory()->forConstruction($outra)->create(['block' => '99', 'unit' => '999']);

    Livewire::test(ListConstructionUnits::class)
        ->searchTable('305')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('Camboinhas')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('CRI Conviva')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other])
        ->searchTable('99')
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$target]);
});

it('filters units by emission and by construction', function () {
    $this->actingAs(makeAdminUser());

    [$emission, $camboinhas] = unitEmissionAndConstruction();
    $piratininga = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Conviva Piratininga',
    ]);
    [$otherEmission, $outra] = unitEmissionAndConstruction('CRA Outro', 'Residencial Outro');

    $camboinhasUnit = ConstructionUnit::factory()->forConstruction($camboinhas)->create();
    $piratiningaUnit = ConstructionUnit::factory()->forConstruction($piratininga)->create();
    $outraUnit = ConstructionUnit::factory()->forConstruction($outra)->create();

    Livewire::test(ListConstructionUnits::class)
        ->filterTable('emission', $emission->id)
        ->assertCanSeeTableRecords([$camboinhasUnit, $piratiningaUnit])
        ->assertCanNotSeeTableRecords([$outraUnit])
        ->filterTable('construction_id', $camboinhas->id)
        ->assertCanSeeTableRecords([$camboinhasUnit])
        ->assertCanNotSeeTableRecords([$piratiningaUnit, $outraUnit]);

    expect($otherEmission->constructionUnits()->count())->toBe(1);
});

it('exposes the units of an emission through its constructions', function () {
    [$emission, $camboinhas] = unitEmissionAndConstruction();
    $piratininga = Construction::factory()->create(['emission_id' => $emission->id]);

    ConstructionUnit::factory()->forConstruction($camboinhas)->count(3)->create();
    ConstructionUnit::factory()->forConstruction($piratininga)->count(2)->create();
    ConstructionUnit::factory()->create();

    expect($emission->constructionUnits()->count())->toBe(5)
        ->and($camboinhas->units()->count())->toBe(3);
});

it('removes the units together with the construction', function () {
    [, $construction] = unitEmissionAndConstruction();
    ConstructionUnit::factory()->forConstruction($construction)->count(2)->create();

    $construction->delete();

    expect(ConstructionUnit::query()->count())->toBe(0);
});

it('shows the unit details on the view page', function () {
    $this->actingAs(makeAdminUser());

    [, $construction] = unitEmissionAndConstruction();
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => '02', 'unit' => '201']);

    Livewire::test(ViewConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertSee('Dados da Unidade')
        ->assertSee('CRI Conviva')
        ->assertSee('Conviva Camboinhas')
        ->assertSee('02')
        ->assertSee('201');
});

it('gates the resource behind the construction permissions', function () {
    $role = Role::firstOrCreate(['name' => 'units-reader']);
    $role->syncPermissions([Permission::firstOrCreate(['name' => 'constructions.view'])]);

    $reader = makeAdminUser();
    $reader->syncRoles([$role]);

    $this->actingAs($reader);

    expect(ConstructionUnitResource::canViewAny())->toBeTrue()
        ->and(ConstructionUnitResource::canCreate())->toBeFalse()
        ->and(ConstructionUnitResource::canEdit(new ConstructionUnit))->toBeFalse()
        ->and(ConstructionUnitResource::canDelete(new ConstructionUnit))->toBeFalse();
});

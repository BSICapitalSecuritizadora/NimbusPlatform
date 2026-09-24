<?php

use App\Enums\UnitValueSource;
use App\Filament\Resources\Constructions\ConstructionResource;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\RelationManagers\SalesDiscountPoliciesRelationManager;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\CreateConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitValuesRelationManager;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Emission;
use App\Models\SalesDiscountPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('registers a unit with the base value pair', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => '101',
            'base_value' => '900.000,00',
            'base_value_reference_date' => '2026-01-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $unit = ConstructionUnit::sole();

    expect($unit->base_value)->toBe('900000.00')
        ->and($unit->base_value_reference_date->toDateString())->toBe('2026-01-01');
});

it('registers a unit with neither half of the pair', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => '101',
            'base_value' => null,
            'base_value_reference_date' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $unit = ConstructionUnit::sole();

    expect($unit->base_value)->toBeNull()
        ->and($unit->base_value_reference_date)->toBeNull()
        ->and($unit->hasBaseValue())->toBeFalse();
});

it('refuses a base value without its reference date', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => '101',
            'base_value' => '900.000,00',
            'base_value_reference_date' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['base_value_reference_date']);

    expect(ConstructionUnit::count())->toBe(0);
});

it('refuses a reference date without its base value', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => '101',
            'base_value' => null,
            'base_value_reference_date' => '2026-01-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['base_value']);

    expect(ConstructionUnit::count())->toBe(0);
});

it('accepts a zero base value as an informed amount', function () {
    $emission = Emission::factory()->create();
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    Livewire::test(CreateConstructionUnit::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'block' => '01',
            'unit' => '101',
            'base_value' => '0,00',
            'base_value_reference_date' => '2026-01-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ConstructionUnit::sole()->base_value)->toBe('0.00');
});

it('does not overwrite the base value when a new value is recorded', function () {
    $construction = Construction::factory()->create();
    $unit = ConstructionUnit::factory()->create([
        'construction_id' => $construction->id,
        'base_value' => '900000.00',
        'base_value_reference_date' => '2026-01-01',
    ]);

    Livewire::test(ConstructionUnitValuesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->callAction(TestAction::make('updateValue')->table(), [
            'value' => '1.000.000,00',
            'effective_from' => '2026-07-01',
            'reason' => 'Reajuste de tabela',
        ])
        ->assertHasNoActionErrors();

    $recorded = ConstructionUnitValue::sole();

    expect($unit->fresh()->base_value)->toBe('900000.00')
        ->and($recorded->value)->toBe('1000000.00')
        ->and($recorded->source)->toBe(UnitValueSource::Manual)
        ->and($recorded->reason)->toBe('Reajuste de tabela')
        ->and($recorded->created_by_id)->not->toBeNull();
});

it('requires a reason to update a value', function () {
    $unit = ConstructionUnit::factory()->create();

    Livewire::test(ConstructionUnitValuesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->callAction(TestAction::make('updateValue')->table(), [
            'value' => '1.000.000,00',
            'effective_from' => '2026-07-01',
            'reason' => null,
        ])
        ->assertHasActionErrors(['reason']);

    expect(ConstructionUnitValue::count())->toBe(0);
});

it('lists the value history newest first and offers no edit or delete', function () {
    $unit = ConstructionUnit::factory()->create();

    $older = ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-01-01')->worth('900000.00')->create();
    $newer = ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2026-07-01')->worth('1000000.00')->create();

    Livewire::test(ConstructionUnitValuesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('shows the resolved current value on the unit page', function () {
    $unit = ConstructionUnit::factory()->create([
        'base_value' => '900000.00',
        'base_value_reference_date' => '2020-01-01',
    ]);

    ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2020-06-01')->worth('1000000.00')->create();

    Livewire::test(ViewConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertSee('1.000.000,00')
        ->assertSee('Histórico de valores');
});

it('reports an unknown current value instead of zero', function () {
    $unit = ConstructionUnit::factory()->create(['base_value' => null, 'base_value_reference_date' => null]);

    Livewire::test(ViewConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertSee('Sem valor conhecido');
});

it('registers a discount policy from the construction page', function () {
    $construction = Construction::factory()->create();

    Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ])
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => '5.00',
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
            'reason' => 'Aprovação comercial',
        ])
        ->assertHasNoActionErrors();

    $policy = SalesDiscountPolicy::sole();

    expect($policy->construction_id)->toBe($construction->id)
        ->and($policy->maximum_discount_percent)->toBe('5.00')
        ->and($policy->effective_from->toDateString())->toBe('2026-07-01')
        ->and($policy->effective_until->toDateString())->toBe('2026-12-31')
        ->and($policy->created_by_id)->not->toBeNull();
});

it('refuses a discount outside the zero to one hundred range', function (string $percent) {
    $construction = Construction::factory()->create();

    Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ])
        ->callAction(TestAction::make('newPolicy')->table(), [
            'maximum_discount_percent' => $percent,
            'effective_from' => '2026-07-01',
            'reason' => 'Teste',
        ])
        ->assertHasActionErrors(['maximum_discount_percent']);

    expect(SalesDiscountPolicy::count())->toBe(0);
})->with([['-1'], ['100.01'], ['150']]);

it('never edits or deletes a registered policy', function () {
    $construction = Construction::factory()->create();

    $policy = SalesDiscountPolicy::factory()->forConstruction($construction)->effectiveFrom('2026-01-01')->create();

    Livewire::test(SalesDiscountPoliciesRelationManager::class, [
        'ownerRecord' => $construction,
        'pageClass' => EditConstruction::class,
    ])
        ->assertCanSeeTableRecords([$policy])
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('offers the batch value template on the units page', function () {
    Livewire::test(ListConstructionUnits::class)
        ->assertActionExists('downloadValueTemplate')
        ->assertActionExists('updateUnitValues');
});

it('downloads the batch value template', function () {
    $this->get(route('admin.construction-unit-values.template.download'))->assertOk();
});

it('refuses to delete a unit that already has value history', function () {
    $withHistory = ConstructionUnit::factory()->create();
    $withoutHistory = ConstructionUnit::factory()->create();

    ConstructionUnitValue::factory()->forUnit($withHistory)->create();

    expect(ConstructionUnitResource::canDelete($withHistory))->toBeFalse()
        ->and(ConstructionUnitResource::canDelete($withoutHistory))->toBeTrue();

    Livewire::test(ListConstructionUnits::class)
        ->assertTableActionHidden('delete', $withHistory)
        ->assertTableActionVisible('delete', $withoutHistory);
});

it('refuses to delete a construction that already has a discount policy', function () {
    $withPolicy = Construction::factory()->create();
    $withoutPolicy = Construction::factory()->create();

    SalesDiscountPolicy::factory()->forConstruction($withPolicy)->create();

    expect(ConstructionResource::canDelete($withPolicy))->toBeFalse()
        ->and(ConstructionResource::canDelete($withoutPolicy))->toBeTrue();
});

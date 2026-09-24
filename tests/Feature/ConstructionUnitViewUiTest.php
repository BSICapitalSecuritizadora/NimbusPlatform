<?php

use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\EditConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitExchangesRelationManager;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitValuesRelationManager;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitValue;
use App\Models\Emission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

it('summarizes the real unit in the header while retaining the complete details', function () {
    $emission = Emission::factory()->create(['name' => 'CRI Vista & Mar']);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Horizonte',
    ]);
    $unit = ConstructionUnit::factory()->forConstruction($construction)->create(['block' => 'G', 'unit' => '302']);

    $page = Livewire::test(ViewConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertSee('Visualizar Unidade')
        ->assertSee('CRI Vista & Mar · Residencial Horizonte · Bloco G · Unidade 302')
        ->assertActionVisible('edit')
        ->assertSeeInOrder(['Dados da Unidade', 'Emissão', 'CRI Vista & Mar', 'Empreendimento', 'Residencial Horizonte', 'Bloco', 'G', 'Unidade', '302']);

    expect($page->instance()->getSubheading())->toBe('CRI Vista & Mar · Residencial Horizonte · Bloco G · Unidade 302');
});

it('keeps the current amount and its origin together before the two reference dates', function (bool $withHistory) {
    $unit = ConstructionUnit::factory()->create([
        'base_value' => '500000.00',
        'base_value_reference_date' => '2020-01-01',
    ]);

    if ($withHistory) {
        ConstructionUnitValue::factory()->forUnit($unit)->effectiveFrom('2020-06-01')->worth('550000.00')->create();
    }

    Livewire::test(ViewConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertSeeInOrder([
            'Valor base',
            '500.000,00',
            'Valor vigente',
            $withHistory ? '550.000,00' : '500.000,00',
            $withHistory ? 'Histórico de valores' : 'Valor base da unidade',
            'Data de referência do valor base',
            '01/01/2020',
            'Vigente desde',
            $withHistory ? '01/06/2020' : '01/01/2020',
        ]);
})->with([false, true]);

it('keeps unknown financial values explicit', function () {
    $unit = ConstructionUnit::factory()->create(['base_value' => null, 'base_value_reference_date' => null]);

    Livewire::test(ViewConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertSeeInOrder(['Valor base', 'Não informado', 'Valor vigente', 'Sem valor conhecido', 'Data de referência do valor base', 'Não informada', 'Vigente desde', '—'])
        ->assertDontSee('Valor base da unidade');
});

it('retains both relation tabs and the native column controls without duplicate empty state actions', function (string $manager, string $heading) {
    $unit = ConstructionUnit::factory()->create();

    Livewire::test(ViewConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertSee('Histórico de Valores')
        ->assertSee('Permutas')
        ->set('activeRelationManager', '1')
        ->assertSee('Permutas');

    $relation = Livewire::test($manager, ['ownerRecord' => $unit, 'pageClass' => ViewConstructionUnit::class])
        ->assertSee($heading);

    expect($relation->instance()->getTable()->getColumnManagerTriggerAction()->getTooltip())->toBe('Colunas')
        ->and($relation->instance()->getTable()->getEmptyStateActions())->toBe([]);

    $editRelation = Livewire::test($manager, ['ownerRecord' => $unit, 'pageClass' => EditConstructionUnit::class]);

    expect($editRelation->instance()->getTable()->getColumnManagerTriggerAction()->getTooltip())->toBeNull();
})->with([
    [ConstructionUnitValuesRelationManager::class, 'Nenhuma atualização de valor'],
    [ConstructionUnitExchangesRelationManager::class, 'Nenhuma permuta registrada'],
]);

it('preserves the history page size and pagination with records', function () {
    $unit = ConstructionUnit::factory()->create();
    $records = ConstructionUnitValue::factory()->forUnit($unit)->count(26)
        ->sequence(fn ($sequence) => ['effective_from' => now()->subDays(26 - $sequence->index)->toDateString()])
        ->create();

    Livewire::test(ConstructionUnitValuesRelationManager::class, ['ownerRecord' => $unit, 'pageClass' => ViewConstructionUnit::class])
        ->assertActionVisible(TestAction::make('updateValue')->table())
        ->assertTableColumnExists('createdBy.name')
        ->assertTableColumnExists('created_at')
        ->set('tableRecordsPerPage', 25)
        ->assertCanSeeTableRecords($records->slice(1)->reverse(), inOrder: true)
        ->assertCanNotSeeTableRecords([$records->first()])
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords([$records->first()])
        ->set('tableRecordsPerPage', 10)
        ->assertCanSeeTableRecords($records->slice(16)->reverse(), inOrder: true);
});

it('preserves the resource permissions and financial action visibility for a reader', function () {
    $role = Role::firstOrCreate(['name' => 'unit-view-reader']);
    $role->syncPermissions([Permission::firstOrCreate(['name' => 'constructions.view'])]);
    auth()->user()->syncRoles([$role]);
    $unit = ConstructionUnit::factory()->create();

    expect(ConstructionUnitResource::canView($unit))->toBeTrue()
        ->and(ConstructionUnitResource::canEdit($unit))->toBeFalse();

    Livewire::test(ViewConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertSee('Dados da Unidade');

    Livewire::test(ConstructionUnitValuesRelationManager::class, ['ownerRecord' => $unit, 'pageClass' => ViewConstructionUnit::class])
        ->assertActionHidden(TestAction::make('updateValue')->table());

    Livewire::test(ConstructionUnitExchangesRelationManager::class, ['ownerRecord' => $unit, 'pageClass' => ViewConstructionUnit::class])
        ->assertActionHidden(TestAction::make('declareBaseline')->table());
});

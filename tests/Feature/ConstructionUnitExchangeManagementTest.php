<?php

use App\Enums\ConstructionUnitExchangeKind;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\ViewConstructionUnit;
use App\Filament\Resources\ConstructionUnits\RelationManagers\ConstructionUnitExchangesRelationManager;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\Emission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

function unitOfEmissionWithStatus(string $status): ConstructionUnit
{
    $emission = Emission::factory()->create(['status' => $status]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    return ConstructionUnit::factory()->create(['construction_id' => $construction->id]);
}

it('declares a baseline exchange while the emission is in draft', function () {
    $unit = unitOfEmissionWithStatus(Emission::STATUS_DRAFT);

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->callAction(TestAction::make('declareBaseline')->table(), [
            'exchange_value' => '450.000,00',
            'effective_from' => '2026-01-01',
            'reason' => 'Permuta acordada na estruturação',
        ])
        ->assertHasNoActionErrors();

    $exchange = ConstructionUnitExchange::sole();

    expect($exchange->construction_unit_id)->toBe($unit->id)
        ->and($exchange->exchange_value)->toBe('450000.00')
        ->and($exchange->effective_from->toDateString())->toBe('2026-01-01')
        ->and($exchange->ended_on)->toBeNull()
        ->and($exchange->kind)->toBe(ConstructionUnitExchangeKind::Baseline)
        ->and($exchange->contract_id)->toBeNull()
        ->and($exchange->created_by_id)->not->toBeNull();
});

it('requires a reason and a value to declare a baseline', function () {
    $unit = unitOfEmissionWithStatus(Emission::STATUS_DRAFT);

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->callAction(TestAction::make('declareBaseline')->table(), [
            'exchange_value' => null,
            'effective_from' => null,
            'reason' => null,
        ])
        ->assertHasActionErrors(['exchange_value', 'effective_from', 'reason']);

    expect(ConstructionUnitExchange::count())->toBe(0);
});

it('hides the declaration once the emission has left draft', function () {
    $draftUnit = unitOfEmissionWithStatus(Emission::STATUS_DRAFT);
    $liveUnit = unitOfEmissionWithStatus('active');

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $draftUnit,
        'pageClass' => ViewConstructionUnit::class,
    ])->assertActionVisible(TestAction::make('declareBaseline')->table());

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $liveUnit,
        'pageClass' => ViewConstructionUnit::class,
    ])->assertActionHidden(TestAction::make('declareBaseline')->table());
});

it('never offers edit, delete or bulk actions on a registered exchange', function () {
    $unit = unitOfEmissionWithStatus(Emission::STATUS_DRAFT);
    $exchange = ConstructionUnitExchange::factory()->forUnit($unit)->create();

    Livewire::test(ConstructionUnitExchangesRelationManager::class, [
        'ownerRecord' => $unit,
        'pageClass' => ViewConstructionUnit::class,
    ])
        ->assertCanSeeTableRecords([$exchange])
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('refuses to delete a unit that carries an exchange', function () {
    $withExchange = unitOfEmissionWithStatus(Emission::STATUS_DRAFT);
    $withoutExchange = ConstructionUnit::factory()->create(['construction_id' => $withExchange->construction_id]);

    ConstructionUnitExchange::factory()->forUnit($withExchange)->create();

    expect(ConstructionUnitResource::canDelete($withExchange))->toBeFalse()
        ->and(ConstructionUnitResource::canDelete($withoutExchange))->toBeTrue();
});

it('protects the exchange history from a unit deletion at the database level', function () {
    $unit = unitOfEmissionWithStatus(Emission::STATUS_DRAFT);
    ConstructionUnitExchange::factory()->forUnit($unit)->create();

    expect(fn () => $unit->delete())->toThrow(QueryException::class)
        ->and(ConstructionUnitExchange::count())->toBe(1);
});

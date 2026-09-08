<?php

use App\Enums\SalesBoardStaleImpact;
use App\Filament\Resources\SalesBoardCycles\Pages\ListSalesBoardCycles;
use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBaselinesRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleLinesRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleMovementsRelationManager;
use App\Filament\Resources\SalesBoardCycles\SalesBoardCycleResource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\CycleFixture;
use Tests\Support\SalesBoards\DerivationFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * @return array{cycle: SalesBoardCycle, construction: Construction, units: list<ConstructionUnit>, contract: Contract}
 */
function uiCycle(): array
{
    [$construction, $units] = CycleFixture::readyConstruction(3);

    $contract = DerivationFixture::contract($units[0], '2026-07-05', '600000.00');
    DerivationFixture::installment($contract, '001', '2026-08-10', '600000.00');

    CycleFixture::generate($construction);

    return ['cycle' => SalesBoardCycle::sole(), 'construction' => $construction, 'units' => $units, 'contract' => $contract];
}

it('lists the cycles with their current version', function () {
    $scenario = uiCycle();

    Livewire::test(ListSalesBoardCycles::class)
        ->assertCanSeeTableRecords([$scenario['cycle']])
        ->assertSee($scenario['construction']->development_name)
        ->assertSee('07/2026')
        ->assertSee('V1');
});

it('shows the frozen position on the view page', function () {
    $scenario = uiCycle();

    $this->get(SalesBoardCycleResource::getUrl('view', ['record' => $scenario['cycle']]))
        ->assertOk()
        ->assertSee('Posição congelada')
        ->assertSee('Estoque')
        ->assertSee('Financiado')
        ->assertSee('Quitado')
        ->assertSee('Permutado')
        ->assertSee('Resumo da fonte')
        ->assertSee('Resumo da posição');
});

it('lists the frozen units read only', function () {
    $scenario = uiCycle();

    Livewire::test(SalesBoardCycleLinesRelationManager::class, [
        'ownerRecord' => $scenario['cycle'],
        'pageClass' => ViewSalesBoardCycle::class,
    ])
        ->assertCanSeeTableRecords($scenario['cycle']->currentLines)
        ->assertCountTableRecords(3)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('lists the frozen movements read only, with the conformity verdict', function () {
    $scenario = uiCycle();

    Livewire::test(SalesBoardCycleMovementsRelationManager::class, [
        'ownerRecord' => $scenario['cycle'],
        'pageClass' => ViewSalesBoardCycle::class,
    ])
        ->assertCountTableRecords(1)
        ->assertSee('Venda')
        ->assertSee('Conforme')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('lists the version history read only', function () {
    $scenario = uiCycle();

    Livewire::test(SalesBoardCycleBaselinesRelationManager::class, [
        'ownerRecord' => $scenario['cycle'],
        'pageClass' => ViewSalesBoardCycle::class,
    ])
        ->assertCountTableRecords(1)
        ->assertSee('V1')
        ->assertSee('vigente')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});

it('offers no create, edit or delete route at all', function () {
    $scenario = uiCycle();

    expect(SalesBoardCycleResource::canCreate())->toBeFalse()
        ->and(SalesBoardCycleResource::canEdit($scenario['cycle']))->toBeFalse()
        ->and(SalesBoardCycleResource::canDelete($scenario['cycle']))->toBeFalse()
        ->and(SalesBoardCycleResource::canDeleteAny())->toBeFalse()
        // A validação da construtora é uma página própria e não torna o ciclo
        // editável: continua sem criar, sem editar e sem excluir.
        ->and(array_keys(SalesBoardCycleResource::getPages()))->toBe(['index', 'view', 'builder-review']);
});

it('checks for source changes without recalculating', function () {
    $scenario = uiCycle();
    $scenario['contract']->update(['sale_value' => '610000.00']);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('checkStale')
        ->assertNotified();

    expect(CycleFixture::currentBaseline($scenario['cycle'])->stale_impact)->toBe(SalesBoardStaleImpact::Material)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1);
});

it('requires a reason to recalculate', function () {
    $scenario = uiCycle();
    $scenario['contract']->update(['sale_value' => '610000.00']);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('recalculate', ['reason' => ''])
        ->assertHasActionErrors(['reason' => ['required']]);

    expect(SalesBoardCycleBaseline::query()->count())->toBe(1);
});

it('creates a new version through the recalculate action', function () {
    $scenario = uiCycle();
    $scenario['contract']->update(['sale_value' => '610000.00']);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('recalculate', ['reason' => 'Valor de venda corrigido pela construtora.'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(SalesBoardCycleBaseline::query()->orderBy('version')->pluck('version')->all())->toBe([1, 2])
        ->and(CycleFixture::currentBaseline($scenario['cycle'])->reason)
        ->toBe('Valor de venda corrigido pela construtora.');
});

it('does not create a version when the recalculate action finds nothing changed', function () {
    $scenario = uiCycle();

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->callAction('recalculate', ['reason' => 'Conferência de rotina sem alteração conhecida.'])
        ->assertHasNoActionErrors();

    expect(SalesBoardCycleBaseline::query()->count())->toBe(1);
});

it('freezes a competência through the generate action', function () {
    [$construction] = CycleFixture::readyConstruction(2);

    Livewire::test(ListSalesBoardCycles::class)
        ->callAction(TestAction::make('generateCycle'), [
            'construction_id' => $construction->id,
            'reference_month' => '2026-07-01',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(SalesBoardCycle::query()->count())->toBe(1)
        ->and(SalesBoardCycleBaseline::query()->count())->toBe(1);
});

it('never publishes a sales board from the interface', function () {
    $scenario = uiCycle();
    $scenario['contract']->update(['sale_value' => '610000.00']);

    Livewire::test(ViewSalesBoardCycle::class, ['record' => $scenario['cycle']->getKey()])
        ->assertActionDoesNotExist('publish')
        ->assertActionDoesNotExist('approve')
        ->assertActionDoesNotExist('delete')
        ->callAction('recalculate', ['reason' => 'Recálculo após correção do valor.']);

    expect(SalesBoard::query()->count())->toBe(0);
});

it('hides the cycles from a user without sales board permission', function () {
    uiCycle();

    $this->actingAs(User::factory()->withTwoFactor()->create());

    expect(SalesBoardCycleResource::canViewAny())->toBeFalse();
});

it('compares two versions from the history without touching either', function () {
    $scenario = uiCycle();
    $v1 = CycleFixture::currentBaseline($scenario['cycle']);

    $scenario['contract']->update(['sale_value' => '610000.00']);
    $v2 = CycleFixture::recalculate($scenario['cycle'], 'Correção do valor de venda.')->baseline;

    Livewire::test(SalesBoardCycleBaselinesRelationManager::class, [
        'ownerRecord' => $scenario['cycle']->fresh(),
        'pageClass' => ViewSalesBoardCycle::class,
    ])
        ->mountAction(TestAction::make('compare')->table($v1))
        ->setActionData(['other_baseline_id' => $v2->id])
        ->assertHasNoActionErrors();

    expect($v1->fresh()->snapshot_fingerprint)->not->toBe($v2->fresh()->snapshot_fingerprint)
        ->and($v1->fresh()->lines->firstWhere('contract_id', $scenario['contract']->id)->contract_sale_value)
        ->toBe('600000.00');
});

<?php

use App\Filament\Resources\SalesBoards\Pages\ViewSalesBoard;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeSalesBoardAdminUser());
});

/**
 * Um quadro com posição fechada: só estoque, para o total identificar a
 * competência na tela.
 */
function initialPositionBoard(Emission $emission, Construction $construction, string $referenceMonth, int $stockUnits): SalesBoard
{
    $salesBoard = new SalesBoard([
        'emission_id' => $emission->id,
        'construction_id' => $construction->id,
        'reference_month' => $referenceMonth,
        'stock_units' => $stockUnits,
        'financed_units' => 0,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => '100000.00',
        'financed_value' => '0.00',
        'paid_value' => '0.00',
        'exchanged_value' => '0.00',
    ]);
    $salesBoard->save();

    return $salesBoard;
}

/**
 * Uma emissão que já deixou "Em Elaboração" com o quadro de 07/2026
 * consolidado como início da operação.
 *
 * @return array{0: Emission, 1: Construction}
 */
function consolidatedEmissionWithInitialBoard(): array
{
    $emission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    initialPositionBoard($emission, $construction, '2026-07-01', 41);

    $emission->update(['status' => 'active']);

    return [$emission->refresh(), $construction];
}

it('shows the construction initial position on a board registered after the consolidation', function () {
    [$emission, $construction] = consolidatedEmissionWithInitialBoard();

    $later = initialPositionBoard($emission, $construction, '2026-09-01', 37);

    expect($later->hasInitialPosition())->toBeFalse();

    Livewire::test(ViewSalesBoard::class, ['record' => $later->getRouteKey()])
        ->assertSee('Início da Operação')
        ->assertSee('Posição inicial da operação, consolidada quando a emissão deixou o status "Em Elaboração". Imutável.')
        ->assertSee('41 unidades')
        ->assertSee('37 unidades')
        ->assertDontSee('Será consolidada quando a emissão deixar "Em Elaboração".')
        ->assertDontSee('Aguardando consolidação');
});

it('shows a single initial position for every competence recorded during elaboration', function () {
    $emission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    $june = initialPositionBoard($emission, $construction, '2026-06-01', 52);
    initialPositionBoard($emission, $construction, '2026-07-01', 41);

    $emission->update(['status' => 'active']);

    // A consolidação marca as duas competências; o início é a posição em vigor.
    expect($june->refresh()->hasInitialPosition())->toBeTrue();

    Livewire::test(ViewSalesBoard::class, ['record' => $june->getRouteKey()])
        ->assertSeeInOrder(['Início da Operação', '07/2026', '41 unidades', 'Posição Atual', '06/2026', '52 unidades']);
});

it('does not announce a future consolidation once the emission left elaboration', function () {
    [$emission] = consolidatedEmissionWithInitialBoard();

    $newcomer = Construction::factory()->create(['emission_id' => $emission->id]);
    $board = initialPositionBoard($emission, $newcomer, '2026-09-01', 12);

    Livewire::test(ViewSalesBoard::class, ['record' => $board->getRouteKey()])
        ->assertSee('Início da Operação')
        ->assertSee('Sem posição inicial consolidada para este empreendimento nesta operação.')
        ->assertDontSee('Será consolidada quando a emissão deixar "Em Elaboração".')
        ->assertDontSee('Aguardando consolidação')
        ->assertDontSee('consolidada quando a emissão deixou o status "Em Elaboração". Imutável.');
});

it('does not borrow the initial position the construction had in another emission', function () {
    [, $construction] = consolidatedEmissionWithInitialBoard();

    // O empreendimento passa para outra operação, onde nunca foi consolidado.
    $otherEmission = Emission::factory()->create(['status' => 'active']);
    Construction::query()->whereKey($construction->id)->update(['emission_id' => $otherEmission->id]);

    $board = initialPositionBoard($otherEmission, $construction->fresh(), '2026-09-01', 12);

    Livewire::test(ViewSalesBoard::class, ['record' => $board->getRouteKey()])
        ->assertSee('Sem posição inicial consolidada para este empreendimento nesta operação.')
        ->assertDontSee('41 unidades');
});

it('still announces the pending consolidation while the emission is in elaboration', function () {
    $emission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);
    $board = initialPositionBoard($emission, $construction, '2026-07-01', 41);

    Livewire::test(ViewSalesBoard::class, ['record' => $board->getRouteKey()])
        ->assertSee('Posição inicial da operação, consolidada quando a emissão deixar o status "Em Elaboração".')
        ->assertSee('Será consolidada quando a emissão deixar "Em Elaboração".')
        ->assertSee('Aguardando consolidação');
});

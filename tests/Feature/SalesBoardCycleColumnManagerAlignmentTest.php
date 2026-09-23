<?php

use App\Filament\Resources\SalesBoardCycles\Pages\ViewSalesBoardCycle;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBaselinesRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleBuilderReviewsRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleLinesRelationManager;
use App\Filament\Resources\SalesBoardCycles\RelationManagers\SalesBoardCycleMovementsRelationManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesBoards\BuilderReviewFixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(makeAdminUser());
});

/**
 * Versões e Validações não têm busca nem filtros: sem o campo de pesquisa para
 * empurrar o grupo Filtros/Colunas para a direita, o botão Colunas ancorava à
 * esquerda da toolbar. A classe compartilhada marca só essas duas tabelas para
 * o alinhamento à direita.
 */
it('marks the Versões and Validações tables for right-aligned toolbar content', function () {
    $scenario = BuilderReviewFixture::generatedCycle();

    $params = [
        'ownerRecord' => $scenario['cycle']->fresh(),
        'pageClass' => ViewSalesBoardCycle::class,
    ];

    Livewire::test(SalesBoardCycleBaselinesRelationManager::class, $params)
        ->assertSee('bsi-cycle-toolbar-end');

    Livewire::test(SalesBoardCycleBuilderReviewsRelationManager::class, $params)
        ->assertSee('bsi-cycle-toolbar-end')
        ->assertSee('Nenhuma validação aberta');

    Livewire::test(SalesBoardCycleLinesRelationManager::class, $params)
        ->assertDontSee('bsi-cycle-toolbar-end');

    Livewire::test(SalesBoardCycleMovementsRelationManager::class, $params)
        ->assertDontSee('bsi-cycle-toolbar-end');
});

it('aligns the marked toolbars to the right through the container only', function () {
    $cycleStyles = file_get_contents(resource_path('css/filament/admin/sales-board-cycle.css'));

    expect($cycleStyles)->toMatch('/\.bsi-sales-board-cycle-view-page \.bsi-cycle-toolbar-end \.fi-ta-header-toolbar > div:last-child \{\s*justify-content: flex-end;\s*\}/');
});

<?php

use App\Filament\Resources\SalesBoards\Pages\CreateSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\ListSalesBoards;
use App\Filament\Resources\SalesBoards\Pages\ViewSalesBoard;
use App\Filament\Resources\SalesBoards\RelationManagers\SalesBoardHistoriesRelationManager;
use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Filament\Resources\SalesBoards\Schemas\SalesBoardForm;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows the create and view actions with the expected filters on the list page', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();
    $salesBoard = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create();

    Livewire::test(ListSalesBoards::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Novo Quadro de Vendas')
        ->assertTableActionExists('view', null, $salesBoard)
        ->assertTableActionHasLabel('view', 'Visualizar')
        ->assertTableActionHasUrl('view', SalesBoardResource::getUrl('view', ['record' => $salesBoard]), $salesBoard)
        ->assertTableFilterExists('emission_id')
        ->assertTableFilterExists('construction_id')
        ->assertTableFilterExists('reference_month')
        ->assertTableFilterExists('stock_position');
});

it('renders each sales board form section on its own row', function () {
    $schema = SalesBoardForm::configure(Schema::make(new CreateSalesBoard));
    $sections = collect($schema->getComponents())
        ->filter(fn (mixed $component): bool => $component instanceof Section)
        ->mapWithKeys(fn (Section $section): array => [$section->getHeading() => $section]);

    expect($sections->keys()->all())->toBe([
        'Dados do Quadro de Vendas',
        'Quantidades por Status',
        'Valores por Status',
    ]);

    $allComponents = SalesBoardForm::configure(Schema::make(new CreateSalesBoard))->getComponents(withHidden: true);

    expect(collect($allComponents)->contains(fn (mixed $component): bool => $component instanceof Textarea
        && $component->getName() === 'change_reason'))->toBeTrue();

    expect($sections['Dados do Quadro de Vendas']->getColumnSpan())->toMatchArray([
        'default' => 'full',
    ]);

    expect($sections['Quantidades por Status']->getColumnSpan())->toMatchArray([
        'default' => 'full',
    ]);

    expect($sections['Valores por Status']->getColumnSpan())->toMatchArray([
        'default' => 'full',
    ]);
});

it('prefills the sales board numeric fields with zero on create', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();

    Livewire::test(CreateSalesBoard::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'reference_month' => '04/2026',
        ])
        ->assertFormSet([
            'stock_units' => 0,
            'financed_units' => 0,
            'paid_units' => 0,
            'exchanged_units' => 0,
            'total_units' => 0,
            'stock_value' => '0,00',
            'financed_value' => '0,00',
            'paid_value' => '0,00',
            'exchanged_value' => '0,00',
        ]);
});

it('creates a monthly sales board linked to emission and construction', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();

    Livewire::test(CreateSalesBoard::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'reference_month' => '04/2026',
            'stock_units' => 10,
            'financed_units' => 20,
            'paid_units' => 30,
            'exchanged_units' => 5,
            'stock_value' => '1.000.000,00',
            'financed_value' => '2.500.000,50',
            'paid_value' => '3.000.000,00',
            'exchanged_value' => '400.000,25',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $salesBoard = SalesBoard::query()->first();

    expect($salesBoard)->not->toBeNull()
        ->and($salesBoard?->emission_id)->toBe($emission->id)
        ->and($salesBoard?->construction_id)->toBe($construction->id)
        ->and($salesBoard?->reference_month?->toDateString())->toBe('2026-04-01')
        ->and($salesBoard?->stock_units)->toBe(10)
        ->and($salesBoard?->financed_units)->toBe(20)
        ->and($salesBoard?->paid_units)->toBe(30)
        ->and($salesBoard?->exchanged_units)->toBe(5)
        ->and($salesBoard?->total_units)->toBe(65)
        ->and($salesBoard?->stock_value)->toBe('1000000.00')
        ->and($salesBoard?->financed_value)->toBe('2500000.50')
        ->and($salesBoard?->paid_value)->toBe('3000000.00')
        ->and($salesBoard?->exchanged_value)->toBe('400000.25');
});

it('records a history version on every tracked sales board change', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    Carbon::setTestNow('2026-05-07 12:00:00');

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();
    $salesBoard = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-04-01',
        'stock_units' => 10,
        'financed_units' => 20,
        'paid_units' => 30,
        'exchanged_units' => 5,
        'stock_value' => 1000000,
        'financed_value' => 2500000.50,
        'paid_value' => 3000000,
        'exchanged_value' => 400000.25,
    ]);

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->fillForm([
            'stock_units' => 12,
            'financed_units' => 18,
            'paid_units' => 31,
            'exchanged_units' => 4,
            'stock_value' => '1.050.000,00',
            'financed_value' => '2.450.000,50',
            'paid_value' => '3.100.000,00',
            'exchanged_value' => '380.000,25',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $salesBoard->refresh();

    expect($salesBoard->stock_units)->toBe(12)
        ->and($salesBoard->financed_units)->toBe(18)
        ->and($salesBoard->paid_units)->toBe(31)
        ->and($salesBoard->exchanged_units)->toBe(4)
        ->and($salesBoard->total_units)->toBe(65)
        ->and($salesBoard->stock_value)->toBe('1050000.00')
        ->and($salesBoard->financed_value)->toBe('2450000.50')
        ->and($salesBoard->paid_value)->toBe('3100000.00')
        ->and($salesBoard->exchanged_value)->toBe('380000.25')
        ->and($salesBoard->valueHistories()->count())->toBe(2);

    $latestVersion = $salesBoard->valueHistories()->latest('id')->first();

    expect($latestVersion->stock_units)->toBe(12)
        ->and($latestVersion->total_units)->toBe(65)
        ->and($latestVersion->changedBy?->name)->not->toBeNull()
        ->and($latestVersion->change_reason)->toBeNull();
});

it('records the saved values as a new history version', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    Carbon::setTestNow('2026-05-07 12:00:00');

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();
    $salesBoard = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-04-01',
        'stock_units' => 10,
        'financed_units' => 20,
        'paid_units' => 30,
        'exchanged_units' => 5,
        'stock_value' => 1000000,
        'financed_value' => 2500000.50,
        'paid_value' => 3000000,
        'exchanged_value' => 400000.25,
    ]);

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->fillForm([
            'stock_units' => 12,
            'financed_units' => 18,
            'paid_units' => 31,
            'exchanged_units' => 4,
            'stock_value' => '1.050.000,00',
            'financed_value' => '2.450.000,50',
            'paid_value' => '3.100.000,00',
            'exchanged_value' => '380.000,25',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $salesBoard->refresh();
    $history = $salesBoard->valueHistories()->latest('id')->first();

    expect($salesBoard->stock_units)->toBe(12)
        ->and($salesBoard->financed_units)->toBe(18)
        ->and($salesBoard->paid_units)->toBe(31)
        ->and($salesBoard->exchanged_units)->toBe(4)
        ->and($salesBoard->total_units)->toBe(65)
        ->and($salesBoard->stock_value)->toBe('1050000.00')
        ->and($salesBoard->financed_value)->toBe('2450000.50')
        ->and($salesBoard->paid_value)->toBe('3100000.00')
        ->and($salesBoard->exchanged_value)->toBe('380000.25')
        ->and($history)->not->toBeNull()
        ->and($history?->reference_month?->toDateString())->toBe('2026-04-01')
        ->and($history?->stock_units)->toBe(12)
        ->and($history?->financed_units)->toBe(18)
        ->and($history?->paid_units)->toBe(31)
        ->and($history?->exchanged_units)->toBe(4)
        ->and($history?->total_units)->toBe(65)
        ->and($history?->stock_value)->toBe('1050000.00')
        ->and($history?->financed_value)->toBe('2450000.50')
        ->and($history?->paid_value)->toBe('3100000.00')
        ->and($history?->exchanged_value)->toBe('380000.25')
        ->and($history?->created_at?->toDateTimeString())->toBe('2026-05-07 12:00:00');
});

it('keeps monthly history without overwriting previous competencies', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-03-01',
        'stock_units' => 8,
        'financed_units' => 10,
        'paid_units' => 15,
        'exchanged_units' => 2,
        'stock_value' => 900000,
        'financed_value' => 1200000,
        'paid_value' => 1700000,
        'exchanged_value' => 250000,
    ]);

    Livewire::test(CreateSalesBoard::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'reference_month' => '04/2026',
            'stock_units' => 9,
            'financed_units' => 11,
            'paid_units' => 16,
            'exchanged_units' => 3,
            'stock_value' => '950.000,00',
            'financed_value' => '1.300.000,00',
            'paid_value' => '1.800.000,00',
            'exchanged_value' => '300.000,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SalesBoard::query()->where('emission_id', $emission->id)->where('construction_id', $construction->id)->count())
        ->toBe(2)
        ->and(SalesBoard::query()->whereDate('reference_month', '2026-03-01')->value('stock_units'))->toBe(8)
        ->and(SalesBoard::query()->whereDate('reference_month', '2026-04-01')->value('stock_units'))->toBe(9);
});

it('records a new version instead of duplicating a sales board for the same competency', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();

    SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-04-01',
    ]);

    Livewire::test(CreateSalesBoard::class)
        ->fillForm([
            'emission_id' => $emission->id,
            'construction_id' => $construction->id,
            'reference_month' => '04/2026',
            'stock_units' => 1,
            'financed_units' => 1,
            'paid_units' => 1,
            'exchanged_units' => 1,
            'stock_value' => '1.000,00',
            'financed_value' => '1.000,00',
            'paid_value' => '1.000,00',
            'exchanged_value' => '1.000,00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SalesBoard::query()->count())->toBe(1);

    $salesBoard = SalesBoard::query()->sole();

    expect($salesBoard->stock_units)->toBe(1)
        ->and($salesBoard->valueHistories()->count())->toBe(2)
        ->and($salesBoard->valueHistories()->orderBy('id')->pluck('stock_units')->last())->toBe(1);
});

it('requires a construction linked to the selected emission', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $selectedEmission = Emission::factory()->create();
    $otherEmission = Emission::factory()->create();
    $constructionFromOtherEmission = Construction::factory()->create([
        'emission_id' => $otherEmission->id,
    ]);

    Livewire::test(CreateSalesBoard::class)
        ->fillForm([
            'emission_id' => $selectedEmission->id,
            'construction_id' => $constructionFromOtherEmission->id,
            'reference_month' => '04/2026',
            'stock_units' => 1,
            'financed_units' => 1,
            'paid_units' => 1,
            'exchanged_units' => 1,
            'stock_value' => '1.000,00',
            'financed_value' => '1.000,00',
            'paid_value' => '1.000,00',
            'exchanged_value' => '1.000,00',
        ])
        ->call('create')
        ->assertHasFormErrors(['construction_id']);

    expect(SalesBoard::query()->count())->toBe(0);
});

it('requires the sales board mandatory fields', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    Livewire::test(CreateSalesBoard::class)
        ->call('create')
        ->assertHasFormErrors([
            'emission_id' => 'required',
            'construction_id' => 'required',
            'reference_month' => 'required',
        ]);
});

it('formats monthly competency and money fields when editing', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();
    $salesBoard = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-04-01',
        'stock_units' => 10,
        'financed_units' => 20,
        'paid_units' => 30,
        'exchanged_units' => 5,
        'stock_value' => 1000000,
        'financed_value' => 2500000.50,
        'paid_value' => 3000000,
        'exchanged_value' => 400000.25,
    ]);

    Livewire::withQueryParams(['from' => $salesBoard->getKey()])
        ->test(CreateSalesBoard::class)
        ->assertFormSet([
            'reference_month' => '04/2026',
            'total_units' => 65,
            'stock_value' => '1.000.000,00',
            'financed_value' => '2.500.000,50',
            'paid_value' => '3.000.000,00',
            'exchanged_value' => '400.000,25',
        ]);
});

it('shows the saved values on the read-only view page', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();
    $salesBoard = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-04-01',
        'stock_units' => 10,
        'financed_units' => 20,
        'paid_units' => 30,
        'exchanged_units' => 5,
        'stock_value' => 1000000,
        'financed_value' => 2500000.50,
        'paid_value' => 3000000,
        'exchanged_value' => 400000.25,
    ]);

    Livewire::test(ViewSalesBoard::class, [
        'record' => $salesBoard->getRouteKey(),
    ])
        ->assertSee('Dados da Operação')
        ->assertSee('Início da Operação')
        ->assertSee('Posição Atual')
        ->assertSee('04/2026')
        ->assertSee('R$ 1.000.000,00')
        ->assertSee('R$ 2.500.000,50')
        ->assertSee('R$ 3.000.000,00')
        ->assertSee('R$ 400.000,25');
});

it('offers a new update action instead of editing the current position', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    [$emission, $construction] = makeSalesBoardEmissionAndConstruction();
    $salesBoard = SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create();

    Livewire::test(ViewSalesBoard::class, ['record' => $salesBoard->getRouteKey()])
        ->assertActionExists('newUpdate')
        ->assertActionHasLabel('newUpdate', 'Nova Atualização')
        ->assertActionHasUrl('newUpdate', SalesBoardResource::getUrl('create', ['from' => $salesBoard->getKey()]))
        ->assertDontSee('Editar');

    Livewire::test(ListSalesBoards::class)
        ->assertTableActionExists('newUpdate', null, $salesBoard)
        ->assertTableActionDoesNotExist('edit', null, $salesBoard);
});

it('no longer registers an edit page for the sales board', function () {
    expect(array_keys(SalesBoardResource::getPages()))->toBe(['index', 'create', 'view'])
        ->and(file_exists(app_path('Filament/Resources/SalesBoards/Pages/EditSalesBoard.php')))->toBeFalse();
});

it('shows sales board history records on the relation manager', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = SalesBoard::factory()->create();
    $latestHistory = SalesBoardHistory::factory()->create([
        'sales_board_id' => $salesBoard->id,
        'reference_month' => '2026-04-01',
        'created_at' => '2026-05-07 12:00:00',
        'updated_at' => '2026-05-07 12:00:00',
    ]);
    $olderHistory = SalesBoardHistory::factory()->create([
        'sales_board_id' => $salesBoard->id,
        'reference_month' => '2026-03-01',
        'created_at' => '2026-04-07 12:00:00',
        'updated_at' => '2026-04-07 12:00:00',
    ]);

    Livewire::test(SalesBoardHistoriesRelationManager::class, [
        'ownerRecord' => $salesBoard,
        'pageClass' => ViewSalesBoard::class,
    ])
        ->assertCanSeeTableRecords([$latestHistory, $olderHistory], inOrder: true);
});

function makeSalesBoardEmissionAndConstruction(): array
{
    $emission = Emission::factory()->create([
        'name' => 'CRI Quadro de Vendas',
        'status' => Emission::STATUS_DRAFT,
    ]);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Sales',
    ]);

    return [$emission, $construction];
}

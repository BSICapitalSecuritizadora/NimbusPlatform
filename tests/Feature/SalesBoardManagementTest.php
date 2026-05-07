<?php

use App\Filament\Resources\SalesBoards\Pages\CreateSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\EditSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\ListSalesBoards;
use App\Filament\Resources\SalesBoards\RelationManagers\SalesBoardHistoriesRelationManager;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
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

it('shows the create sales board action and filters on the list page', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    Livewire::test(ListSalesBoards::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'Criar quadro de vendas')
        ->assertTableFilterExists('emission_id')
        ->assertTableFilterExists('construction_id')
        ->assertTableFilterExists('reference_month');
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

it('stores the previous values in history when tracked sales board fields are updated', function () {
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

    Livewire::test(EditSalesBoard::class, [
        'record' => $salesBoard->getRouteKey(),
    ])
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
        ->call('save')
        ->assertHasNoFormErrors();

    $salesBoard->refresh();
    $history = $salesBoard->valueHistories()->first();

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
        ->and($history?->stock_units)->toBe(10)
        ->and($history?->financed_units)->toBe(20)
        ->and($history?->paid_units)->toBe(30)
        ->and($history?->exchanged_units)->toBe(5)
        ->and($history?->total_units)->toBe(65)
        ->and($history?->stock_value)->toBe('1000000.00')
        ->and($history?->financed_value)->toBe('2500000.50')
        ->and($history?->paid_value)->toBe('3000000.00')
        ->and($history?->exchanged_value)->toBe('400000.25')
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

it('prevents duplicate sales board records for the same monthly competency', function () {
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
        ->assertHasFormErrors(['reference_month']);
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

    Livewire::test(EditSalesBoard::class, [
        'record' => $salesBoard->getRouteKey(),
    ])
        ->assertFormSet([
            'reference_month' => '04/2026',
            'total_units' => 65,
            'stock_value' => '1.000.000,00',
            'financed_value' => '2.500.000,50',
            'paid_value' => '3.000.000,00',
            'exchanged_value' => '400.000,25',
        ]);
});

it('requires confirmation before saving changes to a sales board', function () {
    $this->actingAs(makeSalesBoardAdminUser());

    $salesBoard = SalesBoard::factory()->create();

    $component = Livewire::test(EditSalesBoard::class, [
        'record' => $salesBoard->getRouteKey(),
    ])->instance();

    if (function_exists('invade')) {
        $saveAction = invade($component)->getSaveFormAction();
    } else {
        $method = new ReflectionMethod($component, 'getSaveFormAction');
        $method->setAccessible(true);
        $saveAction = $method->invoke($component);
    }

    expect($saveAction->isConfirmationRequired())->toBeTrue()
        ->and((string) $saveAction->getModalHeading())->toBe('Salvar alteracoes do quadro de vendas')
        ->and((string) $saveAction->getModalDescription())->toBe('Confirme para salvar as alteracoes. Quando houver mudanca nos valores, o historico anterior sera preservado automaticamente.')
        ->and($saveAction->getModalSubmitActionLabel())->toBe('Salvar alteracoes');
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
        'pageClass' => EditSalesBoard::class,
    ])
        ->assertCanSeeTableRecords([$latestHistory, $olderHistory], inOrder: true);
});

function makeSalesBoardEmissionAndConstruction(): array
{
    $emission = Emission::factory()->create([
        'name' => 'CRI Quadro de Vendas',
    ]);
    $construction = Construction::factory()->create([
        'emission_id' => $emission->id,
        'development_name' => 'Residencial Sales',
    ]);

    return [$emission, $construction];
}

function makeSalesBoardAdminUser(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'email' => fake()->unique()->safeEmail(),
    ]);
    $user->assignRole('admin');

    return $user;
}

<?php

use App\Filament\Resources\SalesBoards\Pages\CreateSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\EditSalesBoard;
use App\Filament\Resources\SalesBoards\Pages\ListSalesBoards;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
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
            'stock_units' => 'required',
            'financed_units' => 'required',
            'paid_units' => 'required',
            'exchanged_units' => 'required',
            'stock_value' => 'required',
            'financed_value' => 'required',
            'paid_value' => 'required',
            'exchanged_value' => 'required',
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

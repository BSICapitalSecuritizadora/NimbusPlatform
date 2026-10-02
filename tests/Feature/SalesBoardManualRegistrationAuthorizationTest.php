<?php

use App\Filament\Resources\SalesBoards\Pages\CreateSalesBoard;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * A página "Adicionar Quadro" cria uma competência nova ou versiona a que já
 * existe. A segunda coisa é editar a posição vigente: exige
 * `sales-boards.update`, e a competência existente é achada pela mesma chave do
 * leitor da posição -- empreendimento e mês --, nunca pela Emissão.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function manualRegistrationUser(array $permissions): User
{
    $role = Role::firstOrCreate(['name' => 'manual-registration-'.md5(implode(',', $permissions))]);
    $role->syncPermissions($permissions);

    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function manualRegistrationBoard(): SalesBoard
{
    $emission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
    $construction = Construction::factory()->create(['emission_id' => $emission->id]);

    return SalesBoard::factory()->forEmissionAndConstruction($emission, $construction)->create([
        'reference_month' => '2026-07-01',
        'stock_units' => 30,
        'financed_units' => 70,
        'paid_units' => 0,
        'exchanged_units' => 0,
    ]);
}

/**
 * @return array<string, mixed>
 */
function manualRegistrationForm(SalesBoard $board, int $stockUnits): array
{
    return [
        'emission_id' => $board->construction->emission_id,
        'construction_id' => $board->construction_id,
        'reference_month' => '07/2026',
        'stock_units' => $stockUnits,
        'financed_units' => 70,
        'paid_units' => 0,
        'exchanged_units' => 0,
        'stock_value' => '1.000.000,00',
        'financed_value' => '2.000.000,00',
        'paid_value' => '0,00',
        'exchanged_value' => '0,00',
    ];
}

it('refuses to rewrite a registered competence without sales-boards.update', function () {
    $board = manualRegistrationBoard();

    $this->actingAs(manualRegistrationUser(['emissions.view', 'sales-boards.view', 'sales-boards.create']));

    Livewire::test(CreateSalesBoard::class)
        ->fillForm(manualRegistrationForm($board, 5))
        ->call('create')
        ->assertNotified('Registro recusado');

    Livewire::withQueryParams(['from' => $board->getKey()])
        ->test(CreateSalesBoard::class)
        ->fillForm(['stock_units' => 5])
        ->call('create')
        ->assertNotified('Registro recusado');

    expect($board->fresh()->stock_units)->toBe(30)
        ->and(SalesBoard::query()->count())->toBe(1)
        ->and(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe(1);
});

it('lets the create permission alone register a new competence', function () {
    $board = manualRegistrationBoard();

    $this->actingAs(manualRegistrationUser(['emissions.view', 'sales-boards.view', 'sales-boards.create']));

    Livewire::test(CreateSalesBoard::class)
        ->fillForm([...manualRegistrationForm($board, 5), 'reference_month' => '08/2026'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SalesBoard::query()->where('construction_id', $board->construction_id)->count())->toBe(2)
        ->and($board->fresh()->stock_units)->toBe(30);
});

it('versions a registered competence for whoever may edit it', function () {
    $board = manualRegistrationBoard();

    $this->actingAs(manualRegistrationUser(['emissions.view', 'sales-boards.view', 'sales-boards.create', 'sales-boards.update']));

    Livewire::test(CreateSalesBoard::class)
        ->fillForm(manualRegistrationForm($board, 5))
        ->call('create')
        ->assertHasNoFormErrors();

    expect($board->fresh()->stock_units)->toBe(5)
        ->and(SalesBoard::query()->count())->toBe(1)
        ->and(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe(2);
});

it('finds the registered competence by construction and month, not by emission', function () {
    $board = manualRegistrationBoard();

    /**
     * Um quadro que ficou gravado sob a Emissão antiga de um empreendimento
     * movido antes da guarda de troca de Emissão existir -- a gravação vai por
     * baixo dos eventos, como teria acontecido.
     */
    $currentEmission = Emission::factory()->create(['status' => Emission::STATUS_DRAFT]);
    DB::table('constructions')->where('id', $board->construction_id)->update(['emission_id' => $currentEmission->id]);

    $this->actingAs(manualRegistrationUser(['emissions.view', 'sales-boards.view', 'sales-boards.create', 'sales-boards.update']));

    Livewire::test(CreateSalesBoard::class)
        ->fillForm([...manualRegistrationForm($board->fresh(), 5), 'emission_id' => $currentEmission->id])
        ->call('create')
        ->assertNotified('Registro recusado');

    expect(SalesBoard::query()->where('construction_id', $board->construction_id)->count())->toBe(1)
        ->and($board->fresh()->stock_units)->toBe(30)
        ->and($board->fresh()->emission_id)->not->toBe($currentEmission->id);
});

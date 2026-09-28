<?php

use App\Filament\Resources\Constructions\ConstructionResource;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\Constructions\Pages\ListConstructions;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\EditConstructionUnit;
use App\Filament\Resources\ConstructionUnits\Pages\ListConstructionUnits;
use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Filament\Resources\ContractInstallments\Pages\EditContractInstallment;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Filament\Resources\Emissions\Pages\EditEmission;
use App\Filament\Resources\Emissions\Pages\ListEmissions;
use App\Filament\Resources\SalesBoards\Pages\ListSalesBoards;
use App\Filament\Resources\SalesBoards\SalesBoardResource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * A exclusão e a edição do Quadro de Vendas e das fontes dele passam pela
 * policy do model. No Filament 5 a DeleteBulkAction, a DeleteAction da página e
 * o acesso à edição nunca consultam os `can*()` do resource: sem policy, eram
 * liberados para qualquer um que abrisse a lista.
 *
 * Cada ação é exercida com quatro perfis -- super-admin, admin, editor e um papel
 * customizado só de visualização -- e o que se confere é o dado, não só o botão.
 */
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function sourceAuthorizationUser(string $profile): User
{
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);

    if ($profile !== 'viewer') {
        $user->assignRole($profile);

        return $user;
    }

    $role = Role::firstOrCreate(['name' => 'source-viewer']);
    $role->syncPermissions([
        'sales-boards.view',
        'contracts.view',
        'contract-installments.view',
        'constructions.view',
        'emissions.view',
    ]);

    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function sourceAuthorizationConstruction(): Construction
{
    return Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['type' => 'CRI', 'status' => 'active'])->id,
    ]);
}

function sourceAuthorizationContract(?Construction $construction = null): Contract
{
    $unit = ConstructionUnit::factory()->forConstruction($construction ?? sourceAuthorizationConstruction())->create();

    return Contract::factory()->forUnit($unit)->create();
}

/**
 * Congela a unidade -- e o contrato, se houver -- numa linha de ciclo, como a
 * geração da competência faz.
 */
function sourceAuthorizationFreeze(ConstructionUnit $unit, ?Contract $contract = null): SalesBoardCycleLine
{
    $cycle = SalesBoardCycle::factory()->forConstruction($unit->construction)->create();

    return SalesBoardCycleLine::factory()->create([
        'sales_board_cycle_baseline_id' => SalesBoardCycleBaseline::factory()->create(['sales_board_cycle_id' => $cycle->id])->id,
        'construction_unit_id' => $unit->id,
        'contract_id' => $contract?->id,
        'block' => $unit->block,
        'unit' => $unit->unit,
    ]);
}

function sourceAuthorizationLegacyBoard(Construction $construction): SalesBoard
{
    return SalesBoard::factory()
        ->forEmissionAndConstruction($construction->emission, $construction)
        ->create(['reference_month' => '2026-06-01']);
}

/**
 * Executa a exclusão em massa como uma requisição forjada: a ação oculta é
 * montada à mão no estado do componente, como faria quem manipula o payload do
 * Livewire. Quem não tem a permissão não passa nem assim.
 *
 * @param  list<Model>  $records
 */
function sourceAuthorizationForgeBulkDelete(Testable $page, array $records): void
{
    $page->selectTableRecords($records)
        ->set('mountedActions', [['name' => 'delete', 'arguments' => [], 'context' => ['bulk' => true, 'table' => true]]])
        ->call('callMountedAction');
}

/**
 * O mesmo, para a DeleteAction do cabeçalho de uma página.
 */
function sourceAuthorizationForgeDelete(Testable $page): void
{
    $page->set('mountedActions', [['name' => 'delete', 'arguments' => [], 'context' => []]])
        ->call('callMountedAction');
}

/**
 * Tenta a exclusão em massa pelo caminho que o perfil tem: o botão, para quem o
 * vê; a requisição forjada, para quem não o vê.
 *
 * @param  list<Model>  $records
 */
function sourceAuthorizationBulkDelete(Testable $page, array $records, bool $allowed): void
{
    if ($allowed) {
        $page->assertTableBulkActionVisible('delete')
            ->callTableBulkAction('delete', $records);

        return;
    }

    $page->assertTableBulkActionHidden('delete');

    sourceAuthorizationForgeBulkDelete($page, $records);
}

/**
 * O mesmo para a DeleteAction do cabeçalho.
 */
function sourceAuthorizationPageDelete(Testable $page, bool $allowed): void
{
    if ($allowed) {
        $page->assertActionVisible(DeleteAction::class)
            ->callAction(DeleteAction::class);

        return;
    }

    $page->assertActionHidden(DeleteAction::class);

    sourceAuthorizationForgeDelete($page);
}

dataset('source authorization profiles', [
    'super-admin' => ['super-admin', true],
    'admin' => ['admin', true],
    'editor' => ['editor', false],
    'somente visualização' => ['viewer', false],
]);

it('offers no deletion of sales boards to any profile', function (string $profile, bool $holdsDeletePermission) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $board = sourceAuthorizationLegacyBoard(sourceAuthorizationConstruction());

    Livewire::test(ListSalesBoards::class)
        ->assertCanSeeTableRecords([$board])
        ->assertTableBulkActionDoesNotExist('delete');

    expect(SalesBoardResource::canDeleteAny())->toBe($holdsDeletePermission)
        ->and(SalesBoard::query()->whereKey($board->id)->exists())->toBeTrue()
        ->and(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe(1);
})->with('source authorization profiles');

it('authorizes the contract bulk deletion by contracts.delete', function (string $profile, bool $allowed) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $contract = sourceAuthorizationContract();

    sourceAuthorizationBulkDelete(Livewire::test(ListContracts::class), [$contract], $allowed);

    expect($contract->fresh()->trashed())->toBe($allowed);
})->with('source authorization profiles');

it('keeps a frozen contract out of the bulk deletion, for the super-admin too', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $construction = sourceAuthorizationConstruction();
    $frozen = sourceAuthorizationContract($construction);
    $free = sourceAuthorizationContract($construction);

    sourceAuthorizationFreeze($frozen->constructionUnit, $frozen);

    Livewire::test(ListContracts::class)
        ->assertTableActionHidden('delete', $frozen)
        ->assertTableActionVisible('delete', $free)
        ->callTableBulkAction('delete', [$frozen, $free]);

    expect($frozen->fresh()->trashed())->toBeFalse()
        ->and($free->fresh()->trashed())->toBeTrue();
})->with(['super-admin', 'admin']);

it('authorizes the contract edit page and its deletion by the contract permissions', function (string $profile, bool $allowed) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $contract = sourceAuthorizationContract();

    if ($profile === 'viewer') {
        Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])->assertForbidden();

        expect(ContractResource::canEdit($contract))->toBeFalse();

        return;
    }

    sourceAuthorizationPageDelete(Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()]), $allowed);

    expect($contract->fresh()->trashed())->toBe($allowed);
})->with('source authorization profiles');

it('explains on the edit page why a frozen contract cannot be deleted', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $contract = sourceAuthorizationContract();
    sourceAuthorizationFreeze($contract->constructionUnit, $contract);

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible(DeleteAction::class)
        ->assertActionDisabled(DeleteAction::class)
        ->callAction(DeleteAction::class);

    expect($contract->fresh()->trashed())->toBeFalse()
        ->and(ContractResource::getDeleteAuthorizationResponse($contract)->message())
        ->toContain('posição congelada')
        ->toContain('Data do Distrato');
})->with(['super-admin', 'admin']);

it('no longer suggests deleting a contract to register a distrato', function () {
    $this->actingAs(sourceAuthorizationUser('admin'));

    $contract = sourceAuthorizationContract();

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->mountAction(DeleteAction::class)
        ->assertMountedActionModalSee(['Use a exclusão apenas para um contrato registrado por engano.', 'Data do Distrato'])
        ->assertMountedActionModalDontSee('libera a unidade');

    Livewire::test(ListContracts::class)
        ->mountAction(TestAction::make('delete')->table($contract))
        ->assertMountedActionModalSee('Use a exclusão apenas para um contrato registrado por engano.')
        ->assertMountedActionModalDontSee('libera a unidade');

    Livewire::test(ListContracts::class)
        ->mountTableBulkAction('delete', [$contract])
        ->assertMountedActionModalSee('Use a exclusão apenas para um contrato registrado por engano.');
});

it('authorizes the installment bulk deletion by contract-installments.delete', function (string $profile, bool $allowed) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $installment = ContractInstallment::factory()->forContract(sourceAuthorizationContract())->create();

    sourceAuthorizationBulkDelete(Livewire::test(ListContractInstallments::class), [$installment], $allowed);

    expect($installment->fresh()->trashed())->toBe($allowed);
})->with('source authorization profiles');

it('keeps the installments of a frozen contract out of every deletion', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $contract = sourceAuthorizationContract();
    sourceAuthorizationFreeze($contract->constructionUnit, $contract);

    $installment = ContractInstallment::factory()->forContract($contract)->create();
    $free = ContractInstallment::factory()->forContract(sourceAuthorizationContract())->create();

    Livewire::test(ListContractInstallments::class)
        ->callTableBulkAction('delete', [$installment, $free]);

    Livewire::test(EditContractInstallment::class, ['record' => $installment->getRouteKey()])
        ->assertActionDisabled(DeleteAction::class)
        ->callAction(DeleteAction::class);

    expect($installment->fresh()->trashed())->toBeFalse()
        ->and($free->fresh()->trashed())->toBeTrue()
        ->and(ContractInstallmentResource::getDeleteAuthorizationResponse($installment)->message())
        ->toContain('data de cancelamento');
})->with(['super-admin', 'admin']);

it('authorizes the unit bulk deletion by constructions.delete', function (string $profile, bool $allowed) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $unit = ConstructionUnit::factory()->forConstruction(sourceAuthorizationConstruction())->create();

    sourceAuthorizationBulkDelete(Livewire::test(ListConstructionUnits::class), [$unit], $allowed);

    expect(ConstructionUnit::query()->whereKey($unit->id)->exists())->toBe(! $allowed);
})->with('source authorization profiles');

it('authorizes the unit edit page deletion by constructions.delete', function (string $profile, bool $allowed) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $unit = ConstructionUnit::factory()->forConstruction(sourceAuthorizationConstruction())->create();

    if ($profile === 'viewer') {
        Livewire::test(EditConstructionUnit::class, ['record' => $unit->getRouteKey()])->assertForbidden();

        return;
    }

    sourceAuthorizationPageDelete(Livewire::test(EditConstructionUnit::class, ['record' => $unit->getRouteKey()]), $allowed);

    expect(ConstructionUnit::query()->whereKey($unit->id)->exists())->toBe(! $allowed);
})->with('source authorization profiles');

it('refuses to delete a frozen unit with a reason instead of a constraint error', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $construction = sourceAuthorizationConstruction();
    $frozen = ConstructionUnit::factory()->forConstruction($construction)->create();
    $free = ConstructionUnit::factory()->forConstruction($construction)->create();

    sourceAuthorizationFreeze($frozen);

    Livewire::test(EditConstructionUnit::class, ['record' => $frozen->getRouteKey()])
        ->assertActionDisabled(DeleteAction::class)
        ->callAction(DeleteAction::class)
        ->assertSuccessful();

    Livewire::test(ListConstructionUnits::class)
        ->assertTableActionHidden('delete', $frozen)
        ->callTableBulkAction('delete', [$frozen, $free])
        ->assertSuccessful();

    expect(ConstructionUnit::query()->whereKey($frozen->id)->exists())->toBeTrue()
        ->and(ConstructionUnit::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(ConstructionUnitResource::getDeleteAuthorizationResponse($frozen)->message())
        ->toBe('A unidade não pode ser excluída: já compõe a posição congelada de um ciclo do Quadro de Vendas.');
})->with(['super-admin', 'admin']);

it('authorizes the construction bulk deletion by emissions.delete', function (string $profile, bool $allowed) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $construction = sourceAuthorizationConstruction();

    sourceAuthorizationBulkDelete(Livewire::test(ListConstructions::class), [$construction], $allowed);

    expect(Construction::query()->whereKey($construction->id)->exists())->toBe(! $allowed);
})->with('source authorization profiles');

it('authorizes the construction edit page deletion by emissions.delete', function (string $profile, bool $allowed) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $construction = sourceAuthorizationConstruction();

    if ($profile === 'viewer') {
        Livewire::test(EditConstruction::class, ['record' => $construction->getRouteKey()])->assertForbidden();

        return;
    }

    sourceAuthorizationPageDelete(Livewire::test(EditConstruction::class, ['record' => $construction->getRouteKey()]), $allowed);

    expect(Construction::query()->whereKey($construction->id)->exists())->toBe(! $allowed);
})->with('source authorization profiles');

it('keeps a construction with a legacy sales board, and its history, out of every deletion', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $construction = sourceAuthorizationConstruction();
    $board = sourceAuthorizationLegacyBoard($construction);
    $free = sourceAuthorizationConstruction();

    Livewire::test(EditConstruction::class, ['record' => $construction->getRouteKey()])
        ->assertActionDisabled(DeleteAction::class)
        ->callAction(DeleteAction::class);

    Livewire::test(ListConstructions::class)
        ->callTableBulkAction('delete', [$construction, $free]);

    expect(Construction::query()->whereKey($construction->id)->exists())->toBeTrue()
        ->and(SalesBoard::query()->whereKey($board->id)->exists())->toBeTrue()
        ->and(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe(1)
        ->and(Construction::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(ConstructionResource::getDeleteAuthorizationResponse($construction)->message())
        ->toBe('A obra não pode ser excluída: tem Quadro de Vendas registrado.');
})->with(['super-admin', 'admin']);

it('refuses to delete a construction with a cycle instead of failing on the constraint', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $construction = sourceAuthorizationConstruction();
    SalesBoardCycle::factory()->forConstruction($construction)->create();

    Livewire::test(EditConstruction::class, ['record' => $construction->getRouteKey()])
        ->assertActionDisabled(DeleteAction::class)
        ->callAction(DeleteAction::class)
        ->assertSuccessful();

    expect(Construction::query()->whereKey($construction->id)->exists())->toBeTrue();
})->with(['super-admin', 'admin']);

it('lets only emissions.update open the emission edit page', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    $page = Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()]);

    if ($profile === 'viewer') {
        $page->assertForbidden();
    } else {
        $page->assertSuccessful();
    }

    expect(EmissionResource::canEdit($emission))->toBe($profile !== 'viewer');
})->with(['super-admin', 'admin', 'editor', 'viewer']);

it('authorizes the emission deletion by emissions.delete', function (string $profile, bool $allowed) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    if ($profile === 'viewer') {
        expect(EmissionResource::canDelete($emission))->toBeFalse();

        return;
    }

    sourceAuthorizationPageDelete(Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()]), $allowed);

    expect(Emission::query()->whereKey($emission->id)->exists())->toBe(! $allowed);
})->with('source authorization profiles');

it('keeps an emission with sales boards out of the deletion, for the super-admin too', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $construction = sourceAuthorizationConstruction();
    $board = sourceAuthorizationLegacyBoard($construction);
    $emission = $construction->emission;

    sourceAuthorizationPageDelete(Livewire::test(EditEmission::class, ['record' => $emission->getRouteKey()]), false);

    expect(Emission::query()->whereKey($emission->id)->exists())->toBeTrue()
        ->and(Construction::query()->whereKey($construction->id)->exists())->toBeTrue()
        ->and(SalesBoard::query()->whereKey($board->id)->exists())->toBeTrue()
        ->and(EmissionResource::getDeleteAuthorizationResponse($emission)->message())
        ->toBe('A emissão não pode ser excluída: tem Quadro de Vendas registrado.');
})->with(['super-admin', 'admin']);

it('sends whoever cannot edit the emission to its dossier instead of a forbidden page', function () {
    $emission = Emission::factory()->create();
    $editUrl = EmissionResource::getUrl('edit', ['record' => $emission]);
    $viewUrl = EmissionResource::getUrl('view', ['record' => $emission]);

    expect($editUrl)->toEndWith('/edit');

    $this->actingAs(sourceAuthorizationUser('viewer'));

    expect(EmissionResource::getUrl('edit', ['record' => $emission]))->toBe($viewUrl);

    $this->get(EmissionResource::getUrl('edit', ['record' => $emission]))->assertSuccessful();
    $this->get($editUrl)->assertForbidden();

    $this->actingAs(sourceAuthorizationUser('editor'));

    expect(EmissionResource::getUrl('edit', ['record' => $emission]))->toBe($editUrl);
});

it('shows the emission deletion disabled, with the reason, instead of hiding it', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $construction = sourceAuthorizationConstruction();
    sourceAuthorizationLegacyBoard($construction);
    $emission = $construction->emission;

    $page = Livewire::test(ListEmissions::class)
        ->assertTableActionVisible('delete', $emission)
        ->assertTableActionDisabled('delete', $emission);

    $action = $page->instance()->getTable()->getAction('delete')->record($emission);

    expect($action->getTooltip())->toBe('A emissão não pode ser excluída: tem Quadro de Vendas registrado.');

    $page->callTableAction('delete', $emission);

    expect(Emission::query()->whereKey($emission->id)->exists())->toBeTrue();
})->with(['super-admin', 'admin']);

it('keeps the emission deletion out of sight for whoever lacks emissions.delete', function (string $profile) {
    $this->actingAs(sourceAuthorizationUser($profile));

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    Livewire::test(ListEmissions::class)
        ->assertTableActionHidden('delete', $emission);

    expect(Emission::query()->whereKey($emission->id)->exists())->toBeTrue();
})->with(['editor', 'viewer']);

/**
 * A resposta guardada na renderização não pode decidir o clique seguinte: se a
 * Emissão ganhou história entre uma requisição e outra, a exclusão é recusada.
 */
it('asks the emission policy again on the request that runs the deletion', function () {
    $this->actingAs(sourceAuthorizationUser('admin'));

    $construction = sourceAuthorizationConstruction();
    $emission = $construction->emission;

    $page = Livewire::test(ListEmissions::class)
        ->assertTableActionEnabled('delete', $emission);

    sourceAuthorizationLegacyBoard($construction);

    $page->callTableAction('delete', $emission);

    expect(Emission::query()->whereKey($emission->id)->exists())->toBeTrue();
});

/**
 * Conta as consultas que tocam uma tabela durante a renderização da lista.
 */
function sourceAuthorizationCountProbeQueries(string $table, Closure $render): int
{
    $count = 0;

    DB::listen(function (QueryExecuted $query) use ($table, &$count): void {
        if (str_contains($query->sql, $table)) {
            $count++;
        }
    });

    $render();

    return $count;
}

/**
 * As guardas de integridade consultam o banco a cada linha da lista. O Filament
 * pergunta a autorização de uma ação de linha mais de uma vez -- visibilidade,
 * estado desabilitado, tooltip --; a guarda roda no máximo uma vez por linha.
 */
it('runs the source guards at most once per listed row', function (string $page, string $probeTable, Closure $makeRows, bool $onlyTrashed = false) {
    $this->actingAs(sourceAuthorizationUser('admin'));

    $rows = $makeRows();

    $count = sourceAuthorizationCountProbeQueries($probeTable, function () use ($page, $rows, $onlyTrashed): void {
        $list = Livewire::test($page);

        if ($onlyTrashed) {
            $list->filterTable('trashed', false);
        }

        $list->assertCanSeeTableRecords($rows);
    });

    expect($count)->toBeGreaterThan(0)->toBeLessThanOrEqual(count($rows));
})->with([
    'contratos' => [ListContracts::class, 'sales_board_cycle_movements', fn (): array => [
        sourceAuthorizationContract(),
        sourceAuthorizationContract(),
        sourceAuthorizationContract(),
    ]],
    'contratos excluídos' => [ListContracts::class, 'sales_board_cycle_movements', function (): array {
        $contracts = [sourceAuthorizationContract(), sourceAuthorizationContract(), sourceAuthorizationContract()];

        foreach ($contracts as $contract) {
            $contract->delete();
        }

        return $contracts;
    }, true],
    'parcelas' => [ListContractInstallments::class, 'sales_board_cycle_movements', function (): array {
        $contract = sourceAuthorizationContract();

        return ContractInstallment::factory()->forContract($contract)->count(3)->create()->all();
    }],
    'unidades' => [ListConstructionUnits::class, 'construction_unit_exchanges', fn (): array => ConstructionUnit::factory()
        ->forConstruction(sourceAuthorizationConstruction())
        ->count(3)
        ->create()
        ->all()],
    'emissões' => [ListEmissions::class, 'sales_board_rollout_recipients', fn (): array => Emission::factory()
        ->count(3)
        ->create(['type' => 'CRI', 'status' => 'active'])
        ->all()],
]);

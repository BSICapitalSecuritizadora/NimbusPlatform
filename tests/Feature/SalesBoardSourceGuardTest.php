<?php

use App\Exceptions\SalesBoardSourceException;
use App\Filament\Resources\Constructions\ConstructionResource;
use App\Filament\Resources\Constructions\Pages\EditConstruction;
use App\Filament\Resources\ConstructionUnits\ConstructionUnitResource;
use App\Filament\Resources\ConstructionUnits\Pages\EditConstructionUnit;
use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Emissions\EmissionResource;
use App\Models\Construction;
use App\Models\ConstructionUnit;
use App\Models\ConstructionUnitExchange;
use App\Models\ConstructionUnitRetirement;
use App\Models\ConstructionUnitValue;
use App\Models\Contract;
use App\Models\ContractInstallment;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\SalesBoard;
use App\Models\SalesBoardAutomationTarget;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleLine;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardHistory;
use App\Models\SalesBoardRolloutHomologationConstruction;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardSourceGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * As guardas das fontes do Quadro de Vendas: o que já foi lido por um quadro
 * ou por um ciclo não é apagado, movido nem restaurado por fora.
 *
 * Parte do grupo `parity`: a guarda responde tudo numa única consulta de
 * subconsultas `exists` sem `from`, e o MySQL precisa concordar com o SQLite.
 */
pest()->group('parity');

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function sourceGuardUser(string $role = 'admin'): User
{
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->assignRole($role);

    return $user;
}

function sourceGuardConstruction(): Construction
{
    return Construction::factory()->create([
        'emission_id' => Emission::factory()->create(['type' => 'CRI', 'status' => 'active'])->id,
    ]);
}

function sourceGuardUnit(?Construction $construction = null): ConstructionUnit
{
    return ConstructionUnit::factory()->forConstruction($construction ?? sourceGuardConstruction())->create();
}

function sourceGuardBaseline(Construction $construction): SalesBoardCycleBaseline
{
    $cycle = SalesBoardCycle::factory()->forConstruction($construction)->create();

    return SalesBoardCycleBaseline::factory()->create(['sales_board_cycle_id' => $cycle->id]);
}

dataset('unit anchors', [
    'contrato excluído' => [
        fn (ConstructionUnit $unit) => Contract::factory()->forUnit($unit)->create()->delete(),
        'tem contrato registrado, inclusive excluído',
    ],
    'histórico de valores' => [
        fn (ConstructionUnit $unit) => ConstructionUnitValue::factory()->forUnit($unit)->create(),
        'tem histórico de valores',
    ],
    'permuta' => [
        fn (ConstructionUnit $unit) => ConstructionUnitExchange::factory()->forUnit($unit)->create(),
        'tem permuta registrada',
    ],
    'baixa registrada' => [
        fn (ConstructionUnit $unit) => ConstructionUnitRetirement::factory()->forUnit($unit)->create(),
        'tem baixa registrada',
    ],
    'linha congelada' => [
        fn (ConstructionUnit $unit) => SalesBoardCycleLine::factory()->create([
            'sales_board_cycle_baseline_id' => sourceGuardBaseline($unit->construction)->id,
            'construction_unit_id' => $unit->id,
        ]),
        'já compõe a posição congelada de um ciclo do Quadro de Vendas',
    ],
    'movimento congelado' => [
        fn (ConstructionUnit $unit) => SalesBoardCycleMovement::factory()->create([
            'sales_board_cycle_baseline_id' => sourceGuardBaseline($unit->construction)->id,
            'construction_unit_id' => $unit->id,
            'contract_id' => Contract::factory()->forUnit($unit)->create()->id,
        ]),
        'já compõe a posição congelada de um ciclo do Quadro de Vendas',
    ],
]);

it('refuses to move an anchored unit to another construction', function (Closure $anchor, string $reason) {
    $this->actingAs(sourceGuardUser());

    $unit = sourceGuardUnit();
    $anchor($unit);
    $target = Construction::factory()->create(['emission_id' => $unit->construction->emission_id]);

    Livewire::test(EditConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertFormFieldDisabled('construction_id')
        ->assertFormFieldDisabled('emission_id')
        ->assertSee($reason)
        ->fillForm(['construction_id' => $target->id])
        ->call('save');

    expect($unit->fresh()->construction_id)->toBe($unit->construction_id)
        ->and(fn () => $unit->fresh()->update(['construction_id' => $target->id]))
        ->toThrow(SalesBoardSourceException::class, $reason)
        ->and(ConstructionUnitResource::canDelete($unit->fresh()))->toBeFalse();
})->with('unit anchors');

it('still moves a unit that has no history yet', function () {
    $this->actingAs(sourceGuardUser());

    $unit = sourceGuardUnit();
    $target = Construction::factory()->create(['emission_id' => $unit->construction->emission_id]);

    Livewire::test(EditConstructionUnit::class, ['record' => $unit->getRouteKey()])
        ->assertFormFieldEnabled('construction_id')
        ->fillForm(['construction_id' => $target->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($unit->fresh()->construction_id)->toBe($target->id);
});

dataset('construction emission anchors', [
    'quadro legado' => [
        fn (Construction $construction) => SalesBoard::factory()
            ->forEmissionAndConstruction($construction->emission, $construction)
            ->create(['reference_month' => '2026-06-01']),
        'tem Quadro de Vendas registrado',
    ],
    'ciclo' => [
        fn (Construction $construction) => SalesBoardCycle::factory()->forConstruction($construction)->create(),
        'tem ciclo do Quadro de Vendas',
    ],
    'alvo da automação' => [
        fn (Construction $construction) => SalesBoardAutomationTarget::factory()->create(['construction_id' => $construction->id]),
        'está na automação do Quadro de Vendas',
    ],
    'linha de homologação' => [
        fn (Construction $construction) => SalesBoardRolloutHomologationConstruction::factory()->create(['construction_id' => $construction->id]),
        'está numa homologação do rollout do Quadro de Vendas',
    ],
]);

it('refuses to move an anchored construction to another emission', function (Closure $anchor, string $reason) {
    $this->actingAs(sourceGuardUser());

    $construction = sourceGuardConstruction();
    $anchor($construction);
    $originalEmissionId = $construction->emission_id;
    $otherEmission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);

    Livewire::test(EditConstruction::class, ['record' => $construction->getRouteKey()])
        ->assertFormFieldDisabled('emission_id')
        ->assertSee($reason)
        ->fillForm(['emission_id' => $otherEmission->id])
        ->call('save');

    expect($construction->fresh()->emission_id)->toBe($originalEmissionId)
        ->and(fn () => $construction->fresh()->update(['emission_id' => $otherEmission->id]))
        ->toThrow(SalesBoardSourceException::class, 'A obra não pode trocar de emissão: '.$reason.'.')
        ->and(ConstructionResource::getDeleteAuthorizationResponse($construction->fresh())->message())
        ->toContain($reason);
})->with('construction emission anchors');

it('still moves a construction that has nothing of the sales board yet', function () {
    $construction = sourceGuardConstruction();
    $otherEmission = Emission::factory()->create();

    $construction->update(['emission_id' => $otherEmission->id]);

    expect($construction->fresh()->emission_id)->toBe($otherEmission->id);
});

it('refuses to delete a construction with sales boards from any code path, keeping the history', function () {
    $construction = sourceGuardConstruction();
    $board = SalesBoard::factory()
        ->forEmissionAndConstruction($construction->emission, $construction)
        ->create(['reference_month' => '2026-06-01']);

    expect(fn () => $construction->delete())
        ->toThrow(SalesBoardSourceException::class, 'A obra não pode ser excluída: tem Quadro de Vendas registrado.')
        ->and(SalesBoard::query()->whereKey($board->id)->exists())->toBeTrue()
        ->and(SalesBoardHistory::query()->where('sales_board_id', $board->id)->count())->toBe(1);
});

it('lists every blocker of a construction deletion, the units included', function () {
    $this->actingAs(sourceGuardUser('super-admin'));

    $construction = sourceGuardConstruction();
    $unit = sourceGuardUnit($construction);

    Contract::factory()->forUnit($unit)->create();
    ConstructionUnitValue::factory()->forUnit($unit)->create();

    expect(app(SalesBoardSourceGuard::class)->constructionDeletionBlockers($construction))->toBe([
        'tem contrato registrado, inclusive excluído',
        'tem unidade que tem histórico de valores',
    ])
        ->and(ConstructionResource::canDelete($construction))->toBeFalse();
});

it('lists a retired unit among the blockers of a construction deletion', function () {
    $this->actingAs(sourceGuardUser('super-admin'));

    $construction = sourceGuardConstruction();
    ConstructionUnitRetirement::factory()->forUnit(sourceGuardUnit($construction))->create();

    expect(app(SalesBoardSourceGuard::class)->constructionDeletionBlockers($construction))->toBe([
        'tem unidade que tem baixa registrada',
    ])
        ->and(ConstructionResource::canDelete($construction))->toBeFalse();
});

it('refuses to delete an emission whose cascade would reach protected history', function (Closure $dependent, string $reason) {
    $this->actingAs(sourceGuardUser('super-admin'));

    $emission = Emission::factory()->create(['type' => 'CRI', 'status' => 'active']);
    $dependent($emission);

    expect(EmissionResource::canDelete($emission))->toBeFalse()
        ->and(EmissionResource::getDeleteAuthorizationResponse($emission)->message())->toContain($reason);
})->with([
    'obra com contrato' => [
        fn (Emission $emission) => Contract::factory()->forUnit(
            sourceGuardUnit(Construction::factory()->create(['emission_id' => $emission->id])),
        )->create(),
        'tem contrato registrado, inclusive excluído',
    ],
    'ciclo' => [
        fn (Emission $emission) => SalesBoardCycle::factory()->forConstruction(
            Construction::factory()->create(['emission_id' => $emission->id]),
        )->create(),
        'tem ciclo do Quadro de Vendas',
    ],
    'curva de PU' => [
        fn (Emission $emission) => EmissionPuCurveVersion::factory()->create(['emission_id' => $emission->id]),
        'tem curva de PU gerada',
    ],
]);

it('still deletes an emission that has no protected history', function () {
    $this->actingAs(sourceGuardUser());

    $emission = Emission::factory()->create();
    Construction::factory()->create(['emission_id' => $emission->id]);

    expect(EmissionResource::canDelete($emission))->toBeTrue();
});

it('refuses to restore a frozen contract, and lets a free one come back', function (string $role) {
    $this->actingAs(sourceGuardUser($role));

    $frozenUnit = sourceGuardUnit();
    $frozen = Contract::factory()->forUnit($frozenUnit)->create();
    SalesBoardCycleLine::factory()->create([
        'sales_board_cycle_baseline_id' => sourceGuardBaseline($frozenUnit->construction)->id,
        'construction_unit_id' => $frozenUnit->id,
        'contract_id' => $frozen->id,
    ]);

    /**
     * Um contrato excluído antes desta guarda existir: o estado que ela passa a
     * impedir, montado por baixo dos eventos.
     */
    Contract::query()->whereKey($frozen->id)->toBase()->update(['deleted_at' => now()]);

    $free = Contract::factory()->forUnit(sourceGuardUnit())->create();
    $free->delete();

    Livewire::test(ListContracts::class)
        ->filterTable('trashed', true)
        ->assertTableActionHidden('restore', $frozen->fresh())
        ->assertTableActionVisible('restore', $free->fresh())
        ->callTableAction('restore', $free->fresh());

    expect($frozen->fresh()->trashed())->toBeTrue()
        ->and($free->fresh()->trashed())->toBeFalse()
        ->and(ContractResource::getRestoreAuthorizationResponse($frozen->fresh())->message())
        ->toContain('não pode ser restaurado');
})->with(['super-admin', 'admin']);

it('refuses to restore an installment of a frozen contract', function () {
    $this->actingAs(sourceGuardUser('super-admin'));

    $unit = sourceGuardUnit();
    $contract = Contract::factory()->forUnit($unit)->create();
    $installment = ContractInstallment::factory()->forContract($contract)->create();
    $installment->delete();

    SalesBoardCycleLine::factory()->create([
        'sales_board_cycle_baseline_id' => sourceGuardBaseline($unit->construction)->id,
        'construction_unit_id' => $unit->id,
        'contract_id' => $contract->id,
    ]);

    expect(ContractInstallmentResource::canRestore($installment->fresh()))->toBeFalse()
        ->and(ContractInstallmentResource::getRestoreAuthorizationResponse($installment->fresh())->message())
        ->toContain('não pode ser restaurada');
});

<?php

use App\Filament\Resources\ContractInstallments\ContractInstallmentResource;
use App\Filament\Resources\ContractInstallments\Pages\ListContractInstallments;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractInstallmentsRelationManager;
use App\Models\Contract;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Importar concilia a carteira: cadastra o que é novo e atualiza o que mudou,
 * inclusive a passagem para distratado. Por isso exige criar **e** editar --
 * quem só cria não reescreve, por planilha, o que a permissão de edição
 * protege na tela.
 *
 * A regra vale no servidor: ação oculta não monta nem executa, e um mount
 * forjado não abre o assistente.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  list<string>  $permissions
 */
function importAuthorizationUser(array $permissions): User
{
    $user = User::factory()->withTwoFactor()->create(['email' => fake()->unique()->safeEmail()]);
    $user->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('hides the contract import from a profile that can create but not update contracts and ignores the forged mount', function () {
    $this->actingAs(importAuthorizationUser(['contracts.view', 'contracts.create']));

    expect(ContractResource::canCreate())->toBeTrue()
        ->and(ContractResource::canImport())->toBeFalse();

    Livewire::test(ListContracts::class)
        ->assertActionHidden('importContracts')
        ->call('mountAction', 'importContracts')
        ->assertSet('mountedActions', []);
});

it('offers the contract import to whoever can create and update contracts', function () {
    $this->actingAs(importAuthorizationUser(['contracts.view', 'contracts.create', 'contracts.update']));

    expect(ContractResource::canImport())->toBeTrue();

    Livewire::test(ListContracts::class)
        ->assertActionVisible('importContracts')
        ->mountAction('importContracts')
        ->assertActionMounted('importContracts');
});

it('hides the installment import from a profile that can create but not update installments, on the list and inside the contract', function () {
    $contract = Contract::factory()->create();

    $this->actingAs(importAuthorizationUser(['contracts.view', 'contract-installments.view', 'contract-installments.create']));

    expect(ContractInstallmentResource::canCreate())->toBeTrue()
        ->and(ContractInstallmentResource::canImport())->toBeFalse();

    Livewire::test(ListContractInstallments::class)
        ->assertActionHidden('importContractInstallments')
        ->call('mountAction', 'importContractInstallments')
        ->assertSet('mountedActions', []);

    Livewire::test(ContractInstallmentsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertActionHidden(TestAction::make('importContractInstallments')->table())
        // Criar uma parcela à mão continua: só a conciliação exige editar.
        ->assertActionVisible(TestAction::make('create')->table())
        ->call('mountAction', 'importContractInstallments', [], ['table' => true])
        ->assertSet('mountedActions', []);
});

it('offers the installment import on both screens to whoever can create and update installments', function () {
    $contract = Contract::factory()->create();

    $this->actingAs(importAuthorizationUser([
        'contracts.view',
        'contract-installments.view',
        'contract-installments.create',
        'contract-installments.update',
    ]));

    expect(ContractInstallmentResource::canImport())->toBeTrue();

    Livewire::test(ListContractInstallments::class)
        ->assertActionVisible('importContractInstallments')
        ->mountAction('importContractInstallments')
        ->assertActionMounted('importContractInstallments');

    Livewire::test(ContractInstallmentsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertActionVisible(TestAction::make('importContractInstallments')->table())
        ->mountAction(TestAction::make('importContractInstallments')->table())
        ->assertActionMounted(TestAction::make('importContractInstallments')->table());
});

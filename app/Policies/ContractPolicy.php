<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardSourceGuard;
use Illuminate\Auth\Access\Response;

/**
 * As permissões `contracts.*`, e o que um contrato já lido pelo Quadro de
 * Vendas não pode mais sofrer.
 *
 * A exclusão é lógica, mas a derivação da posição ignora contratos excluídos:
 * apagar um contrato vendido faz a venda nunca ter existido em todas as
 * competências, sem distrato nem movimento. Depois que ele entra numa posição
 * congelada, a exclusão e a restauração ficam fechadas -- o distrato é um fato
 * datado e se registra como tal.
 */
class ContractPolicy
{
    public function __construct(private readonly SalesBoardSourceGuard $sourceGuard) {}

    public function viewAny(User $user): bool
    {
        return $user->can('contracts.view');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->can('contracts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contracts.create');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->can('contracts.update') && ! $contract->trashed();
    }

    public function delete(User $user, Contract $contract): Response
    {
        if ((! $user->can('contracts.delete')) || $contract->trashed()) {
            return Response::deny();
        }

        if ($this->sourceGuard->isContractFrozen($contract->getKey())) {
            return Response::deny('Este contrato já compõe a posição congelada de um ciclo do Quadro de Vendas e não pode ser excluído. '
                .'Para um distrato, edite o contrato, mude o status para Distratado e informe a Data do Distrato.');
        }

        return Response::allow();
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('contracts.delete');
    }

    public function restore(User $user, Contract $contract): Response
    {
        if ((! $user->can('contracts.restore')) || (! $contract->trashed())) {
            return Response::deny();
        }

        if ($this->sourceGuard->isContractFrozen($contract->getKey())) {
            return Response::deny('Este contrato compõe a posição congelada de um ciclo do Quadro de Vendas e não pode ser restaurado.');
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('contracts.restore');
    }

    /**
     * A contract is commercial history: erasing it for good would take the sale,
     * the distrato and the trail of a unit with it.
     */
    public function forceDelete(User $user, Contract $contract): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}

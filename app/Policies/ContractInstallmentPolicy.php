<?php

namespace App\Policies;

use App\Filament\Support\AuthorizesThroughModelPolicy;
use App\Models\ContractInstallment;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardSourceGuard;
use Illuminate\Auth\Access\Response;

/**
 * The permission checks of the installments module, expressed as a policy so
 * that the Gate agrees with the resource.
 *
 * The relation manager embedded in the contract authorizes its actions through
 * the Gate, and the resource reads this same policy through
 * {@see AuthorizesThroughModelPolicy} -- so the table,
 * the pages, the bulk action and the relation manager all answer from here.
 *
 * Excluir ou restaurar uma parcela muda a quitação do contrato em todas as
 * competências: a derivação lê o cronograma vivo. Quando o contrato já compõe
 * uma posição congelada, as duas coisas ficam fechadas; para tirar a parcela do
 * fluxo, a data de cancelamento é o fato datado.
 */
class ContractInstallmentPolicy
{
    public function __construct(private readonly SalesBoardSourceGuard $sourceGuard) {}

    public function viewAny(User $user): bool
    {
        return $user->can('contract-installments.view');
    }

    public function view(User $user, ContractInstallment $installment): bool
    {
        return $user->can('contract-installments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contract-installments.create');
    }

    public function update(User $user, ContractInstallment $installment): bool
    {
        return $user->can('contract-installments.update') && ! $installment->trashed();
    }

    public function delete(User $user, ContractInstallment $installment): Response
    {
        if ((! $user->can('contract-installments.delete')) || $installment->trashed()) {
            return Response::deny();
        }

        if ($this->belongsToFrozenContract($installment)) {
            return Response::deny('Esta parcela pertence a um contrato que já compõe a posição congelada de um ciclo do Quadro de Vendas e não pode ser excluída. '
                .'Para tirá-la do fluxo contratual, informe a data de cancelamento.');
        }

        return Response::allow();
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('contract-installments.delete');
    }

    public function restore(User $user, ContractInstallment $installment): Response
    {
        if ((! $user->can('contract-installments.restore')) || (! $installment->trashed())) {
            return Response::deny();
        }

        if ($this->belongsToFrozenContract($installment)) {
            return Response::deny('Esta parcela pertence a um contrato que já compõe a posição congelada de um ciclo do Quadro de Vendas e não pode ser restaurada.');
        }

        return Response::allow();
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('contract-installments.restore');
    }

    /**
     * An installment is financial history: erasing it for good would take the
     * schedule it belonged to with it.
     */
    public function forceDelete(User $user, ContractInstallment $installment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function belongsToFrozenContract(ContractInstallment $installment): bool
    {
        return $this->sourceGuard->isContractFrozen($installment->contract_id);
    }
}

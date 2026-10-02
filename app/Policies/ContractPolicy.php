<?php

namespace App\Policies;

use App\Models\ConstructionUnit;
use App\Models\Contract;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardSourceGuard;
use App\Support\Contracts\ContractOccupancyPeriod;
use App\Support\SalesBoards\UnitRetirementConflict;
use App\Support\SalesBoards\UnitRetirementTimeline;
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
 *
 * Restaurar também é ocupar a unidade de novo, e a unidade baixada não é
 * ocupada no período da baixa -- a mesma regra do formulário do contrato, da
 * importação e da permuta. A baixa ignora o contrato excluído, que não ocupa;
 * sem a conferência aqui, excluir, baixar e restaurar poria um contrato vigente
 * sobre a baixa, e a competência travaria num bloqueio que a construtora não
 * vê.
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

    /**
     * A importação concilia a carteira: cadastra os contratos novos e atualiza
     * os que mudaram, inclusive a passagem para distratado. Criar sozinho não
     * pode reescrever o que já existe, então importar exige as duas permissões.
     */
    public function import(User $user): bool
    {
        return $user->can('contracts.create') && $user->can('contracts.update');
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

        $retirement = $this->retirementCrossedByRestoring($contract);

        if ($retirement !== null) {
            return Response::deny('Este contrato não pode ser restaurado: ele voltaria a ocupar a unidade no período da baixa. '
                .$retirement->describe(ConstructionUnit::query()->whereKey($contract->construction_unit_id)->first()?->display_name));
        }

        return Response::allow();
    }

    /**
     * O período de baixa que o contrato restaurado cruzaria, ou `null`. O
     * período é o do contrato como está gravado: `[venda, distrato)`, aberto
     * enquanto ele ocupa a unidade.
     */
    private function retirementCrossedByRestoring(Contract $contract): ?UnitRetirementConflict
    {
        if (blank($contract->construction_unit_id)) {
            return null;
        }

        $period = ContractOccupancyPeriod::fromValues(
            code: (string) $contract->code,
            clientName: null,
            saleDate: $contract->sale_date,
            cancellationDate: $contract->cancellation_date,
            status: $contract->status,
        );

        if ($period === null) {
            return null;
        }

        $unitId = (int) $contract->construction_unit_id;

        return UnitRetirementTimeline::forUnits([$unitId])->conflictWith($unitId, $period->startsOn, $period->endsOn);
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

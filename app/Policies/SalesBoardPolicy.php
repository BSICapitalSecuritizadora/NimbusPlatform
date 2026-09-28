<?php

namespace App\Policies;

use App\Exceptions\SalesBoardRolloutException;
use App\Models\SalesBoard;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardWriteGuard;
use Illuminate\Auth\Access\Response;

/**
 * As permissões `sales-boards.*` do Quadro de Vendas legado.
 *
 * A posição registrada é evidência: o histórico de versões vai junto com o
 * quadro (FK em cascata), e Garantias e Relatório Mensal a leem. Por isso a
 * tela não oferece exclusão nenhuma -- a regra abaixo existe para que qualquer
 * ação padrão futura já nasça autorizada pela permissão certa e pelo guard de
 * escrita, em vez de liberada por falta de policy.
 */
class SalesBoardPolicy
{
    public function __construct(private readonly SalesBoardWriteGuard $writeGuard) {}

    public function viewAny(User $user): bool
    {
        return $user->can('sales-boards.view');
    }

    public function view(User $user, SalesBoard $salesBoard): bool
    {
        return $user->can('sales-boards.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales-boards.create');
    }

    public function update(User $user, SalesBoard $salesBoard): bool
    {
        return $user->can('sales-boards.update');
    }

    /**
     * Quadro publicado pela governança do ciclo não é apagado por ninguém; o
     * motivo é o mesmo que o observer daria ao recusar.
     */
    public function delete(User $user, SalesBoard $salesBoard): Response
    {
        if (! $user->can('sales-boards.delete')) {
            return Response::deny();
        }

        try {
            $this->writeGuard->assertCanDelete($salesBoard);
        } catch (SalesBoardRolloutException $exception) {
            return Response::deny($exception->getMessage());
        }

        return Response::allow();
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('sales-boards.delete');
    }
}

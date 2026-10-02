<?php

namespace App\Policies;

use App\Exceptions\SalesBoardRolloutException;
use App\Models\SalesBoard;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardWriteGuard;
use App\Support\SalesBoards\SalesBoardAccess;
use Illuminate\Auth\Access\Response;

/**
 * As permissões `sales-boards.*` do Quadro de Vendas legado.
 *
 * A posição registrada é evidência: o histórico de versões vai junto com o
 * quadro (FK em cascata), e Garantias e Relatório Mensal a leem. Por isso a
 * tela não oferece exclusão nenhuma -- a regra abaixo existe para que qualquer
 * ação padrão futura já nasça autorizada pela permissão certa e pelo guard de
 * escrita, em vez de liberada por falta de policy.
 *
 * Ver exige também `emissions.view` ({@see SalesBoardAccess::canView()}): o
 * Quadro é um recorte da Emissão, e o Filament entrega o registro, com a
 * Emissão e a obra carregadas, a quem chamar `getRecord()` na tela.
 */
class SalesBoardPolicy
{
    public function __construct(private readonly SalesBoardWriteGuard $writeGuard) {}

    public function viewAny(User $user): bool
    {
        return SalesBoardAccess::canView($user);
    }

    public function view(User $user, SalesBoard $salesBoard): bool
    {
        return SalesBoardAccess::canView($user);
    }

    public function create(User $user): bool
    {
        return $user->can('sales-boards.create');
    }

    /**
     * Só a permissão, de propósito. `canEdit()` também decide se a competência
     * existente pode ganhar versão nova na tela de "Nova Atualização", e
     * negar aqui um quadro publicado trocaria a explicação certa -- "publicado
     * pelo fluxo de governança" -- por "exige a permissão de editar". Quadro
     * publicado e competência automatizada são recusados pelo
     * {@see SalesBoardWriteGuard} na gravação, e a tela avisa antes, pela mesma
     * regra.
     */
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

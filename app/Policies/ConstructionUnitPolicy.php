<?php

namespace App\Policies;

use App\Exceptions\SalesBoardSourceException;
use App\Models\ConstructionUnit;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardSourceGuard;
use Illuminate\Auth\Access\Response;

/**
 * As unidades respondem às permissões `constructions.*`, como o resource delas.
 *
 * Uma unidade com contrato, histórico de valor, permuta ou posição congelada
 * num ciclo não é apagada por ninguém: o banco recusaria (FK RESTRICT) e, se
 * não recusasse, a história lida dela perderia a unidade. Não existe baixa ou
 * inativação de unidade -- a regra ainda não foi decidida --, então a recusa
 * só explica o motivo.
 */
class ConstructionUnitPolicy
{
    public function __construct(private readonly SalesBoardSourceGuard $sourceGuard) {}

    public function viewAny(User $user): bool
    {
        return $user->can('constructions.view');
    }

    public function view(User $user, ConstructionUnit $unit): bool
    {
        return $user->can('constructions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('constructions.create');
    }

    public function update(User $user, ConstructionUnit $unit): bool
    {
        return $user->can('constructions.update');
    }

    public function delete(User $user, ConstructionUnit $unit): Response
    {
        if (! $user->can('constructions.delete')) {
            return Response::deny();
        }

        $anchors = $this->sourceGuard->unitAnchors($unit);

        return $anchors === []
            ? Response::allow()
            : Response::deny('A unidade não pode ser excluída: '.SalesBoardSourceException::joinReasons($anchors).'.');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('constructions.delete');
    }
}

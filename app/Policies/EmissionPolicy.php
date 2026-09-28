<?php

namespace App\Policies;

use App\Exceptions\SalesBoardSourceException;
use App\Models\Emission;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardSourceGuard;
use Illuminate\Auth\Access\Response;

/**
 * As permissões `emissions.*`.
 *
 * Sem esta policy, abrir a edição e excluir a Emissão eram liberados para
 * qualquer um que visse a lista: o Filament autoriza a página de edição e a
 * DeleteAction dela pela policy, não pelos `can*()` do resource.
 *
 * A exclusão desce em cascata por obras, unidades, quadros, operações e curvas.
 * Ela só é oferecida quando nada nessa cascata é história protegida -- o
 * motivo vem na recusa, em vez de um erro de constraint.
 */
class EmissionPolicy
{
    public function __construct(private readonly SalesBoardSourceGuard $sourceGuard) {}

    public function viewAny(User $user): bool
    {
        return $user->can('emissions.view');
    }

    public function view(User $user, Emission $emission): bool
    {
        return $user->can('emissions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('emissions.create');
    }

    public function update(User $user, Emission $emission): bool
    {
        return $user->can('emissions.update');
    }

    public function delete(User $user, Emission $emission): Response
    {
        if (! $user->can('emissions.delete')) {
            return Response::deny();
        }

        $blockers = $this->sourceGuard->emissionDeletionBlockers($emission);

        return $blockers === []
            ? Response::allow()
            : Response::deny('A emissão não pode ser excluída: '.SalesBoardSourceException::joinReasons($blockers).'.');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('emissions.delete');
    }
}

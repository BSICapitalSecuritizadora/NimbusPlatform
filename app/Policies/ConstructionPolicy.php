<?php

namespace App\Policies;

use App\Exceptions\SalesBoardSourceException;
use App\Models\Construction;
use App\Models\User;
use App\Services\SalesBoards\SalesBoardSourceGuard;
use Illuminate\Auth\Access\Response;

/**
 * As obras respondem às permissões `emissions.*`, como o resource delas.
 *
 * Excluir a obra desce em cascata pelas unidades e pelos quadros de vendas --
 * estes sem nenhuma proteção do banco, com todo o histórico de versões. A
 * exclusão só é oferecida para uma obra que ainda não tem história nenhuma.
 */
class ConstructionPolicy
{
    public function __construct(private readonly SalesBoardSourceGuard $sourceGuard) {}

    public function viewAny(User $user): bool
    {
        return $user->can('emissions.view');
    }

    public function view(User $user, Construction $construction): bool
    {
        return $user->can('emissions.view');
    }

    public function create(User $user): bool
    {
        return $user->can('emissions.create');
    }

    public function update(User $user, Construction $construction): bool
    {
        return $user->can('emissions.update');
    }

    public function delete(User $user, Construction $construction): Response
    {
        if (! $user->can('emissions.delete')) {
            return Response::deny();
        }

        $blockers = $this->sourceGuard->constructionDeletionBlockers($construction);

        return $blockers === []
            ? Response::allow()
            : Response::deny(SalesBoardSourceException::constructionDeletionBlocked($blockers)->getMessage());
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('emissions.delete');
    }
}

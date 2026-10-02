<?php

namespace App\Policies;

use App\Models\ConstructionUnitRetirement;
use App\Models\User;
use App\Services\SalesBoards\ConstructionUnitRetirementService;

/**
 * As baixas de unidade são lidas por quem vê o cadastro de obras e escritas só
 * pelo {@see ConstructionUnitRetirementService}, que confere a autoridade da
 * Gestão, o motivo e as datas.
 *
 * As escritas padrão respondem sempre `false`. A policy existe para que um
 * `CreateAction`, `EditAction` ou `DeleteAction` acrescentado no futuro à aba
 * "Baixas" não seja liberado por omissão: sem policy, o Filament permite.
 */
class ConstructionUnitRetirementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('constructions.view');
    }

    public function view(User $user, ConstructionUnitRetirement $retirement): bool
    {
        return $user->can('constructions.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ConstructionUnitRetirement $retirement): bool
    {
        return false;
    }

    public function delete(User $user, ConstructionUnitRetirement $retirement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ConstructionUnitRetirement $retirement): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ConstructionUnitRetirement $retirement): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}

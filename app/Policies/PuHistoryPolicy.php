<?php

namespace App\Policies;

use App\Enums\AccessPermission;
use App\Models\PuHistory;
use App\Models\User;

/**
 * O Histórico de PU é o PU oficial das emissões legadas e o legado legítimo das
 * governadas: quem o importa, lança, edita ou apaga mexe no PU que o relatório,
 * as garantias e o site mostram. Ver acompanha a Emissão; todo o resto exige
 * `pu.parameters.configure`.
 *
 * Sem esta policy o Filament liberava criar, editar e apagar (inclusive em
 * massa) para qualquer um que abrisse a edição da Emissão.
 */
class PuHistoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessPermission::EmissionsView->value);
    }

    public function view(User $user, PuHistory $history): bool
    {
        return $user->can(AccessPermission::EmissionsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessPermission::PuParametersConfigure->value);
    }

    /**
     * Importar cria e sobrescreve linhas: exige o mesmo que criar e editar.
     */
    public function import(User $user): bool
    {
        return $user->can(AccessPermission::PuParametersConfigure->value);
    }

    public function update(User $user, PuHistory $history): bool
    {
        return $user->can(AccessPermission::PuParametersConfigure->value);
    }

    public function delete(User $user, PuHistory $history): bool
    {
        return $user->can(AccessPermission::PuParametersConfigure->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(AccessPermission::PuParametersConfigure->value);
    }
}

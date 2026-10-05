<?php

namespace App\Policies;

use App\Enums\AccessPermission;
use App\Models\EmissionPuEvent;
use App\Models\User;

/**
 * Eventos de PU (juros, amortizações) são insumo do cálculo da curva: alterá-los
 * muda a curva da próxima geração. Ver acompanha a Emissão; criar, editar e
 * excluir -- inclusive a exclusão em massa, que o Filament autoriza pelo
 * `deleteAny` -- exigem `pu.parameters.configure`, a mesma permissão que
 * configura o cálculo.
 *
 * Sem esta policy o Filament liberava as ações padrão da tabela para qualquer um
 * que abrisse a edição da Emissão.
 */
class EmissionPuEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessPermission::EmissionsView->value);
    }

    public function view(User $user, EmissionPuEvent $event): bool
    {
        return $user->can(AccessPermission::EmissionsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessPermission::PuParametersConfigure->value);
    }

    public function update(User $user, EmissionPuEvent $event): bool
    {
        return $user->can(AccessPermission::PuParametersConfigure->value);
    }

    public function delete(User $user, EmissionPuEvent $event): bool
    {
        return $user->can(AccessPermission::PuParametersConfigure->value);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(AccessPermission::PuParametersConfigure->value);
    }
}

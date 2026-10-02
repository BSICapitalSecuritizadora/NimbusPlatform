<?php

declare(strict_types=1);

namespace Tests\Support\SalesBoards;

use App\Enums\AccessPermission;
use App\Models\User;

/**
 * Os dois lados da segregação de funções do Quadro de Vendas.
 *
 * Quem opera a competência (`sales-boards.update`) prepara: envia a validação da
 * construtora, abre a análise, abre a homologação. Quem tem
 * `sales-boards.approve` conclui: decide, devolve, aprova, publica, atesta e
 * ativa. As fixtures usam uma pessoa de cada lado para que os testes exercitem o
 * fluxo como ele acontece, e não uma única conta fazendo tudo.
 */
final class GovernanceFixture
{
    /**
     * Alguém da Gestão: só a permissão de aprovação, sem nenhum papel.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function approver(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->givePermissionTo(AccessPermission::SalesBoardsApprove->value);

        return $user;
    }

    /**
     * Quem opera a competência: enxerga e registra posição, mas não conclui.
     *
     * Enxergar o Quadro exige também `emissions.view` -- o Quadro é recorte da
     * Emissão --, e é com esta conta que as fixtures de validação, de análise e
     * de rollout agem quando o teste não informa outra: os serviços de preparo
     * recusam quem não opera a competência.
     */
    public static function operator(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            AccessPermission::SalesBoardsView->value,
            AccessPermission::SalesBoardsCreate->value,
            AccessPermission::SalesBoardsUpdate->value,
            AccessPermission::EmissionsView->value,
        ]);

        return $user;
    }

    public static function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }
}

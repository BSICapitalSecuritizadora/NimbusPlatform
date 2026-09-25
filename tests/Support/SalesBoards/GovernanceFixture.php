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
     */
    public static function approver(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(AccessPermission::SalesBoardsApprove->value);

        return $user;
    }

    /**
     * Quem opera a competência: enxerga e registra posição, mas não conclui.
     */
    public static function operator(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            AccessPermission::SalesBoardsView->value,
            AccessPermission::SalesBoardsCreate->value,
            AccessPermission::SalesBoardsUpdate->value,
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

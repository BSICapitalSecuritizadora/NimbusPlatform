<?php

namespace App\Enums;

/**
 * Quem pediu a execução.
 *
 * Duas origens, e nenhuma delas é um usuário. A execução agendada não tem ator
 * humano e não vai ganhar um: um `User` chamado "Sistema" seria uma conta
 * autenticável de verdade, com papéis e permissões reais, criada só para
 * preencher uma coluna. A procedência da automação é esta trilha -- execução,
 * alvo e tentativa --, não uma identidade inventada.
 */
enum SalesBoardAutomationRunTrigger: string
{
    case Scheduled = 'agendado';

    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendado',
            self::Manual => 'Manual',
        };
    }
}

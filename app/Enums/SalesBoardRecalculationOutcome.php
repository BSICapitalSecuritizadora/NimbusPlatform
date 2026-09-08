<?php

namespace App\Enums;

/**
 * O que o recálculo de um ciclo fez.
 *
 * `Unchanged` existe para que clicar em "Recalcular" sem que nada tenha mudado
 * não produza uma versão nova e vazia de significado: o histórico de versões
 * precisa querer dizer alguma coisa.
 */
enum SalesBoardRecalculationOutcome: string
{
    case Recalculated = 'recalculado';

    case Unchanged = 'sem_alteracao';

    case Blocked = 'bloqueado';

    public function label(): string
    {
        return match ($this) {
            self::Recalculated => 'Nova versão criada',
            self::Unchanged => 'Nada mudou',
            self::Blocked => 'Recálculo bloqueado',
        };
    }
}

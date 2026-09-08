<?php

namespace App\Enums;

/**
 * O que a geração de um ciclo fez.
 *
 * `AlreadyExists` e `Blocked` são resultados legítimos, não erros: gerar duas
 * vezes a mesma competência tem que ser inofensivo, e uma fonte incompleta não
 * pode produzir um ciclo pela metade "para preencher depois".
 */
enum SalesBoardGenerationOutcome: string
{
    case Generated = 'gerado';

    case AlreadyExists = 'ja_existente';

    case Blocked = 'bloqueado';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Ciclo gerado',
            self::AlreadyExists => 'Ciclo já existente',
            self::Blocked => 'Geração bloqueada',
        };
    }
}

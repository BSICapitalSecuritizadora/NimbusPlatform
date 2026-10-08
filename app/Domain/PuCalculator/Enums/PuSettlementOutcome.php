<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Resultado de uma tentativa de registrar, corrigir ou estornar liquidação.
 *
 *  - `Recorded`: o fato entrou no livro;
 *  - `Duplicate`: o mesmo fato já estava no livro (idempotente, nada gravado);
 *  - `Conflict`: dados diferentes para algo já liquidado: conflito aberto, nada
 *    sobrescrito;
 *  - `Rejected`: recusado antes de gravar (obrigação inexistente, dados
 *    inválidos, liquidação não vigente).
 */
enum PuSettlementOutcome: string
{
    case Recorded = 'recorded';
    case Duplicate = 'duplicate';
    case Conflict = 'conflict';
    case Rejected = 'rejected';
}

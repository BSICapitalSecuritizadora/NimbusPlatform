<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use InvalidArgumentException;

/**
 * Os insumos contratuais da curva não permitem calcular com segurança: retrato
 * ausente ou adulterado, configuração inconsistente (evento fora do horizonte,
 * evento que a engine não sabe calcular) ou insumos que mudaram no meio da
 * geração. Nunca é tratado como "sem efeito": quem recebe a exceção não grava
 * nada.
 *
 * Estende `InvalidArgumentException`, como {@see PuCurveGovernanceException}: é
 * recusa de negócio, e as telas e comandos já a mostram como tal.
 */
class PuCurveInputsException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $issues
     */
    public static function inconsistent(array $issues): self
    {
        return new self(implode("\n", $issues));
    }
}

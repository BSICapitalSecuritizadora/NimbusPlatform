<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use RuntimeException;

/**
 * A emissão tem histórico financeiro governado do PU e não pode ser apagada
 * fisicamente (Fase 7). Nada foi apagado.
 */
class PuGovernedHistoryProtectedException extends RuntimeException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly int $emissionId,
        public readonly array $reasons,
    ) {
        parent::__construct(sprintf(
            'A emissão #%d não pode ser apagada: %s. O histórico financeiro governado do PU é evidência e não sai por exclusão.',
            $emissionId,
            implode('; ', $reasons),
        ));
    }
}

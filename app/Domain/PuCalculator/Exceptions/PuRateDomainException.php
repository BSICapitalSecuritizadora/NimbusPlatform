<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use InvalidArgumentException;

/**
 * Taxa ou número-índice fora do domínio FINANCEIRO da engine do PU.
 *
 * Toda capitalização da engine multiplica por uma base positiva: 1 + taxa/100 no
 * Fator DI, no Fator Spread, no fator prefixado e no cupom do IPCA, e NI_ref/NI_ant
 * na correção do IPCA. Com base zero ou negativa o fator não tem sentido financeiro,
 * ainda que a raiz exista na matemática -- zero tem raiz zero, e base negativa tem
 * raiz real de grau ímpar. Taxa negativa com base positiva (acima de -100% a.a.) é
 * válida e continua sendo calculada.
 *
 * A recusa acontece ANTES da raiz e não se confunde com
 * `PuNumericConvergenceException`: aqui a ENTRADA é inválida; lá a entrada é válida,
 * mas o Newton limitado não conseguiu certificar o resultado.
 */
class PuRateDomainException extends InvalidArgumentException
{
    private function __construct(
        string $message,
        public readonly string $value,
        public readonly ?string $base = null,
    ) {
        parent::__construct($message);
    }

    public static function nonPositiveCompoundingBase(string $annualRate, string $base): self
    {
        return new self(sprintf(
            'A taxa de %s%% a.a. leva a base de capitalização (1 + taxa/100) a %s; o fator só existe para taxa acima de -100%% a.a., e nenhum fator foi calculado.',
            $annualRate,
            $base,
        ), $annualRate, $base);
    }

    public static function nonPositiveIndexNumber(string $referenceMonth, string $indexNumber): self
    {
        return new self(sprintf(
            'O número-índice do IPCA de %s é %s; a correção monetária só existe com número-índice positivo, e nenhum fator foi calculado.',
            $referenceMonth,
            $indexNumber,
        ), $indexNumber);
    }
}

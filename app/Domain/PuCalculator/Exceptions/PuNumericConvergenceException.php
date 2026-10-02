<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Exceptions;

use RuntimeException;

/**
 * A raiz n-ésima da engine do PU não chegou a um valor provadamente correto.
 *
 * `DailyFactorCalculator::nthRoot()` alimenta o Fator DI diário, o Fator Spread, o
 * fator prefixado e o cupom/correção do IPCA. Devolver a última aproximação de um
 * Newton que não convergiu gravaria um fator errado sem nenhum sinal; a engine
 * falha FECHADO e esta exceção interrompe o cálculo.
 *
 * Carrega só os dados da conta -- a base e o índice da raiz --, o suficiente para
 * reproduzir a falha sem expor nada da emissão.
 */
class PuNumericConvergenceException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $base,
        public readonly int $root,
    ) {
        parent::__construct($message);
    }

    public static function didNotConverge(string $base, int $root, int $iterations): self
    {
        return new self(sprintf(
            'A raiz %d-ésima de %s não convergiu em %d iterações de Newton; nenhum fator foi devolvido.',
            $root,
            $base,
            $iterations,
        ), $base, $root);
    }

    public static function vanishingDerivative(string $base, int $root, int $iteration): self
    {
        return new self(sprintf(
            'A raiz %d-ésima de %s zerou a derivada na iteração %d de Newton; nenhum fator foi devolvido.',
            $root,
            $base,
            $iteration,
        ), $base, $root);
    }

    public static function failedVerification(string $base, int $root, string $candidate, string $unitInLastPlace): self
    {
        return new self(sprintf(
            'A raiz %d-ésima de %s convergiu para %s, mas a raiz exata não está a menos de %s desse valor; nenhum fator foi devolvido.',
            $root,
            $base,
            $candidate,
            $unitInLastPlace,
        ), $base, $root);
    }
}

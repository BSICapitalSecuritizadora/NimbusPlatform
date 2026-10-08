<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Situação do valor esperado de um componente. `Unsupported` nunca carrega
 * valor: é o componente que a Fase 5 não sabe calcular com segurança (ou cujo
 * valor informado contradiz o cálculo), e torna a obrigação incompleta.
 */
enum PuObligationComponentStatus: string
{
    case Calculated = 'calculated';
    case Informed = 'informed';
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => 'Calculado',
            self::Informed => 'Informado',
            self::Unsupported => 'Não suportado',
        };
    }
}

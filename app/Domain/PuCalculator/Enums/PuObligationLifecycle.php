<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Ciclo de vida da obrigação econômica. Obrigação nunca é apagada: a que sai do
 * cronograma oficial (evento cancelado ou movido, vencimento antecipado) fica
 * `Superseded`, com motivo, e volta a `Active` se uma versão oficial posterior a
 * trouxer de novo com a mesma identidade.
 */
enum PuObligationLifecycle: string
{
    case Active = 'active';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Superseded => 'Superada',
        };
    }
}

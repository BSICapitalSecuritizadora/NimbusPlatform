<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Ciclo de vida do evento contratual. Cancelar não apaga: o evento cancelado sai
 * do cálculo e do retrato de insumos, e continua no banco com quem, quando e por
 * quê. A versão de curva que já o usou guarda o conteúdo dele no próprio retrato.
 */
enum PuEventStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Cancelled => 'Cancelado',
        };
    }
}

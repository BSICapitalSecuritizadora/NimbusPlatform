<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Origem do fato de liquidação. A origem não muda a regra: toda liquidação é
 * fechada (integral) e a diferença para o esperado é divergência.
 */
enum PuSettlementSource: string
{
    case B3 = 'b3';
    case Bank = 'bank';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::B3 => 'B3',
            self::Bank => 'Banco liquidante',
            self::Manual => 'Registro manual',
        };
    }
}

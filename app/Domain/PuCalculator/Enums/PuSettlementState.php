<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Liquidação da obrigação: fechada ou não. Não existe liquidação parcial nem
 * percentual liquidado -- uma liquidação B3 de valor diferente do esperado é
 * liquidação fechada com divergência ({@see PuReconciliationStatus::Divergent}).
 */
enum PuSettlementState: string
{
    case Unsettled = 'unsettled';
    case Settled = 'settled';

    public function label(): string
    {
        return match ($this) {
            self::Unsettled => 'Não liquidada',
            self::Settled => 'Liquidada',
        };
    }
}

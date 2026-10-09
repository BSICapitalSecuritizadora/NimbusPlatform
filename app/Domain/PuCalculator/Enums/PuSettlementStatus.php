<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Situação de um lançamento de liquidação. Só uma liquidação ativa por
 * obrigação (garantido pelo banco). As transições possíveis são `Active` →
 * `Corrected` e `Active` → `Reversed`; o lançamento de estorno nasce `Reversal` e
 * nunca é ativo.
 */
enum PuSettlementStatus: string
{
    case Active = 'active';
    case Corrected = 'corrected';
    case Reversed = 'reversed';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Vigente',
            self::Corrected => 'Corrigida',
            self::Reversed => 'Estornada',
            self::Reversal => 'Lançamento de estorno',
        };
    }
}

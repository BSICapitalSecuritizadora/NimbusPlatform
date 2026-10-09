<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Tipo do lançamento no livro de liquidações, que é só de inclusão.
 *
 *  - `Settlement`: o fato de liquidação como chegou (B3, banco ou registro manual);
 *  - `Correction`: substitui uma liquidação ativa pelo valor/data corrigidos;
 *  - `Reversal`: estorna uma liquidação ativa -- a obrigação volta a não liquidada.
 *
 * Correção e estorno nunca reescrevem a liquidação original: ela fica no livro,
 * com quem a substituiu, quando e por quê.
 */
enum PuSettlementEntryType: string
{
    case Settlement = 'settlement';
    case Correction = 'correction';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Settlement => 'Liquidação',
            self::Correction => 'Correção',
            self::Reversal => 'Estorno',
        };
    }
}

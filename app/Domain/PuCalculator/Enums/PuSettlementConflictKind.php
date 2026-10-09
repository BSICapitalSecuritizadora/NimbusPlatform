<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Por que um fato de liquidação não pôde ser aplicado sem decisão humana.
 *
 *  - `ReferenceDataMismatch`: a mesma referência externa chegou com outro valor,
 *    data ou componentes;
 *  - `ObligationAlreadySettled`: a obrigação já tem liquidação vigente com
 *    outros dados (liquidação é fechada: não há segunda liquidação);
 *  - `ReferenceReusedForOtherObligation`: a referência externa já liquidou outra
 *    obrigação.
 */
enum PuSettlementConflictKind: string
{
    case ReferenceDataMismatch = 'reference_data_mismatch';
    case ObligationAlreadySettled = 'obligation_already_settled';
    case ReferenceReusedForOtherObligation = 'reference_reused_for_other_obligation';

    public function label(): string
    {
        return match ($this) {
            self::ReferenceDataMismatch => 'Mesma referência com dados diferentes',
            self::ObligationAlreadySettled => 'Obrigação já liquidada',
            self::ReferenceReusedForOtherObligation => 'Referência já usada em outra obrigação',
        };
    }
}

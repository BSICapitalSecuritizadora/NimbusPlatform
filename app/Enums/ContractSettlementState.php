<?php

namespace App\Enums;

/**
 * Se um contrato estava quitado numa data.
 *
 * `Undetermined` separa "não estava quitado" de "não dá para saber". Um contrato
 * sem nenhuma parcela válida na data não está quitado por vacuidade -- ele é uma
 * obrigação cujo cronograma o Nimbus não conhece, e tratá-lo como quitado
 * inventaria um número que ninguém apurou.
 */
enum ContractSettlementState: string
{
    case Settled = 'quitado';

    case Outstanding = 'em_aberto';

    case Undetermined = 'indeterminado';

    public function label(): string
    {
        return match ($this) {
            self::Settled => 'Quitado',
            self::Outstanding => 'Em aberto',
            self::Undetermined => 'Indeterminado',
        };
    }
}

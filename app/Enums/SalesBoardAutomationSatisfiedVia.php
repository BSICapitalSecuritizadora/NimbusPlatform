<?php

namespace App\Enums;

/**
 * Como a competência passou a ter ciclo.
 *
 * Os dois são sucesso. `Existing` não é segunda opção nem consolação: um ciclo
 * criado à mão antes do dia 13, ou por outra instância um segundo antes, é
 * exatamente o resultado que a automação queria garantir. O que ela promete é
 * que o ciclo exista uma vez -- não que tenha sido ela a criá-lo.
 */
enum SalesBoardAutomationSatisfiedVia: string
{
    case Generated = 'gerado';

    case Existing = 'ja_existente';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Gerado pela automação',
            self::Existing => 'Ciclo já existia',
        };
    }
}

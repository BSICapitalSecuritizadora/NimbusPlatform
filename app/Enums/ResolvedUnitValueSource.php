<?php

namespace App\Enums;

/**
 * De onde o valor vigente de uma unidade numa data foi resolvido.
 *
 * `Absent` é um estado de primeira classe: uma unidade sem valor conhecido na
 * data não vale zero, e tratar as duas coisas como a mesma coisa produziria
 * conformidade inventada em cima de dado que não existe.
 *
 * Não confundir com {@see UnitValueSource}, que é a procedência gravada de uma
 * linha do histórico.
 */
enum ResolvedUnitValueSource: string
{
    case History = 'history';

    case Base = 'base';

    case Absent = 'absent';

    public function label(): string
    {
        return match ($this) {
            self::History => 'Histórico de valores',
            self::Base => 'Valor base da unidade',
            self::Absent => 'Sem valor conhecido',
        };
    }
}

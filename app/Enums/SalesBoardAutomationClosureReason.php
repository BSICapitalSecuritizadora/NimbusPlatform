<?php

namespace App\Enums;

/**
 * Por que um alvo da automação foi encerrado sem ser satisfeito.
 *
 * Todos dizem a mesma coisa de fundo -- a competência saiu do perímetro que a
 * automação atende -- e diferem em quem precisa agir para ela voltar. O retorno
 * ao legado foi uma decisão registrada no rollout; o escopo alterado pede nova
 * homologação; o resto é um empreendimento que deixou de pertencer a uma Emissão
 * automatizada, ou uma competência anterior ao início da automação.
 */
enum SalesBoardAutomationClosureReason: string
{
    case ReturnedToLegacy = 'retorno_ao_legado';

    case ScopeSuspended = 'escopo_alterado';

    case OutsidePerimeter = 'fora_do_perimetro';

    public function label(): string
    {
        return match ($this) {
            self::ReturnedToLegacy => 'Emissão devolvida ao modo legado',
            self::ScopeSuspended => 'Automação suspensa por mudança de escopo',
            self::OutsidePerimeter => 'Fora do perímetro da automação',
        };
    }
}

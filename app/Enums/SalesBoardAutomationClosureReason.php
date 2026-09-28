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
 *
 * `CompetenceCancelled` é diferente: a competência não saiu do perímetro, a
 * Gestão a encerrou. Por isso é o único motivo que a descoberta não desfaz.
 */
enum SalesBoardAutomationClosureReason: string
{
    case ReturnedToLegacy = 'retorno_ao_legado';

    case ScopeSuspended = 'escopo_alterado';

    case OutsidePerimeter = 'fora_do_perimetro';

    case CompetenceCancelled = 'competencia_cancelada';

    /**
     * A descoberta pode devolver à automação um alvo encerrado por este motivo?
     */
    public function isReopenable(): bool
    {
        return $this !== self::CompetenceCancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::ReturnedToLegacy => 'Emissão devolvida ao modo legado',
            self::ScopeSuspended => 'Automação suspensa por mudança de escopo',
            self::OutsidePerimeter => 'Fora do perímetro da automação',
            self::CompetenceCancelled => 'Competência cancelada pela Gestão',
        };
    }
}

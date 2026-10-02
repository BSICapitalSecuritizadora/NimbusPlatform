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
 * `EmissionLiquidated` é a Emissão encerrada ("Liquidada"): não há competência
 * mensal a automatizar de uma operação que acabou. Como o status é reversível,
 * a descoberta reabre o alvo se a liquidação for desfeita.
 *
 * `CompetenceCancelled` é diferente: a competência não saiu do perímetro, a
 * Gestão a encerrou. Por isso a descoberta não o desfaz -- só a reabertura da
 * competência pela Gestão ("Reabrir competência") devolve o alvo à situação de
 * satisfeito.
 *
 * `LaterCompetencePublished` também não volta: a competência ficou para trás de
 * uma competência posterior do empreendimento já publicada, que reflete os
 * fatos dela, e a geração a recusa para sempre -- uma publicação nunca é
 * desfeita. Sem o encerramento, o alvo ficaria bloqueado, tentando de novo e
 * alertando todo dia sem que ninguém pudesse resolver.
 */
enum SalesBoardAutomationClosureReason: string
{
    case ReturnedToLegacy = 'retorno_ao_legado';

    case ScopeSuspended = 'escopo_alterado';

    case OutsidePerimeter = 'fora_do_perimetro';

    case CompetenceCancelled = 'competencia_cancelada';

    case EmissionLiquidated = 'emissao_liquidada';

    case LaterCompetencePublished = 'competencia_posterior_publicada';

    /**
     * A descoberta pode devolver à automação um alvo encerrado por este motivo?
     */
    public function isReopenable(): bool
    {
        return ! in_array($this, [self::CompetenceCancelled, self::LaterCompetencePublished], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ReturnedToLegacy => 'Emissão devolvida ao modo legado',
            self::ScopeSuspended => 'Automação suspensa por mudança de escopo',
            self::OutsidePerimeter => 'Fora do perímetro da automação',
            self::CompetenceCancelled => 'Competência cancelada pela Gestão',
            self::EmissionLiquidated => 'Emissão liquidada',
            self::LaterCompetencePublished => 'Competência posterior já publicada',
        };
    }
}

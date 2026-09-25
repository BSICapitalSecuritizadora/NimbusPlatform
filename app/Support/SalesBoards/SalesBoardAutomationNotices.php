<?php

declare(strict_types=1);

namespace App\Support\SalesBoards;

use App\Enums\SalesBoardSource;
use App\Models\Emission;

/**
 * O que as telas da automação precisam avisar, mesmo sem nada ter falhado.
 *
 * São lacunas de decisão, não defeitos: o projeto ainda não tem SLA para os
 * lembretes, nem regra para quando parar de gerar competências de uma Emissão
 * liquidada. Nenhuma das duas é decidida aqui -- decidir seria inventar requisito
 * de negócio --, mas as duas precisam estar à vista de quem ativa e acompanha a
 * automação, em vez de enterradas num arquivo de configuração.
 */
final class SalesBoardAutomationNotices
{
    /**
     * O status de Emissão que a tela chama de "Liquidada".
     *
     * @see Emission::STATUS_OPTIONS
     */
    private const LIQUIDATED_STATUS = 'closed';

    /**
     * Os avisos do cabeçalho do rollout de uma Emissão.
     *
     * @return list<string>
     */
    public static function forRollout(Emission $emission): array
    {
        $notices = [];

        if (SalesBoardAutomationConfig::allRemindersOff()) {
            $notices[] = self::remindersOffNotice();
        }

        if ($emission->usesAutomatedSalesBoard() && $emission->status === self::LIQUIDATED_STATUS) {
            $notices[] = 'Esta Emissão está liquidada e continua automatizada. A regra de parada para Emissões liquidadas '
                .'ainda não foi decidida: até lá, a automação segue gerando uma competência por mês, até a Emissão voltar ao modo legado.';
        }

        return $notices;
    }

    /**
     * Os avisos da tela "Automação do Quadro".
     *
     * @return list<string>
     */
    public static function forAutomationScreen(): array
    {
        $notices = [];

        if (SalesBoardAutomationConfig::allRemindersOff()) {
            $notices[] = self::remindersOffNotice();
        }

        $liquidated = Emission::query()
            ->where('sales_board_source', SalesBoardSource::Automated)
            ->where('status', self::LIQUIDATED_STATUS)
            ->count();

        if ($liquidated > 0) {
            $notices[] = sprintf(
                '%d Emissão(ões) liquidada(s) continua(m) automatizada(s): a regra de parada ainda não foi decidida, '
                    .'e a automação segue gerando competências mensais para ela(s).',
                $liquidated,
            );
        }

        return $notices;
    }

    private static function remindersOffNotice(): string
    {
        return 'Os lembretes de prazo da automação estão todos desligados (o SLA ainda não foi definido): os responsáveis '
            .'só recebem aviso de execução interrompida e de suspensão por mudança de escopo, nunca de competência parada.';
    }
}

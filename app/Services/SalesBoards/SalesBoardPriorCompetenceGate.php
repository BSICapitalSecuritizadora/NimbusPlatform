<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRectificationStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleRectification;
use App\Support\Dates\InclusiveDateBound;
use Carbon\CarbonImmutable;

/**
 * A regra de ordem da aprovação: M só é publicada depois que a competência de
 * onde os movimentos dela partem estiver publicada.
 *
 * Os movimentos de M partem da âncora ({@see SalesBoardPriorPositionResolver}):
 * M-1 ou, quando M-1 foi cancelada, a primeira competência não cancelada antes
 * dela. Os extemporâneos de M são apurados contra a âncora, e a janela de M
 * absorve os meses cancelados até ela. Publicar M com a âncora aberta deixaria
 * publicados extemporâneos apurados contra uma versão que ainda vai mudar -- e,
 * quando a âncora fosse publicada depois, os mesmos fatos sairiam em duas
 * publicações (ou, se ela fosse cancelada, em nenhuma).
 *
 * Por isso o portão anda a mesma cadeia da âncora, com o mesmo limite: a partir
 * de M-1, pula os ciclos cancelados, e o primeiro ciclo não cancelado precisa
 * estar aprovado sem retificação aberta. É a regra "M-1 aprovada ou cancelada"
 * aplicada à âncora: com M-1 aprovada nada muda; com M-1 cancelada, M espera a
 * competência em que se apoia. Mês sem ciclo encerra a cadeia sem exigência
 * (obra sem unidades, competência nunca gerada, primeira competência
 * automatizada) -- a competência sem âncora absorve as canceladas que pulou --,
 * e ciclo aberto sempre tem saída: aprovar ou cancelar.
 *
 * Vale também para a aprovação de uma retificação: a âncora dela precisa estar
 * encerrada.
 *
 * Ordem de locks do Quadro: depois do lock do próprio ciclo, os ciclos da
 * cadeia são lidos com `sharedLock()` um mês por vez, do mais recente para o
 * mais antigo -- a mesma ordem da baixa, da permuta e da reabertura. Um mês por
 * vez, e não a faixa de 24 meses de uma vez: a leitura travada de uma faixa
 * seguraria lacunas que a geração de outras competências precisa. É o que
 * serializa "Aprovar M" com "Retificar", "Reabrir" ou "Cancelar" qualquer
 * competência da cadeia, que travam aquele ciclo com FOR UPDATE: ou o outro ato
 * espera a aprovação terminar e encontra M publicada, ou a aprovação espera o
 * outro ato e encontra a cadeia como ele a deixou. A retificação aberta também
 * é lida com lock, pelo mesmo motivo.
 */
class SalesBoardPriorCompetenceGate
{
    /**
     * Por que a competência ainda não pode ser aprovada pela regra de ordem, ou
     * `null` quando pode.
     */
    public function assess(SalesBoardCycle $cycle): ?string
    {
        return $this->refusal($cycle)?->getMessage();
    }

    /**
     * @throws SalesBoardManagementReviewException
     */
    public function assertClosed(SalesBoardCycle $cycle): void
    {
        $refusal = $this->refusal($cycle);

        if ($refusal !== null) {
            throw $refusal;
        }
    }

    private function refusal(SalesBoardCycle $cycle): ?SalesBoardManagementReviewException
    {
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();
        $locking = SalesBoardCycle::query()->getConnection()->transactionLevel() > 0;
        $cancelledMonths = [];

        for ($back = 1; $back <= SalesBoardPriorPositionResolver::MAXIMUM_MONTHS_BACK; $back++) {
            $candidateMonth = $month->subMonthsNoOverflow($back);
            $previous = $this->cycleOf((int) $cycle->construction_id, $candidateMonth, $locking);

            if (! $previous instanceof SalesBoardCycle) {
                return null;
            }

            if ($previous->status === SalesBoardCycleStatus::Cancelled) {
                $cancelledMonths[] = $candidateMonth->format('m/Y');

                continue;
            }

            return $this->anchorRefusal($previous, $candidateMonth, $month, $cancelledMonths, $locking);
        }

        return null;
    }

    /**
     * A âncora encerrada -- aprovada e sem retificação aberta -- libera a
     * competência; qualquer outra situação a segura, nomeando a âncora e as
     * canceladas que a cadeia pulou até ela.
     *
     * Aprovado sem retificação aberta é publicado: a aprovação cria a
     * publicação na mesma transação em que grava o status. O status vem da
     * leitura travada, que enxerga o que acabou de ser commitado; a publicação,
     * numa leitura comum, ficaria presa ao instantâneo da transação.
     *
     * @param  list<string>  $cancelledMonths  `m/Y`, do mais recente para o mais antigo
     */
    private function anchorRefusal(
        SalesBoardCycle $anchor,
        CarbonImmutable $anchorMonth,
        CarbonImmutable $month,
        array $cancelledMonths,
        bool $locking,
    ): ?SalesBoardManagementReviewException {
        $rectificationQuery = SalesBoardCycleRectification::query()
            ->where('sales_board_cycle_id', $anchor->getKey())
            ->where('status', SalesBoardRectificationStatus::Open->value);

        if ($locking) {
            $rectificationQuery->sharedLock();
        }

        if ($rectificationQuery->exists()) {
            return SalesBoardManagementReviewException::priorCompetenceUnderRectification(
                $anchorMonth->format('m/Y'),
                $month->format('m/Y'),
                $cancelledMonths,
            );
        }

        if ($anchor->status === SalesBoardCycleStatus::Approved) {
            return null;
        }

        return SalesBoardManagementReviewException::priorCompetenceOpen(
            $anchorMonth->format('m/Y'),
            $anchor->status->label(),
            $month->format('m/Y'),
            $cancelledMonths,
        );
    }

    /**
     * O ciclo do empreendimento num mês, lido com lock compartilhado dentro de
     * uma transação.
     *
     * Faixa em vez de igualdade: uma coluna `date` gravada pelo Eloquent
     * carrega a hora junto no SQLite e é comparada como texto. A coluna fica
     * nua na comparação, e o índice da unique continua valendo.
     */
    private function cycleOf(int $constructionId, CarbonImmutable $month, bool $locking): ?SalesBoardCycle
    {
        $query = SalesBoardCycle::query()
            ->where('construction_id', $constructionId)
            ->whereBetween('reference_month', [$month->toDateString(), InclusiveDateBound::upperBound($month)]);

        if ($locking) {
            $query->sharedLock();
        }

        return $query->first(['id', 'status', 'reference_month']);
    }
}

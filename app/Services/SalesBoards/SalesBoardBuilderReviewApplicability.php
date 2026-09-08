<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;

/**
 * Se uma validação ainda fala do quadro vigente, e se o quadro vigente pode ser
 * validado.
 *
 * Uma regra só, num lugar só. Espalhar a comparação de fingerprint pela tela e
 * pelos serviços garantiria que, em algum ponto, alguém comparasse o id da
 * versão em vez do resumo da posição -- e aí um recálculo que não mudou nada
 * visível passaria a invalidar a conferência da construtora sem motivo.
 */
class SalesBoardBuilderReviewApplicability
{
    /**
     * Impede validar uma versão que já se sabe desatualizada.
     *
     * Material e bloqueante impedem; `SourceOnly` não. A construtora valida o
     * quadro que enxerga, e numa mudança apenas de origem material o quadro é
     * exatamente o mesmo -- barrar ali seria pedir que ela conferisse de novo
     * uma posição idêntica por uma razão que ela sequer consegue ver.
     */
    public function baselineBlocker(?SalesBoardCycleBaseline $baseline): ?SalesBoardStaleImpact
    {
        if ($baseline === null) {
            return null;
        }

        $impact = $baseline->stale_impact ?? SalesBoardStaleImpact::None;

        return in_array($impact, [SalesBoardStaleImpact::Material, SalesBoardStaleImpact::Blocking], true)
            ? $impact
            : null;
    }

    public function isBaselineEligible(?SalesBoardCycleBaseline $baseline): bool
    {
        return ($baseline !== null) && ($this->baselineBlocker($baseline) === null);
    }

    /**
     * A validação em andamento do ciclo, se houver. No máximo uma.
     */
    public function activeDraft(SalesBoardCycle $cycle): ?SalesBoardBuilderReview
    {
        return SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardBuilderReviewStatus::Draft)
            ->orderByDesc('attempt')
            ->first();
    }

    /**
     * A validação que ainda vale para a versão vigente do ciclo.
     *
     * Vale a que revisou o mesmo quadro -- mesmo resumo de posição -- e que não
     * foi substituída.
     */
    public function applicableReview(SalesBoardCycle $cycle): ?SalesBoardBuilderReview
    {
        $baseline = $cycle->currentBaseline;

        if ($baseline === null) {
            return null;
        }

        return SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->whereNot('status', SalesBoardBuilderReviewStatus::Superseded)
            ->where('snapshot_fingerprint', $baseline->snapshot_fingerprint)
            ->orderByDesc('attempt')
            ->first();
    }

    /**
     * As validações que deixaram de falar do quadro vigente.
     *
     * @return list<SalesBoardBuilderReview>
     */
    public function reviewsOutdatedBy(SalesBoardCycle $cycle, SalesBoardCycleBaseline $newBaseline): array
    {
        return SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->whereIn('status', [SalesBoardBuilderReviewStatus::Draft, SalesBoardBuilderReviewStatus::Submitted])
            ->where('snapshot_fingerprint', '!=', $newBaseline->snapshot_fingerprint)
            ->orderBy('attempt')
            ->get()
            ->all();
    }
}

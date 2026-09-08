<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementReview;

/**
 * Se uma análise ainda fala do quadro vigente, e qual validação da construtora
 * ela deve analisar.
 *
 * Uma regra só, num lugar só, pelo mesmo motivo da Fase D: espalhar a comparação
 * de fingerprint garantiria que, em algum ponto, alguém comparasse o id da
 * versão em vez do resumo da posição -- e aí uma correção de origem material
 * passaria a descartar decisões da Gestão sem que nada tivesse mudado para ela.
 */
class SalesBoardManagementReviewApplicability
{
    /**
     * A análise em andamento do ciclo, se houver. No máximo uma.
     */
    public function activeDraft(SalesBoardCycle $cycle): ?SalesBoardManagementReview
    {
        return SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardManagementReviewStatus::Draft)
            ->orderByDesc('attempt')
            ->first();
    }

    /**
     * A validação da construtora que a Gestão deve analisar.
     *
     * É a enviada que fala do **quadro vigente** -- não a última aberta. Pegar a
     * mais recente por data entregaria à Gestão uma declaração sobre outra
     * versão sempre que uma rodada tivesse sido substituída no meio do caminho,
     * e a análise nasceria falando de números que ninguém mais mantém.
     */
    public function submittedBuilderReview(SalesBoardCycle $cycle, SalesBoardCycleBaseline $baseline): ?SalesBoardBuilderReview
    {
        return SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardBuilderReviewStatus::Submitted)
            ->where('snapshot_fingerprint', $baseline->snapshot_fingerprint)
            ->orderByDesc('attempt')
            ->first();
    }

    /**
     * A análise que ainda vale para a versão vigente do ciclo.
     */
    public function applicableReview(SalesBoardCycle $cycle): ?SalesBoardManagementReview
    {
        $baseline = $cycle->currentBaseline;

        if ($baseline === null) {
            return null;
        }

        return SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->whereNot('status', SalesBoardManagementReviewStatus::Superseded)
            ->where('snapshot_fingerprint', $baseline->snapshot_fingerprint)
            ->orderByDesc('attempt')
            ->first();
    }

    /**
     * As análises em andamento que deixaram de falar do quadro vigente.
     *
     * Só as em andamento. Uma análise devolvida ou aprovada já terminou, e o
     * status dela é o registro do que a Gestão decidiu naquela rodada --
     * sobrescrevê-lo com "substituída" apagaria justamente o fato que a trilha
     * durável existe para guardar. Elas continuam consultáveis, e a comparação
     * de fingerprint continua dizendo, para quem perguntar, que os fatos que
     * elas analisaram não são mais os vigentes.
     *
     * @return list<SalesBoardManagementReview>
     */
    public function reviewsOutdatedBy(SalesBoardCycle $cycle, SalesBoardCycleBaseline $newBaseline): array
    {
        return SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardManagementReviewStatus::Draft)
            ->where('snapshot_fingerprint', '!=', $newBaseline->snapshot_fingerprint)
            ->orderBy('attempt')
            ->get()
            ->all();
    }
}

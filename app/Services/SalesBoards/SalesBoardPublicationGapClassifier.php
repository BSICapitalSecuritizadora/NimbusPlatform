<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\ConstructionSalesPosition;
use App\DTOs\SalesBoards\EmissionSalesPosition;
use App\DTOs\SalesBoards\SalesBoardPublicationGaps;
use App\Enums\SalesBoardCycleStatus;
use App\Models\Emission;
use App\Models\SalesBoardCycle;

/**
 * Explica, pelo ciclo mensal, o empreendimento que ficou sem o quadro publicado
 * da competência -- com a posição transportada de um mês anterior ou sem
 * posição nenhuma.
 *
 * Para cada um deles:
 *
 * - há ciclo do mês cancelado e nenhum ciclo do mês aberto: **cancelada**. A
 *   Gestão encerrou a competência, e a posição não vai chegar por ali;
 * - senão, se a automação da Emissão cobre o mês: **aguardando publicação**. O
 *   ciclo existe e ainda não foi aprovado -- ou nem foi gerado;
 * - senão: nada a explicar pelo ciclo. É o registro manual que atrasou.
 *
 * A classificação olha o estado do ciclo, e não o histórico: uma competência
 * cancelada e depois reaberta volta a ter ciclo aberto e volta a ser
 * "aguardando publicação", sem que nada aqui precise saber da reabertura.
 *
 * Uma consulta só, e só quando a posição está incompleta: o relatório mensal
 * chama isto uma vez por competência.
 */
class SalesBoardPublicationGapClassifier
{
    public function classify(Emission $emission, EmissionSalesPosition $position): SalesBoardPublicationGaps
    {
        $incomplete = array_values(array_filter(
            $position->positions,
            fn (ConstructionSalesPosition $construction): bool => $construction->wasCarriedForward()
                || (! $construction->isResolved() && $construction->status->isExpected()),
        ));

        if ($incomplete === []) {
            return SalesBoardPublicationGaps::none();
        }

        $month = $position->positionDate->startOfMonth();

        $cyclesByConstruction = SalesBoardCycle::query()
            ->whereIn('construction_id', array_map(
                fn (ConstructionSalesPosition $construction): int => $construction->constructionId,
                $incomplete,
            ))
            ->whereDate('reference_month', $month->toDateString())
            ->orderByDesc('id')
            ->get(['id', 'emission_id', 'construction_id', 'reference_month', 'status', 'cancelled_at', 'cancellation_reason'])
            ->groupBy('construction_id');

        $automated = $emission->automationCovers($month);
        $awaiting = [];
        $cancelled = [];

        foreach ($incomplete as $construction) {
            $cycles = $cyclesByConstruction->get($construction->constructionId, collect());

            $openCycle = $cycles->first(fn (SalesBoardCycle $cycle): bool => $cycle->status !== SalesBoardCycleStatus::Cancelled);
            $cancelledCycle = $cycles->first(fn (SalesBoardCycle $cycle): bool => $cycle->status === SalesBoardCycleStatus::Cancelled);

            if (($openCycle === null) && ($cancelledCycle instanceof SalesBoardCycle)) {
                $cancelled[] = ['position' => $construction, 'cycle' => $cancelledCycle];

                continue;
            }

            if ($automated) {
                $awaiting[] = $construction;
            }
        }

        return new SalesBoardPublicationGaps($awaiting, $cancelled);
    }
}

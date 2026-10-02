<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementReview;
use Carbon\CarbonImmutable;

/**
 * Encerra as rodadas abertas de um ciclo que não vai seguir por elas.
 *
 * Usado pelo cancelamento da competência e pela desistência da retificação:
 * nos dois casos a validação e a análise em andamento deixam de levar a algum
 * lugar, e ficam substituídas -- nada é apagado, e o motivo diz por quê.
 *
 * As rodadas encerradas não se reescrevem. A validação enviada que uma análise
 * devolveu, ou que uma análise aprovou e publicou, já teve a sua resposta
 * ({@see SalesBoardBuilderReviewApplicability::closedRoundIds()}): marcá-la
 * substituída reescreveria o que aconteceu naquela devolução ou naquela
 * publicação.
 *
 * Quem chama trava o ciclo antes, dentro da transação: ciclo primeiro, revisões
 * depois -- a mesma ordem da aprovação e da devolução.
 */
class SalesBoardOpenReviewsSuperseder
{
    public function __construct(
        private readonly SalesBoardBuilderReviewApplicability $builderApplicability,
    ) {}

    /**
     * @return array{management: int, builder: int} quantas análises e validações foram substituídas
     */
    public function supersede(SalesBoardCycle $cycle, string $reason, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        return [
            'management' => $this->supersedeOpenManagementReviews($cycle, $reason, $at),
            'builder' => $this->supersedeOpenBuilderReviews($cycle, $reason, $at),
        ];
    }

    /**
     * As análises em andamento. Devolvidas, aprovadas e substituídas já estão
     * encerradas.
     */
    private function supersedeOpenManagementReviews(SalesBoardCycle $cycle, string $reason, CarbonImmutable $at): int
    {
        $reviews = SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardManagementReviewStatus::Draft)
            ->lockForUpdate()
            ->get();

        foreach ($reviews as $review) {
            $review->forceFill([
                'status' => SalesBoardManagementReviewStatus::Superseded,
                'superseded_at' => $at,
                'superseded_reason' => $reason,
            ])->save();
        }

        return $reviews->count();
    }

    /**
     * As validações em andamento e a enviada que ainda aguardava a Gestão.
     */
    private function supersedeOpenBuilderReviews(SalesBoardCycle $cycle, string $reason, CarbonImmutable $at): int
    {
        $reviews = SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->whereIn('status', [SalesBoardBuilderReviewStatus::Draft, SalesBoardBuilderReviewStatus::Submitted])
            ->whereKeyNot($this->builderApplicability->closedRoundIds($cycle))
            ->lockForUpdate()
            ->get();

        foreach ($reviews as $review) {
            $review->forceFill([
                'status' => SalesBoardBuilderReviewStatus::Superseded,
                'superseded_at' => $at,
                'superseded_reason' => $reason,
            ])->save();
        }

        return $reviews->count();
    }
}

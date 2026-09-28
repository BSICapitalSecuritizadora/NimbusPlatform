<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Exceptions\SalesBoardCycleCancellationException;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Cancelar competência": o fim de um ciclo que não vai terminar em publicação.
 *
 * O caso que o pede é a competência que saiu da automação -- a Emissão voltou ao
 * legado, ou o mês é anterior ao início -- e que por isso não pode mais ser
 * aprovada. Sem este caminho ela ficava parada para sempre em validação ou em
 * análise.
 *
 * Regras decididas pela Gestão:
 *
 * - só ciclo **não aprovado**: o quadro publicado é imutável;
 * - **motivo obrigatório**, com autor e data gravados no ciclo, e trilha no
 *   Activitylog (`sales_board`);
 * - a autoridade é a da Gestão (`sales-boards.approve`), a mesma de aprovar;
 * - as rodadas **abertas** de validação e de análise são substituídas -- nada é
 *   apagado, e as rodadas já encerradas (devolvidas) continuam como estavam;
 * - o **alvo da automação** da competência é encerrado e não é reaberto pela
 *   descoberta: gerar outro ciclo para a mesma competência é outra decisão.
 *
 * Nada do que foi apurado é tocado: versões, linhas e movimentos continuam
 * inteiros e consultáveis.
 */
class SalesBoardCycleCancellationService
{
    public const SUPERSEDED_REASON = 'competencia_cancelada';

    public function __construct(
        private readonly SalesBoardAutomationTargetClosureService $targetClosureService,
    ) {}

    public function cancel(SalesBoardCycle $cycle, ?User $actor, string $reason): SalesBoardCycle
    {
        if ($actor === null) {
            throw SalesBoardCycleCancellationException::actorRequired();
        }

        SalesBoardApprovalAuthority::authorize($actor);

        $reason = $this->normalizeReason($reason);

        return DB::transaction(function () use ($cycle, $actor, $reason): SalesBoardCycle {
            /**
             * Ciclo primeiro, revisões depois: a mesma ordem da aprovação e da
             * devolução. É o que faz cancelar e aprovar serializarem -- nunca um
             * ciclo cancelado com um quadro publicado ao lado.
             */
            $locked = SalesBoardCycle::query()
                ->whereKey($cycle->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, [SalesBoardCycleStatus::Approved, SalesBoardCycleStatus::Cancelled], true)) {
                throw SalesBoardCycleCancellationException::notCancellable($locked->status);
            }

            $now = CarbonImmutable::now();

            $supersededManagement = $this->supersedeOpenManagementReviews($locked, $now);
            $supersededBuilder = $this->supersedeOpenBuilderReviews($locked, $now);

            $locked->forceFill([
                'status' => SalesBoardCycleStatus::Cancelled,
                'cancelled_at' => $now,
                'cancelled_by_user_id' => $actor->getKey(),
                'cancellation_reason' => $reason,
            ])->save();

            $closedTargets = $this->targetClosureService->closeForCancelledCycle(
                $locked,
                sprintf('Competência cancelada pela Gestão: %s', $reason),
                $actor,
            );

            Log::info('Sales board competence cancelled', [
                'event' => 'sales_board_competence_cancelled',
                'cycle_id' => (int) $locked->getKey(),
                'construction_id' => (int) $locked->construction_id,
                'reference_month' => $locked->reference_month?->format('Y-m'),
                'actor_user_id' => (int) $actor->getKey(),
                'superseded_builder_reviews' => $supersededBuilder,
                'superseded_management_reviews' => $supersededManagement,
                'closed_automation_targets' => $closedTargets,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * As análises em andamento. Devolvidas e substituídas já estão encerradas.
     */
    private function supersedeOpenManagementReviews(SalesBoardCycle $cycle, CarbonImmutable $now): int
    {
        $reviews = SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardManagementReviewStatus::Draft)
            ->lockForUpdate()
            ->get();

        foreach ($reviews as $review) {
            $review->forceFill([
                'status' => SalesBoardManagementReviewStatus::Superseded,
                'superseded_at' => $now,
                'superseded_reason' => self::SUPERSEDED_REASON,
            ])->save();
        }

        return $reviews->count();
    }

    /**
     * As validações em andamento e a enviada que ainda aguardava a Gestão.
     *
     * Uma validação enviada cuja análise foi devolvida é uma rodada encerrada: a
     * construtora já recebeu a rodada seguinte, e marcá-la substituída
     * reescreveria o que aconteceu naquela devolução.
     */
    private function supersedeOpenBuilderReviews(SalesBoardCycle $cycle, CarbonImmutable $now): int
    {
        $returnedRounds = SalesBoardManagementReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->where('status', SalesBoardManagementReviewStatus::Returned)
            ->pluck('sales_board_builder_review_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $reviews = SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->whereIn('status', [SalesBoardBuilderReviewStatus::Draft, SalesBoardBuilderReviewStatus::Submitted])
            ->whereKeyNot($returnedRounds)
            ->lockForUpdate()
            ->get();

        foreach ($reviews as $review) {
            $review->forceFill([
                'status' => SalesBoardBuilderReviewStatus::Superseded,
                'superseded_at' => $now,
                'superseded_reason' => self::SUPERSEDED_REASON,
            ])->save();
        }

        return $reviews->count();
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH) {
            throw SalesBoardCycleCancellationException::reasonRequired(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH);
        }

        return mb_substr($reason, 0, SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH);
    }
}

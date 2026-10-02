<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardCycleStatus;
use App\Events\SalesBoards\SalesBoardPriorPositionChanged;
use App\Exceptions\SalesBoardCycleCancellationException;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardPublication;
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
 * - só ciclo **não aprovado e sem publicação**: o quadro publicado é imutável.
 *   A competência em retificação voltou a "Gerado", mas continua publicada --
 *   a saída dela é "Desistir da retificação", e não o cancelamento;
 * - **motivo obrigatório**, com autor e data gravados no ciclo, e trilha no
 *   Activitylog (`sales_board`);
 * - a autoridade é a da Gestão (`sales-boards.approve`), a mesma de aprovar;
 * - as rodadas **abertas** de validação e de análise são substituídas -- nada é
 *   apagado, e as rodadas já encerradas (devolvidas) continuam como estavam
 *   ({@see SalesBoardOpenReviewsSuperseder});
 * - o **alvo da automação** da competência é encerrado e não é reaberto pela
 *   descoberta. A volta, quando o cancelamento se mostra um engano, é reabrir
 *   o mesmo ciclo ({@see SalesBoardCycleReopeningService}), não gerar outro
 *   para a mesma competência.
 *
 * Nada do que foi apurado é tocado: versões, linhas e movimentos continuam
 * inteiros e consultáveis.
 *
 * Depois do commit sai {@see SalesBoardPriorPositionChanged}: a competência
 * seguinte passa a absorver os fatos desta, e a verificação dela é antecipada
 * -- a cadeia dela mudou, e ela fica "Alterações materiais" mesmo que o mês
 * cancelado não tenha tido fato.
 */
class SalesBoardCycleCancellationService
{
    public const SUPERSEDED_REASON = 'competencia_cancelada';

    public function __construct(
        private readonly SalesBoardAutomationTargetClosureService $targetClosureService,
        private readonly SalesBoardOpenReviewsSuperseder $openReviewsSuperseder,
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

            /**
             * Publicação, e não status: a competência em retificação voltou a
             * "Gerado" e continua com a posição publicada. Lida com lock, para
             * enxergar a publicação de uma aprovação que acabou de commitar.
             */
            $published = SalesBoardPublication::query()
                ->where('sales_board_cycle_id', $locked->getKey())
                ->sharedLock()
                ->exists();

            if ($published) {
                throw SalesBoardCycleCancellationException::publishedCompetence();
            }

            $now = CarbonImmutable::now();

            $superseded = $this->openReviewsSuperseder->supersede($locked, self::SUPERSEDED_REASON, $now);
            $supersededManagement = $superseded['management'];
            $supersededBuilder = $superseded['builder'];

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

            SalesBoardPriorPositionChanged::dispatch(
                (int) $locked->construction_id,
                CarbonImmutable::parse($locked->reference_month->toDateString())->startOfMonth(),
                SalesBoardPriorPositionChanged::COMPETENCE_CANCELLED,
            );

            return $locked->refresh();
        });
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

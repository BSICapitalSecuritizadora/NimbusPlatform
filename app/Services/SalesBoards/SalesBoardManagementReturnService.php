<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardManagementReviewStatus;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Devolve a competência à construtora, sem tocar em nada do que ela declarou.
 *
 * Existe para o caso em que a Gestão precisa de esclarecimento, confirmação
 * adicional ou uma declaração refeita -- e a fonte do Nimbus não está em
 * questão. Quando o problema é o dado operacional, o caminho é outro: marcar a
 * pendência como "correção necessária", corrigir a fonte e recalcular.
 *
 * A validação enviada **não é editada nem substituída**. Ela continua Submitted,
 * com tudo o que a construtora afirmou, porque aquilo foi dito e continua sendo
 * verdade sobre aquela versão. O que nasce é a rodada seguinte: uma validação
 * nova, sobre o mesmo quadro, com as sete seções pendentes e nenhuma divergência
 * copiada.
 *
 * Não copiar as divergências é deliberado. Transportá-las faria a construtora
 * reenviar, sem reler, exatamente o que a Gestão acabou de questionar -- e a
 * segunda rodada nasceria idêntica à primeira, com a aparência de uma nova
 * conferência.
 */
class SalesBoardManagementReturnService
{
    public function __construct(
        private readonly SalesBoardBuilderReviewOpeningService $builderReviewOpeningService,
    ) {}

    /**
     * @return array{review: SalesBoardManagementReview, builderReview: SalesBoardBuilderReview}
     */
    public function returnToBuilder(
        SalesBoardManagementReview $review,
        ?User $actor,
        string $reason,
    ): array {
        if ($actor === null) {
            throw SalesBoardManagementReviewException::actorRequired();
        }

        $reason = $this->normalizeReason($reason);

        if ($reason === null) {
            throw SalesBoardManagementReviewException::returnReasonRequired();
        }

        return DB::transaction(function () use ($review, $actor, $reason): array {
            /**
             * Ciclo primeiro, análise depois: a mesma ordem da aprovação. É o
             * que faz devolver e aprovar serializarem em vez de produzirem uma
             * análise devolvida ao lado de um ciclo aprovado.
             */
            $cycle = SalesBoardCycle::query()
                ->whereKey($review->sales_board_cycle_id)
                ->lockForUpdate()
                ->firstOrFail();

            $review = SalesBoardManagementReview::query()
                ->whereKey($review->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($review->isSuperseded()) {
                throw SalesBoardManagementReviewException::reviewSuperseded();
            }

            if (! $review->isEditable()) {
                throw SalesBoardManagementReviewException::reviewNotEditable();
            }

            if ($cycle->status !== SalesBoardCycleStatus::ManagementReview) {
                throw SalesBoardManagementReviewException::cycleNotInManagement($cycle->status);
            }

            $baseline = SalesBoardCycleBaseline::query()->find($cycle->current_baseline_id);

            if (! $baseline instanceof SalesBoardCycleBaseline) {
                throw SalesBoardManagementReviewException::withoutCurrentBaseline();
            }

            if (! $review->appliesTo($baseline)) {
                throw SalesBoardManagementReviewException::baselineChanged();
            }

            $now = CarbonImmutable::now();

            $review->forceFill([
                'status' => SalesBoardManagementReviewStatus::Returned,
                'returned_at' => $now,
                'returned_by_user_id' => $actor->getKey(),
                'return_reason' => $reason,
            ])->save();

            /**
             * A competência volta a depender da construtora antes de a rodada
             * nova nascer: a abertura da Fase D só aceita ciclo em geração ou em
             * validação, e essa regra é dela -- não se reescreve daqui.
             */
            $cycle->forceFill(['status' => SalesBoardCycleStatus::BuilderReview])->save();

            /**
             * A rodada seguinte é criada pelo serviço da Fase D, sobre a mesma
             * versão vigente. Reimplementar aqui a criação das sete seções
             * garantiria que, no dia em que a Fase D acrescentasse a oitava,
             * uma devolução produzisse uma validação incompleta.
             *
             * A verificação de obsolescência que a abertura normal faz não se
             * repete: devolver não é publicar, e a aplicabilidade já foi
             * conferida acima contra a versão vigente sob lock. Uma derivação
             * completa dentro desta transação só a alongaria.
             */
            $builderReview = $this->builderReviewOpeningService->openNextAttempt($cycle, $baseline, $actor);

            return [
                'review' => $review->refresh(),
                'builderReview' => $builderReview,
            ];
        });
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH) {
            return null;
        }

        return mb_substr($reason, 0, SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardNonconformityDecision;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementNonconformity;
use App\Models\SalesBoardManagementReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O que a Gestão pode concluir sobre cada pendência, enquanto a análise é
 * rascunho.
 *
 * Mudar de ideia é normal: uma conclusão precipitada não pode obrigar a abrir
 * outra rodada. O congelamento acontece no encerramento -- aprovação, devolução
 * ou substituição -- e a partir dali o model da pendência recusa qualquer
 * gravação, relendo a análise do banco.
 *
 * A decisão trava ciclo, análise e só então a pendência: a mesma ordem da
 * aprovação e da devolução. Travar só a pendência deixava uma "correção
 * necessária" entrar enquanto a aprovação derivava a fonte, e o Quadro saía
 * publicado ao lado de uma pendência que exige corrigir a fonte.
 *
 * Não há histórico de cada clique. As decisões que importam são as que
 * atravessaram o portão, e elas ficam nas linhas duráveis; registrar cada
 * hesitação transformaria a trilha de auditoria num log de interface. O que
 * mudou de fato continua no Activity log, que já observa este model.
 */
class SalesBoardManagementDecisionService
{
    /**
     * Um motivo precisa dizer algo. O piso é o mesmo do motivo de recálculo --
     * dez caracteres -- e ele não julga semântica: barra "ok", "-" e ".", que é
     * o que aparece quando alguém está preenchendo campo obrigatório, e deixa
     * passar qualquer justificativa real.
     */
    public const MINIMUM_REASON_LENGTH = 10;

    public const MAXIMUM_REASON_LENGTH = 2000;

    public function __construct(
        private readonly SalesBoardManagementReviewApplicability $applicability,
    ) {}

    public function decide(
        SalesBoardManagementNonconformity $nonconformity,
        SalesBoardNonconformityDecision $decision,
        ?string $reason,
        ?User $actor = null,
    ): SalesBoardManagementNonconformity {
        /**
         * Pendência, análise e ciclo são identidade imutável, e lê-los fora da
         * transação é de propósito: no `REPEATABLE READ` a primeira leitura
         * simples fixa o retrato da transação, e fixá-lo antes dos locks faria
         * a versão vigente ser lida como era antes de quem segurava o ciclo.
         */
        $reviewId = SalesBoardManagementNonconformity::query()
            ->whereKey($nonconformity->getKey())
            ->valueOrFail('sales_board_management_review_id');

        $cycleId = SalesBoardManagementReview::query()
            ->whereKey($reviewId)
            ->valueOrFail('sales_board_cycle_id');

        return DB::transaction(function () use ($nonconformity, $decision, $reason, $actor, $reviewId, $cycleId): SalesBoardManagementNonconformity {
            $cycle = SalesBoardCycle::query()
                ->whereKey($cycleId)
                ->lockForUpdate()
                ->firstOrFail();

            $review = SalesBoardManagementReview::query()
                ->whereKey($reviewId)
                ->lockForUpdate()
                ->firstOrFail();

            $nonconformity = SalesBoardManagementNonconformity::query()
                ->whereKey($nonconformity->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->setRelation('review', $review);

            $this->assertEditable($review, $cycle);

            /**
             * A origem decide o que é conclusão admissível. Uma declaração da
             * construtora não vira "exceção aprovada", e uma venda fora da
             * política não vira "não procede": em ambos os casos publicar-se-ia
             * um número que a própria Gestão sabe estar errado, com um rótulo
             * que sugere o contrário.
             */
            if (! $decision->isAllowedFor($nonconformity->origin)) {
                throw SalesBoardManagementReviewException::decisionNotAllowedForOrigin($decision, $nonconformity->origin);
            }

            $reason = $this->normalizeReason($reason);

            if ($decision->requiresReason()) {
                if ($reason === null) {
                    throw SalesBoardManagementReviewException::decisionReasonRequired();
                }

                $now = CarbonImmutable::now();

                $nonconformity->forceFill([
                    'decision' => $decision,
                    'decision_reason' => $reason,
                    'decided_at' => $now,
                    'decided_by_user_id' => $actor?->getKey(),
                ])->save();

                return $nonconformity->refresh();
            }

            if ($reason !== null) {
                throw SalesBoardManagementReviewException::decisionReasonNotAllowed();
            }

            /**
             * Voltar para "pendente" apaga a conclusão anterior inteira -- não
             * só o rótulo. Deixar o motivo antigo ao lado de "pendente" faria a
             * tela mostrar uma justificativa para uma decisão que não existe.
             */
            $nonconformity->forceFill([
                'decision' => SalesBoardNonconformityDecision::Pending,
                'decision_reason' => null,
                'decided_at' => null,
                'decided_by_user_id' => null,
            ])->save();

            return $nonconformity->refresh();
        });
    }

    /**
     * A análise precisa estar aberta **e** falar do quadro vigente.
     *
     * Se uma nova versão material apareceu no meio da análise, continuar
     * decidindo sobre a versão anterior só acumularia conclusões que a
     * aprovação teria de recusar.
     */
    private function assertEditable(SalesBoardManagementReview $review, SalesBoardCycle $cycle): void
    {
        if ($review->isSuperseded()) {
            throw SalesBoardManagementReviewException::reviewSuperseded();
        }

        if (! $review->isEditable()) {
            throw SalesBoardManagementReviewException::reviewNotEditable();
        }

        $current = SalesBoardCycleBaseline::query()->find($cycle->current_baseline_id);

        if (! $review->appliesTo($current)) {
            throw SalesBoardManagementReviewException::baselineChanged();
        }
    }

    /**
     * Texto sem marcação, aparado e limitado. Não interpreta o conteúdo: exige
     * que exista.
     */
    private function normalizeReason(?string $reason): ?string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
            return null;
        }

        return mb_substr($reason, 0, self::MAXIMUM_REASON_LENGTH);
    }
}

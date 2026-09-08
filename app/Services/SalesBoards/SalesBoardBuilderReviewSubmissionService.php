<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\BuilderReviewerIdentity;
use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fecha a validação da construtora e entrega a competência à Gestão.
 *
 * O envio é o ponto em que o formulário vira declaração: a partir daqui nada do
 * que a construtora escreveu muda mais. Corrigir é abrir a tentativa seguinte --
 * o que preserva o que foi afirmado antes, que é justamente o que a Gestão
 * precisa analisar.
 *
 * Enviar **não** é aprovar. O ciclo passa a "em análise da Gestão" e para aí:
 * nenhuma não conformidade é criada, nenhum quadro é publicado, ninguém é
 * notificado. Essas decisões são da fase seguinte, e antecipá-las aqui faria a
 * submissão da construtora parecer um aval que ela não é.
 */
class SalesBoardBuilderReviewSubmissionService
{
    /**
     * Versão do texto que a construtora aceita ao enviar. Congelada junto com a
     * submissão para que, se o texto mudar, se saiba qual foi aceito.
     */
    public const DECLARATION_VERSION = '2026-09-v1';

    public function __construct(
        private readonly SalesBoardStaleDetectionService $staleDetectionService,
        private readonly SalesBoardBuilderReviewApplicability $applicability,
    ) {}

    public function submit(
        SalesBoardBuilderReview $review,
        BuilderReviewerIdentity $reviewer,
        ?string $overallComment = null,
    ): SalesBoardBuilderReview {
        /**
         * A conferência final contra a fonte acontece **fora** da transação,
         * pelo mesmo motivo da abertura: ela grava o que encontrou, e uma recusa
         * desfaria esse registro junto. Descobrir aqui -- e não na análise da
         * Gestão -- que a posição enviada já nasceu desatualizada é o que este
         * custo compra, uma vez por validação.
         *
         * O que precisa de lock é a transição, não a derivação: a corrida a
         * evitar é dois envios simultâneos, e essa é resolvida logo abaixo.
         */
        $this->refreshStaleMetadata($review);

        return DB::transaction(function () use ($review, $reviewer, $overallComment): SalesBoardBuilderReview {
            /**
             * Ciclo e revisão travados na mesma ordem em que a abertura os toca:
             * o ciclo primeiro. Dois envios simultâneos serializam aqui, e o
             * segundo encontra a revisão já enviada em vez de produzir uma
             * segunda transição.
             */
            $cycle = SalesBoardCycle::query()
                ->whereKey($review->sales_board_cycle_id)
                ->lockForUpdate()
                ->firstOrFail();

            $review = SalesBoardBuilderReview::query()
                ->whereKey($review->getKey())
                ->lockForUpdate()
                ->with('sections')
                ->firstOrFail();

            if ($review->status === SalesBoardBuilderReviewStatus::Submitted) {
                throw SalesBoardBuilderReviewException::alreadySubmitted();
            }

            if (! $review->isEditable()) {
                throw SalesBoardBuilderReviewException::reviewNotEditable();
            }

            if ($cycle->status !== SalesBoardCycleStatus::BuilderReview) {
                throw SalesBoardBuilderReviewException::cycleNotReviewable($cycle->status);
            }

            $this->assertStillApplies($cycle, $review);
            $this->assertSectionsResolved($review);
            $this->assertReviewerIdentified($reviewer);

            $now = CarbonImmutable::now();

            $review->forceFill([
                'status' => SalesBoardBuilderReviewStatus::Submitted,
                'submitted_at' => $now,
                'submitted_by_user_id' => $reviewer->internalUserId,
                'reviewer_type' => $reviewer->type->value,
                'reviewer_key' => $reviewer->stableKey,
                'reviewer_name' => $reviewer->displayName,
                'reviewer_email' => $reviewer->email,
                'declaration_version' => self::DECLARATION_VERSION,
                'overall_comment' => $this->normalizeComment($overallComment) ?? $review->overall_comment,
            ])->save();

            $cycle->forceFill(['status' => SalesBoardCycleStatus::ManagementReview])->save();

            return $review->refresh();
        });
    }

    /**
     * Confronta a versão vigente com a fonte de agora, antes de travar nada.
     */
    private function refreshStaleMetadata(SalesBoardBuilderReview $review): void
    {
        $cycle = $review->cycle;

        if (($cycle === null) || ($cycle->current_baseline_id === null)) {
            throw SalesBoardBuilderReviewException::withoutCurrentBaseline();
        }

        if (! $review->isEditable()) {
            throw $review->isSubmitted()
                ? SalesBoardBuilderReviewException::alreadySubmitted()
                : SalesBoardBuilderReviewException::reviewNotEditable();
        }

        $this->staleDetectionService->check($cycle);
    }

    /**
     * A validação precisa continuar falando do quadro vigente.
     *
     * A tela pode estar aberta há uma hora. Se nesse intervalo alguém recalculou
     * a posição, a conferência é sobre números que não são mais os do Nimbus, e
     * deixá-la atravessar para a Gestão entregaria uma análise nascida errada.
     */
    private function assertStillApplies(SalesBoardCycle $cycle, SalesBoardBuilderReview $review): void
    {
        $baseline = SalesBoardCycleBaseline::query()->find($cycle->current_baseline_id);

        if (! $review->appliesTo($baseline)) {
            throw SalesBoardBuilderReviewException::baselineChanged();
        }

        $blocker = $this->applicability->baselineBlocker($baseline);

        if ($blocker !== null) {
            throw SalesBoardBuilderReviewException::baselineNotEligible($blocker);
        }
    }

    /**
     * As sete seções precisam ter recebido uma resposta, e uma seção marcada
     * como divergente precisa realmente ter divergência.
     *
     * A segunda checagem não é paranoia: o status da seção e as divergências são
     * duas linhas diferentes do banco, e uma remoção concorrente pode deixá-las
     * discordando. Enviar nesse estado entregaria à Gestão uma seção que diz
     * "tem problema" sem dizer qual.
     */
    private function assertSectionsResolved(SalesBoardBuilderReview $review): void
    {
        /**
         * Listadas na ordem em que aparecem na tela. Uma mensagem que enumera
         * seções em ordem arbitrária obriga quem lê a procurar cada uma.
         */
        $pending = $review->sections
            ->filter(fn (SalesBoardBuilderReviewSection $section): bool => ! $section->status->isResolved())
            ->map(fn (SalesBoardBuilderReviewSection $section) => $section->section)
            ->sortBy(fn (SectionEnum $section): int => array_search($section, SectionEnum::ordered(), true))
            ->values()
            ->all();

        if ($pending !== []) {
            throw SalesBoardBuilderReviewException::sectionsPending($pending);
        }

        foreach ($review->sections as $section) {
            if ($section->status !== SalesBoardBuilderReviewSectionStatus::Divergent) {
                continue;
            }

            if (! $section->divergences()->exists()) {
                throw SalesBoardBuilderReviewException::divergentSectionWithoutDivergence($section->section);
            }
        }
    }

    private function assertReviewerIdentified(BuilderReviewerIdentity $reviewer): void
    {
        if ((trim($reviewer->stableKey) === '') || (trim($reviewer->displayName) === '')) {
            throw SalesBoardBuilderReviewException::reviewerIdentityRequired();
        }
    }

    private function normalizeComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : mb_substr(strip_tags($comment), 0, 2000);
    }
}

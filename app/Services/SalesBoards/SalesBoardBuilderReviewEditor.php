<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O que a construtora pode mexer enquanto a validação é rascunho.
 *
 * Rascunho é formulário: confirmar uma seção, registrar uma divergência, mudar
 * de ideia e apagá-la são operações normais. Exigir append-only aqui obrigaria a
 * construtora a conviver com o próprio erro de digitação até a Gestão analisá-lo.
 * O congelamento acontece no envio, que é quando a declaração passa a valer.
 *
 * Toda operação reconfere o estado no banco antes de gravar. A visibilidade de um
 * botão na tela não é garantia de nada: entre carregar a página e clicar, a
 * revisão pode ter sido enviada por outra pessoa ou uma nova versão do quadro
 * pode ter sido gerada.
 *
 * A reconferência só vale sob a mesma ordem de locks do envio e da abertura:
 * ciclo, revisão, seção e, por último, a divergência. Travar só a seção deixava
 * o envio passar no meio da edição -- e a declaração enviada ganhava uma
 * divergência, ou uma seção voltava a "pendente", depois de congelada.
 */
class SalesBoardBuilderReviewEditor
{
    public function __construct(
        private readonly SalesBoardBuilderDivergenceValidator $validator,
        private readonly SalesBoardBuilderReviewApplicability $applicability,
    ) {}

    /**
     * "Esta seção confere."
     *
     * Só é aceito se a seção realmente não tiver divergências. Confirmar uma
     * seção que tem discordâncias registradas produziria uma submissão que se
     * contradiz -- e a Gestão receberia "confirmado" ao lado de três apontamentos.
     */
    public function confirmSection(
        SalesBoardBuilderReviewSection $section,
        ?string $comment = null,
    ): SalesBoardBuilderReviewSection {
        $anchors = $this->anchorsOfSection((int) $section->getKey());

        return DB::transaction(function () use ($anchors, $comment): SalesBoardBuilderReviewSection {
            $section = $this->lockedSection($anchors);

            if ($section->divergences()->exists()) {
                throw SalesBoardBuilderReviewException::cannotConfirmWithDivergences($section->section);
            }

            $section->forceFill([
                'status' => SalesBoardBuilderReviewSectionStatus::Confirmed,
                'comment' => $this->normalizeComment($comment),
                'confirmed_at' => CarbonImmutable::now(),
            ])->save();

            return $section->refresh();
        });
    }

    /**
     * Devolve a seção ao estado de não revisada.
     *
     * Existe para a construtora poder desfazer uma confirmação antes de enviar.
     */
    public function reopenSection(SalesBoardBuilderReviewSection $section): SalesBoardBuilderReviewSection
    {
        $anchors = $this->anchorsOfSection((int) $section->getKey());

        return DB::transaction(function () use ($anchors): SalesBoardBuilderReviewSection {
            $section = $this->lockedSection($anchors);

            $section->forceFill([
                'status' => $section->divergences()->exists()
                    ? SalesBoardBuilderReviewSectionStatus::Divergent
                    : SalesBoardBuilderReviewSectionStatus::Pending,
                'confirmed_at' => null,
            ])->save();

            return $section->refresh();
        });
    }

    public function addDivergence(
        SalesBoardBuilderReviewSection $section,
        SalesBoardBuilderDivergenceInput $input,
    ): SalesBoardBuilderDivergence {
        $anchors = $this->anchorsOfSection((int) $section->getKey());

        return DB::transaction(function () use ($anchors, $input): SalesBoardBuilderDivergence {
            $section = $this->lockedSection($anchors);
            $review = $section->review;

            $this->validator->validate($review, $section, $input);

            $divergence = SalesBoardBuilderDivergence::query()->create([
                'sales_board_builder_review_id' => $review->getKey(),
                'sales_board_builder_review_section_id' => $section->getKey(),
                ...$input->toAttributes(),
            ]);

            $this->syncSectionStatus($section);

            return $divergence;
        });
    }

    public function updateDivergence(
        SalesBoardBuilderDivergence $divergence,
        SalesBoardBuilderDivergenceInput $input,
    ): SalesBoardBuilderDivergence {
        $anchors = $this->anchorsOfDivergence((int) $divergence->getKey());

        return DB::transaction(function () use ($anchors, $input): SalesBoardBuilderDivergence {
            $section = $this->lockedSection($anchors);
            $divergence = $this->lockedDivergence($anchors);

            $this->validator->validate($section->review, $section, $input);

            $divergence->forceFill($input->toAttributes())->save();

            $this->syncSectionStatus($section);

            return $divergence->refresh();
        });
    }

    /**
     * Remover a última divergência devolve a seção a "pendente", nunca a
     * "confirmada".
     *
     * Apagar o apontamento não é o mesmo que dizer que a seção confere: a
     * construtora precisa afirmar isso de novo, explicitamente.
     */
    public function removeDivergence(SalesBoardBuilderDivergence $divergence): void
    {
        $anchors = $this->anchorsOfDivergence((int) $divergence->getKey());

        DB::transaction(function () use ($anchors): void {
            $section = $this->lockedSection($anchors);
            $divergence = $this->lockedDivergence($anchors);

            $divergence->delete();

            $this->syncSectionStatus($section);
        });
    }

    public function updateOverallComment(SalesBoardBuilderReview $review, ?string $comment): SalesBoardBuilderReview
    {
        $cycleId = (int) SalesBoardBuilderReview::query()
            ->whereKey($review->getKey())
            ->valueOrFail('sales_board_cycle_id');

        return DB::transaction(function () use ($review, $comment, $cycleId): SalesBoardBuilderReview {
            $cycle = $this->lockedCycle($cycleId);

            $review = SalesBoardBuilderReview::query()
                ->whereKey($review->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($review, $cycle);

            $review->forceFill(['overall_comment' => $this->normalizeComment($comment)])->save();

            return $review->refresh();
        });
    }

    private function syncSectionStatus(SalesBoardBuilderReviewSection $section): void
    {
        $hasDivergences = $section->divergences()->exists();

        $section->forceFill([
            'status' => $hasDivergences
                ? SalesBoardBuilderReviewSectionStatus::Divergent
                : SalesBoardBuilderReviewSectionStatus::Pending,
            'confirmed_at' => null,
        ])->save();
    }

    /**
     * Seção, revisão e ciclo de uma seção, lidos antes da transação.
     *
     * São identidade imutável, e lê-los fora dela é de propósito: no
     * `REPEATABLE READ` a primeira leitura simples fixa o retrato da transação,
     * e fixá-lo antes dos locks faria o status da revisão ser lido como era
     * antes de quem segurava o ciclo.
     *
     * @return array{cycle: int, review: int, section: int, divergence: int|null}
     */
    private function anchorsOfSection(int $sectionId): array
    {
        $reviewId = (int) SalesBoardBuilderReviewSection::query()
            ->whereKey($sectionId)
            ->valueOrFail('sales_board_builder_review_id');

        return [
            'cycle' => (int) SalesBoardBuilderReview::query()->whereKey($reviewId)->valueOrFail('sales_board_cycle_id'),
            'review' => $reviewId,
            'section' => $sectionId,
            'divergence' => null,
        ];
    }

    /**
     * @return array{cycle: int, review: int, section: int, divergence: int|null}
     */
    private function anchorsOfDivergence(int $divergenceId): array
    {
        $sectionId = (int) SalesBoardBuilderDivergence::query()
            ->whereKey($divergenceId)
            ->valueOrFail('sales_board_builder_review_section_id');

        return [
            ...$this->anchorsOfSection($sectionId),
            'divergence' => $divergenceId,
        ];
    }

    private function lockedCycle(int $cycleId): SalesBoardCycle
    {
        return SalesBoardCycle::query()
            ->whereKey($cycleId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Trava ciclo, revisão e seção, nessa ordem, e confere a revisão sob o lock.
     *
     * @param  array{cycle: int, review: int, section: int, divergence: int|null}  $anchors
     */
    private function lockedSection(array $anchors): SalesBoardBuilderReviewSection
    {
        $cycle = $this->lockedCycle($anchors['cycle']);

        $review = SalesBoardBuilderReview::query()
            ->whereKey($anchors['review'])
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertEditable($review, $cycle);

        return SalesBoardBuilderReviewSection::query()
            ->whereKey($anchors['section'])
            ->lockForUpdate()
            ->firstOrFail()
            ->setRelation('review', $review);
    }

    /**
     * @param  array{cycle: int, review: int, section: int, divergence: int|null}  $anchors
     */
    private function lockedDivergence(array $anchors): SalesBoardBuilderDivergence
    {
        return SalesBoardBuilderDivergence::query()
            ->whereKey($anchors['divergence'])
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * A revisão precisa estar aberta **e** falar do quadro vigente.
     *
     * Se uma nova versão material apareceu no meio da conferência, continuar
     * registrando divergências contra a versão anterior só acumularia trabalho
     * que a Gestão teria de descartar.
     */
    private function assertEditable(SalesBoardBuilderReview $review, SalesBoardCycle $cycle): void
    {
        if (! $review->isEditable()) {
            throw SalesBoardBuilderReviewException::reviewNotEditable();
        }

        $current = SalesBoardCycleBaseline::query()->find($cycle->current_baseline_id);

        if (! $review->appliesTo($current)) {
            throw SalesBoardBuilderReviewException::baselineChanged();
        }

        $blocker = $this->applicability->baselineBlocker($current);

        if ($blocker !== null) {
            throw SalesBoardBuilderReviewException::baselineNotEligible($blocker);
        }
    }

    private function normalizeComment(?string $comment): ?string
    {
        $comment = trim((string) $comment);

        return $comment === '' ? null : mb_substr(strip_tags($comment), 0, 2000);
    }
}

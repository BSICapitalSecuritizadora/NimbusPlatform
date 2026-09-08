<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardBuilderDivergenceInput;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\SalesBoardBuilderDivergence;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
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
        return DB::transaction(function () use ($section, $comment): SalesBoardBuilderReviewSection {
            $section = $this->lockedSection($section);
            $this->assertEditable($section->review);

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
        return DB::transaction(function () use ($section): SalesBoardBuilderReviewSection {
            $section = $this->lockedSection($section);
            $this->assertEditable($section->review);

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
        return DB::transaction(function () use ($section, $input): SalesBoardBuilderDivergence {
            $section = $this->lockedSection($section);
            $review = $section->review;
            $this->assertEditable($review);

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
        return DB::transaction(function () use ($divergence, $input): SalesBoardBuilderDivergence {
            $divergence = SalesBoardBuilderDivergence::query()
                ->whereKey($divergence->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $section = $this->lockedSection($divergence->section);
            $this->assertEditable($section->review);

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
        DB::transaction(function () use ($divergence): void {
            $divergence = SalesBoardBuilderDivergence::query()
                ->whereKey($divergence->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $section = $this->lockedSection($divergence->section);
            $this->assertEditable($section->review);

            $divergence->delete();

            $this->syncSectionStatus($section);
        });
    }

    public function updateOverallComment(SalesBoardBuilderReview $review, ?string $comment): SalesBoardBuilderReview
    {
        return DB::transaction(function () use ($review, $comment): SalesBoardBuilderReview {
            $review = SalesBoardBuilderReview::query()
                ->whereKey($review->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($review);

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

    private function lockedSection(SalesBoardBuilderReviewSection $section): SalesBoardBuilderReviewSection
    {
        return SalesBoardBuilderReviewSection::query()
            ->whereKey($section->getKey())
            ->lockForUpdate()
            ->with('review')
            ->firstOrFail();
    }

    /**
     * A revisão precisa estar aberta **e** falar do quadro vigente.
     *
     * Se uma nova versão material apareceu no meio da conferência, continuar
     * registrando divergências contra a versão anterior só acumularia trabalho
     * que a Gestão teria de descartar.
     */
    private function assertEditable(SalesBoardBuilderReview $review): void
    {
        if (! $review->isEditable()) {
            throw SalesBoardBuilderReviewException::reviewNotEditable();
        }

        $cycle = $review->cycle;
        $current = $cycle?->currentBaseline;

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

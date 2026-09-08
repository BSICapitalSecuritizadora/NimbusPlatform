<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\Enums\SalesBoardBuilderReviewSection as SectionEnum;
use App\Enums\SalesBoardBuilderReviewSectionStatus;
use App\Enums\SalesBoardBuilderReviewStatus;
use App\Enums\SalesBoardCycleStatus;
use App\Exceptions\SalesBoardBuilderReviewException;
use App\Models\SalesBoardBuilderReview;
use App\Models\SalesBoardBuilderReviewSection;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Abre a validação da construtora sobre a versão vigente.
 *
 * Sempre contra a versão vigente, nunca contra uma histórica: pedir à
 * construtora que valide a V1 quando a V2 já é a posição do Nimbus produziria
 * uma conferência sobre números que ninguém mais pretende usar. As versões
 * antigas continuam consultáveis -- só não recebem validação nova.
 *
 * Antes de abrir, a posição é verificada contra a fonte de agora. É uma
 * derivação completa, e ela se paga: abrir uma validação é ato deliberado e raro,
 * e mandar para a construtora um quadro que já se sabe desatualizado custa uma
 * ida e volta inteira. Nas telas seguintes basta o metadado já gravado.
 */
class SalesBoardBuilderReviewOpeningService
{
    public function __construct(
        private readonly SalesBoardStaleDetectionService $staleDetectionService,
        private readonly SalesBoardBuilderReviewApplicability $applicability,
    ) {}

    public function open(SalesBoardCycle $cycle, ?User $actor = null): SalesBoardBuilderReview
    {
        $cycle = $cycle->fresh();

        $existing = $this->applicability->activeDraft($cycle);

        if ($existing !== null) {
            return $existing;
        }

        /**
         * A verificação acontece **fora** da transação de propósito.
         *
         * Ela grava no baseline o que encontrou, e uma recusa faz a transação
         * inteira voltar atrás -- inclusive esse registro. O operador leria
         * "posição desatualizada" na mensagem e "sem alterações" no badge da
         * tela seguinte, que é a pior combinação possível: a constatação some
         * junto com a recusa.
         *
         * A janela entre verificar e travar não abre risco: dentro da transação
         * a versão vigente é relida sob lock, e a validação nasce ancorada no
         * que estiver lá.
         */
        $this->refreshStaleMetadata($cycle);

        return DB::transaction(function () use ($cycle, $actor): SalesBoardBuilderReview {
            /**
             * O lock do ciclo é o que faz dois operadores clicando ao mesmo
             * tempo produzirem uma validação só. Sem ele, os dois leriam
             * "nenhum rascunho" e os dois criariam o seu.
             */
            $locked = SalesBoardCycle::query()
                ->whereKey($cycle->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->applicability->activeDraft($locked);

            if ($existing !== null) {
                return $existing;
            }

            if (! in_array($locked->status, [SalesBoardCycleStatus::Generated, SalesBoardCycleStatus::BuilderReview], true)) {
                throw SalesBoardBuilderReviewException::cycleNotReviewable($locked->status);
            }

            $baseline = SalesBoardCycleBaseline::query()->find($locked->current_baseline_id);

            if (! $baseline instanceof SalesBoardCycleBaseline) {
                throw SalesBoardBuilderReviewException::withoutCurrentBaseline();
            }

            $blocker = $this->applicability->baselineBlocker($baseline);

            if ($blocker !== null) {
                throw SalesBoardBuilderReviewException::baselineNotEligible($blocker);
            }

            $review = $this->createReview($locked, $baseline, $actor);

            if ($locked->status !== SalesBoardCycleStatus::BuilderReview) {
                $locked->forceFill(['status' => SalesBoardCycleStatus::BuilderReview])->save();
            }

            return $review;
        });
    }

    /**
     * Confronta a versão vigente com a fonte de agora e grava o resultado.
     *
     * É uma derivação completa, e ela se paga: abrir uma validação é ato raro e
     * deliberado, e mandar à construtora um quadro que já se sabe desatualizado
     * custa uma ida e volta inteira. As telas seguintes leem o metadado gravado
     * aqui e não pagam nada.
     */
    private function refreshStaleMetadata(SalesBoardCycle $cycle): void
    {
        if ($cycle->current_baseline_id === null) {
            throw SalesBoardBuilderReviewException::withoutCurrentBaseline();
        }

        if (! in_array($cycle->status, [SalesBoardCycleStatus::Generated, SalesBoardCycleStatus::BuilderReview], true)) {
            throw SalesBoardBuilderReviewException::cycleNotReviewable($cycle->status);
        }

        $this->staleDetectionService->check($cycle);
    }

    /**
     * Abre a tentativa seguinte sobre uma versão que quem chama já validou.
     *
     * Existe para a devolução da Gestão, que precisa exatamente disto -- uma
     * rodada nova, limpa, sobre o mesmo quadro -- e nada do resto: o ciclo já
     * está travado, a versão vigente já foi relida e a aplicabilidade já foi
     * conferida. Reimplementar a criação lá garantiria que, no dia em que uma
     * oitava seção existisse, a devolução produzisse uma validação incompleta.
     *
     * Não verifica nada. Quem chama é responsável por ter travado o ciclo e por
     * ter conferido que a versão é a vigente e é elegível; o caminho normal de
     * abertura continua sendo {@see self::open()}.
     */
    public function openNextAttempt(
        SalesBoardCycle $cycle,
        SalesBoardCycleBaseline $baseline,
        ?User $actor = null,
    ): SalesBoardBuilderReview {
        return $this->createReview($cycle, $baseline, $actor);
    }

    /**
     * Cria a validação e as sete seções, todas pendentes.
     *
     * As seções nascem juntas, e não conforme a construtora abre cada aba: uma
     * seção que só existisse depois de visitada tornaria "não existe" e "não foi
     * revisada" o mesmo estado, e a submissão não teria como dizer o que falta.
     */
    private function createReview(
        SalesBoardCycle $cycle,
        SalesBoardCycleBaseline $baseline,
        ?User $actor,
    ): SalesBoardBuilderReview {
        $now = CarbonImmutable::now();

        $review = SalesBoardBuilderReview::query()->create([
            'sales_board_cycle_id' => $cycle->getKey(),
            'sales_board_cycle_baseline_id' => $baseline->getKey(),
            'attempt' => $this->nextAttempt($cycle),
            'status' => SalesBoardBuilderReviewStatus::Draft,
            'snapshot_fingerprint' => $baseline->snapshot_fingerprint,
            'opened_at' => $now,
            'opened_by_user_id' => $actor?->getKey(),
        ]);

        $sections = array_map(
            fn (SectionEnum $section): array => [
                'sales_board_builder_review_id' => $review->getKey(),
                'section' => $section->value,
                'status' => SalesBoardBuilderReviewSectionStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            SectionEnum::ordered(),
        );

        SalesBoardBuilderReviewSection::query()->insert($sections);

        return $review->refresh();
    }

    private function nextAttempt(SalesBoardCycle $cycle): int
    {
        return ((int) SalesBoardBuilderReview::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->max('attempt')) + 1;
    }
}

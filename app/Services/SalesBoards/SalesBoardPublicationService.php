<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardStaleAssessment;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Support\SalesBoards\SalesBoardWriteContext;
use Carbon\CarbonImmutable;

/**
 * A única porta de escrita automática em `sales_boards`.
 *
 * Uma porta só, e estreita. O quadro legado continua aceitando posição digitada
 * à mão pela tela de sempre; o que não pode existir é um segundo caminho
 * automático, porque a garantia de que "tudo o que foi publicado passou pela
 * governança" vale exatamente enquanto houver um lugar por onde a publicação
 * passa.
 *
 * Não recalcula nada. O `payload` vem da projeção, que vem do baseline aprovado.
 *
 * Quem chama é responsável pela transação: quadro, publicação e transições da
 * análise e do ciclo são um ato só. Um quadro publicado sem a publicação ao lado
 * seria uma posição sem procedência -- indistinguível de uma digitada à mão.
 */
class SalesBoardPublicationService
{
    public function __construct(
        private readonly SalesBoardPublicationProjection $projection,
        private readonly SalesBoardWriteContext $writeContext,
    ) {}

    /**
     * Já existe posição registrada para o empreendimento nesta competência?
     *
     * A busca é por empreendimento e competência, e não pela chave completa da
     * tabela -- que inclui a emissão. É de propósito: o
     * {@see SalesBoardPositionReader} lê a posição de um empreendimento sem
     * filtrar por emissão, então um quadro registrado sob outra emissão seria
     * lido do mesmo jeito. Publicar ao lado dele criaria duas posições para o
     * mesmo empreendimento no mesmo mês, e o desempate por id decidiria em
     * silêncio qual das duas o relatório e as garantias enxergariam.
     */
    public function existingPosition(SalesBoardCycle $cycle): ?SalesBoard
    {
        return SalesBoard::query()
            ->where('construction_id', $cycle->construction_id)
            ->whereDate('reference_month', $cycle->reference_month->toDateString())
            ->orderBy('id')
            ->first();
    }

    /**
     * Cria o quadro publicado e o registro que o liga à cadeia de decisão.
     *
     * @param  SalesBoardStaleAssessment  $assessment  a observação da fonte feita
     *                                                 no instante da aprovação,
     *                                                 dentro da mesma transação
     */
    public function publish(
        SalesBoardCycle $cycle,
        SalesBoardCycleBaseline $baseline,
        SalesBoardManagementReview $review,
        SalesBoardStaleAssessment $assessment,
        ?string $sourceChangeReason,
        ?User $actor,
    ): SalesBoardPublication {
        $existing = $this->existingPosition($cycle);

        if ($existing instanceof SalesBoard) {
            throw SalesBoardManagementReviewException::legacyPositionExists(
                (string) ($cycle->construction?->development_name ?? '—'),
                $cycle->reference_month->format('m/Y'),
            );
        }

        $payload = $this->projection->project($cycle, $baseline);

        /**
         * `create()` -- não `insert()` nem `saveQuietly()`. O observer do
         * `SalesBoard` transforma toda criação numa primeira versão do histórico
         * de posições, e esse comportamento é o correto aqui: uma posição
         * publicada precisa nascer com a mesma trilha de versões que uma
         * digitada. Silenciar os eventos produziria um quadro sem versão
         * inicial, que é um estado que nenhuma outra parte do sistema conhece.
         *
         * O contexto de publicação é aberto em volta da criação, e é a única
         * coisa no sistema que o abre. É por ele que o guard de escrita
         * distingue esta escrita -- legítima, no modo automatizado -- do
         * registro manual da mesma competência, sem precisar desligar o observer
         * nem olhar de onde veio a requisição.
         */
        $salesBoard = $this->writeContext->asPublication(
            fn (): SalesBoard => SalesBoard::query()->create($payload->toSalesBoardAttributes()),
        );

        return SalesBoardPublication::query()->create([
            'sales_board_cycle_id' => $cycle->getKey(),
            'sales_board_cycle_baseline_id' => $baseline->getKey(),
            'sales_board_builder_review_id' => $review->sales_board_builder_review_id,
            'sales_board_management_review_id' => $review->getKey(),
            'sales_board_id' => $salesBoard->getKey(),
            'snapshot_fingerprint' => $baseline->snapshot_fingerprint,
            'source_fingerprint' => $baseline->source_fingerprint,
            'observed_source_fingerprint' => $assessment->observedSourceFingerprint,
            'source_changed' => $assessment->sourceChanged,
            'source_change_reason' => $sourceChangeReason,
            'published_by_user_id' => $actor?->getKey(),
            'published_at' => CarbonImmutable::now(),
        ]);
    }
}

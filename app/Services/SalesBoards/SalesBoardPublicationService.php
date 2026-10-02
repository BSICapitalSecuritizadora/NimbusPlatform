<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardStaleAssessment;
use App\Exceptions\SalesBoardManagementReviewException;
use App\Models\Construction;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardManagementReview;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Support\SalesBoards\SalesBoardWriteContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

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
 * Duas escritas, e só elas: a publicação, que cria o quadro da competência, e a
 * republicação ({@see self::republish()}), que atualiza esse mesmo quadro
 * quando a Gestão aprova a retificação da competência. Nenhuma das duas
 * reescreve publicação: cada aprovação cria a sua, encadeada à anterior.
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
     *
     * O mês é procurado inteiro, como no guard de escrita: uma carga feita por
     * fora do model pode ter gravado o quadro com outro dia, e o leitor o trata
     * como o mesmo mês. Procurar só o dia 01 deixaria o conflito para o guard,
     * que recusaria com a mensagem do registro manual no meio da aprovação.
     */
    public function existingPosition(SalesBoardCycle $cycle): ?SalesBoard
    {
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();

        return SalesBoard::query()
            ->where('construction_id', $cycle->construction_id)
            ->whereDate('reference_month', '>=', $month->toDateString())
            ->whereDate('reference_month', '<=', $month->endOfMonth()->toDateString())
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

        $this->assertConstructionStillInCycleEmission($cycle);

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
            'sequence_number' => 1,
            'snapshot_fingerprint' => $baseline->snapshot_fingerprint,
            'source_fingerprint' => $baseline->source_fingerprint,
            'observed_source_fingerprint' => $assessment->observedSourceFingerprint,
            'source_changed' => $assessment->sourceChanged,
            'source_change_reason' => $sourceChangeReason,
            'published_by_user_id' => $actor?->getKey(),
            'published_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Publica de novo a competência retificada: atualiza o quadro já publicado
     * e cria a publicação encadeada.
     *
     * O quadro é o mesmo -- a unique da Emissão, da obra e do mês não admite
     * outro, e o leitor desempataria por id. A atualização passa pelo observer
     * de sempre: o histórico de versões grava a mudança com o motivo da
     * retificação e em nome de quem aprovou, e o invalidador de garantias marca
     * as competências de garantias que ela mexe. O contexto de republicação vale
     * só para este quadro e só no update.
     *
     * A pré-checagem é a mesma da publicação: o empreendimento precisa continuar
     * na Emissão do ciclo. E o quadro da competência precisa continuar sendo o
     * da publicação vigente -- outro quadro na mesma obra e mês seria a posição
     * que o leitor enxerga, e a republicação estaria escrevendo no lugar errado.
     */
    public function republish(
        SalesBoardCycle $cycle,
        SalesBoardCycleBaseline $baseline,
        SalesBoardManagementReview $review,
        SalesBoardStaleAssessment $assessment,
        ?string $sourceChangeReason,
        User $actor,
        SalesBoardCycleRectification $rectification,
    ): SalesBoardPublication {
        $this->assertConstructionStillInCycleEmission($cycle);

        $previous = SalesBoardPublication::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->orderByDesc('sequence_number')
            ->sharedLock()
            ->firstOrFail();

        $salesBoard = SalesBoard::query()
            ->whereKey($previous->sales_board_id)
            ->lockForUpdate()
            ->firstOrFail();

        $existing = $this->existingPosition($cycle);

        if ($existing instanceof SalesBoard && ((int) $existing->getKey() !== (int) $salesBoard->getKey())) {
            throw SalesBoardManagementReviewException::legacyPositionExists(
                (string) ($cycle->construction?->development_name ?? '—'),
                $cycle->reference_month->format('m/Y'),
            );
        }

        $payload = $this->projection->project($cycle, $baseline);

        $salesBoard->fill(Arr::except($payload->toSalesBoardAttributes(), ['emission_id', 'construction_id', 'reference_month']));
        $salesBoard->changeReason = sprintf(
            'Retificação aprovada da competência %s: %s',
            $cycle->reference_month->format('m/Y'),
            (string) $rectification->reason,
        );
        $salesBoard->changedById = (int) $actor->getKey();

        $this->writeContext->asRepublication(
            (int) $salesBoard->getKey(),
            fn (): bool => $salesBoard->save(),
        );

        return SalesBoardPublication::query()->create([
            'sales_board_cycle_id' => $cycle->getKey(),
            'sales_board_cycle_baseline_id' => $baseline->getKey(),
            'sales_board_builder_review_id' => $review->sales_board_builder_review_id,
            'sales_board_management_review_id' => $review->getKey(),
            'sales_board_id' => $salesBoard->getKey(),
            'sequence_number' => ((int) $previous->sequence_number) + 1,
            'supersedes_publication_id' => $previous->getKey(),
            'sales_board_cycle_rectification_id' => $rectification->getKey(),
            'snapshot_fingerprint' => $baseline->snapshot_fingerprint,
            'source_fingerprint' => $baseline->source_fingerprint,
            'observed_source_fingerprint' => $assessment->observedSourceFingerprint,
            'source_changed' => $assessment->sourceChanged,
            'source_change_reason' => $sourceChangeReason,
            'published_by_user_id' => $actor->getKey(),
            'published_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * O empreendimento continua na Emissão do ciclo?
     *
     * O ciclo guarda a Emissão do dia em que foi gerado. Se o empreendimento
     * passou para outra Emissão desde então -- por carga ou correção feita por
     * fora das telas, que travam a troca --, o quadro publicado ficaria fora da
     * Emissão do empreendimento e seria somado nas duas. O guard de escrita
     * recusaria do mesmo jeito, mas com a mensagem do registro manual; aqui a
     * recusa sai no vocabulário da Gestão, e o workspace desfaz a aprovação.
     *
     * A leitura é compartilhada, dentro da transação de quem aprova: uma troca
     * de Emissão em andamento termina antes desta conferência.
     */
    private function assertConstructionStillInCycleEmission(SalesBoardCycle $cycle): void
    {
        $constructionEmissionId = Construction::query()
            ->whereKey($cycle->construction_id)
            ->sharedLock()
            ->value('emission_id');

        if ((int) $constructionEmissionId === (int) $cycle->emission_id) {
            return;
        }

        throw SalesBoardManagementReviewException::constructionOutsideCycleEmission(
            (string) ($cycle->construction?->development_name ?? '—'),
            $cycle->reference_month->format('m/Y'),
        );
    }
}

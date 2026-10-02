<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardChainStructure;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardMovementTiming;
use App\Events\SalesBoards\SalesBoardPriorPositionChanged;
use App\Exceptions\SalesBoardCycleReopeningException;
use App\Models\Construction;
use App\Models\Emission;
use App\Models\SalesBoard;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleMovement;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Support\Dates\InclusiveDateBound;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Reabrir competência": desfaz um cancelamento, devolvendo o MESMO ciclo a
 * "Gerado".
 *
 * O cancelamento continua sendo o que a Gestão decidiu -- só para ciclo não
 * aprovado, com motivo, autor e trilha --, mas deixou de ser sem volta: um
 * cancelamento por engano no piloto deixava a competência sem quadro para
 * sempre, e os movimentos daquele mês nunca eram publicados. Reabrir é um ato
 * posterior, da Gestão (`sales-boards.approve`), com motivo.
 *
 * Reabre-se o mesmo ciclo, e não um novo, de propósito. A identidade
 * (empreendimento + competência) continua sendo uma só -- a unique de
 * `sales_board_cycles` fica intacta, e nenhum leitor que supõe um ciclo por
 * competência (a geração, o processador da automação, a avaliação do rollout, o
 * conflito da publicação, os alvos) precisa aprender o que é "ciclo ativo". As
 * versões apuradas e as rodadas substituídas continuam no histórico.
 *
 * Não há maker/checker aqui: reabrir não publica nada. Daí em diante vale o
 * fluxo normal -- recálculo com motivo se a fonte mudou, nova rodada da
 * construtora, análise e aprovação, onde a segregação continua valendo.
 *
 * Recusas, sob o lock do ciclo:
 *
 * - o ciclo não está cancelado;
 * - o empreendimento mudou de Emissão desde a geração;
 * - a Emissão está em elaboração;
 * - a competência não é mais coberta pela automação da Emissão;
 * - já existe Quadro de Vendas para o empreendimento na competência, sob
 *   qualquer Emissão -- o leitor de posição consulta sem a Emissão;
 * - uma competência posterior do empreendimento já foi publicada: a posição
 *   dela já reflete os fatos do mês cancelado (e, quando ela absorveu o mês, os
 *   movimentos dele também), e reabrir publicaria os mesmos fatos duas vezes.
 *
 * Os ciclos posteriores do empreendimento são travados em modo compartilhado,
 * pela chave primária, **antes** do lock deste, do mês mais recente para o mais
 * antigo -- a ordem de locks do Quadro
 * ({@see PublishedCompetenceBoundary::lockCyclesAfter()}). É o que serializa a
 * reabertura com a aprovação de qualquer competência posterior, que trava o
 * próprio ciclo com FOR UPDATE antes de tudo: ou a reabertura espera a
 * aprovação terminar e encontra a posterior publicada, ou a aprovação espera a
 * reabertura. Vale para M+1, para M+2 quando M+1 também está cancelada (e é M+2
 * que absorve os fatos desta) e para a posterior separada desta por um mês sem
 * ciclo, cujo portão de ordem para nesse mês e nem lê esta competência. Quando
 * é a aprovação que espera, a regra de ordem a segura se a cadeia dela chega a
 * esta competência, agora aberta; com o mês sem ciclo no meio a cadeia não
 * chega, a posterior é publicada, e esta deixa de poder ser publicada -- a
 * aprovação recusa competência com posterior publicada, e a saída é cancelá-la
 * de novo. As leituras até o lock deste ciclo são todas travadas, então o
 * instantâneo da transação nasce depois das esperas e a conferência da
 * posterior publicada, uma leitura comum, já enxerga o que a aprovação gravou.
 *
 * Depois do commit sai {@see SalesBoardPriorPositionChanged}: a competência
 * seguinte não cancelada, que absorvia os fatos desta enquanto ela estava
 * cancelada, volta a ancorar nela, e a verificação dela é antecipada. Se a
 * seguinte só foi gerada, fica "Alterações materiais" pela cadeia -- e pelo
 * conteúdo, se o mês teve fato --, mesmo quando nenhum número dela muda: a
 * janela, os avisos e a ponte dela partiam da âncora antiga
 * ({@see SalesBoardChainStructure}). A regra de ordem da aprovação a segura
 * até esta ser aprovada ou cancelada de novo.
 */
class SalesBoardCycleReopeningService
{
    public function __construct(
        private readonly SalesBoardAutomationTargetClosureService $targetClosureService,
        private readonly SalesBoardPublicationService $publicationService,
        private readonly SalesBoardStaleDetectionService $staleDetectionService,
    ) {}

    public function reopen(SalesBoardCycle $cycle, ?User $actor, string $reason): SalesBoardCycle
    {
        if ($actor === null) {
            throw SalesBoardCycleReopeningException::actorRequired();
        }

        SalesBoardApprovalAuthority::authorize($actor);

        $reason = $this->normalizeReason($reason);

        $reopened = DB::transaction(function () use ($cycle, $actor, $reason): SalesBoardCycle {
            /**
             * Os ciclos posteriores primeiro, em modo compartilhado pela chave
             * primária e do mais recente para o mais antigo; depois este
             * ciclo, e os alvos por último -- a mesma ordem do cancelamento,
             * para cancelar e reabrir a mesma competência serializarem. A
             * Emissão é lida em seguida em modo compartilhado, como o guard de
             * escrita a lê dentro da publicação -- um retorno ao legado em
             * andamento termina antes, e a cobertura é conferida já com o modo
             * novo.
             */
            PublishedCompetenceBoundary::lockCyclesAfter((int) $cycle->construction_id, $cycle->reference_month);

            $locked = SalesBoardCycle::query()
                ->whereKey($cycle->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== SalesBoardCycleStatus::Cancelled) {
                throw SalesBoardCycleReopeningException::notReopenable($locked->status);
            }

            $construction = Construction::query()->find($locked->construction_id);

            if (! $construction instanceof Construction || (int) $construction->emission_id !== (int) $locked->emission_id) {
                throw SalesBoardCycleReopeningException::constructionChangedEmission();
            }

            $emission = Emission::query()->whereKey($locked->emission_id)->sharedLock()->firstOrFail();
            $referenceMonth = $locked->reference_month->format('m/Y');

            if ($emission->isInDraft()) {
                throw SalesBoardCycleReopeningException::emissionInDraft();
            }

            if (! $emission->automationCovers($locked->reference_month)) {
                throw SalesBoardCycleReopeningException::competenceNotCovered($referenceMonth, (string) $emission->name);
            }

            if ($this->publicationService->existingPosition($locked) instanceof SalesBoard) {
                throw SalesBoardCycleReopeningException::positionAlreadyRegistered(
                    (string) ($construction->development_name ?? '—'),
                    $referenceMonth,
                );
            }

            $laterPublished = $this->laterPublishedMonth($locked);

            if ($laterPublished !== null) {
                throw $this->laterPublicationRefusal($locked, $laterPublished);
            }

            $locked->forceFill([
                'status' => SalesBoardCycleStatus::Generated,
                'reopened_at' => CarbonImmutable::now(),
                'reopened_by_user_id' => $actor->getKey(),
                'reopen_reason' => $reason,
            ])->save();

            $reopenedTargets = $this->targetClosureService->reopenForReopenedCycle($locked);

            SalesBoardPriorPositionChanged::dispatch(
                (int) $locked->construction_id,
                CarbonImmutable::parse($locked->reference_month->toDateString())->startOfMonth(),
                SalesBoardPriorPositionChanged::COMPETENCE_REOPENED,
            );

            Log::info('Sales board competence reopened', [
                'event' => 'sales_board_competence_reopened',
                'cycle_id' => (int) $locked->getKey(),
                'construction_id' => (int) $locked->construction_id,
                'reference_month' => $locked->reference_month->format('Y-m'),
                'actor_user_id' => (int) $actor->getKey(),
                'reopened_automation_targets' => $reopenedTargets,
            ]);

            return $locked;
        });

        $this->checkSourceAfterReopening($reopened);

        return $reopened->refresh();
    }

    /**
     * A competência publicada mais recente do empreendimento, quando ela é
     * posterior a esta -- ou `null`.
     *
     * "Publicada" é ter publicação, e não o status Aprovado: a pergunta é a da
     * fronteira única ({@see PublishedCompetenceBoundary}), a mesma da permuta e
     * da baixa. Leitura simples: os ciclos posteriores já estão travados em
     * modo compartilhado, e o instantâneo nasceu depois dessas esperas. Uma
     * leitura travada aqui, depois do lock deste ciclo, travaria também as
     * publicações e as competências anteriores fora da ordem do Quadro.
     */
    private function laterPublishedMonth(SalesBoardCycle $cycle): ?CarbonImmutable
    {
        $cycleMonth = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();
        $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth((int) $cycle->construction_id);

        return ($lastPublished !== null) && $lastPublished->greaterThan($cycleMonth) ? $lastPublished : null;
    }

    /**
     * A recusa que diz o que a competência posterior fez com os fatos desta.
     *
     * Só se diz que a posterior "absorveu os fatos" quando a publicação vigente
     * dela tem movimentos "de competência sem posição" datados neste mês -- é
     * o que absorver significa. Sem eles (a posterior foi apurada antes de esta
     * ser cancelada, ou o mês não teve fato), o que é verdade é outra coisa: a
     * posição publicada da posterior já reflete os fatos deste mês, e a correção
     * de uma posição publicada é a retificação da última competência publicada.
     */
    private function laterPublicationRefusal(SalesBoardCycle $cycle, CarbonImmutable $lastPublished): SalesBoardCycleReopeningException
    {
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();
        $absorbedBy = $this->absorbingPublicationMonth($cycle, $month);

        return $absorbedBy !== null
            ? SalesBoardCycleReopeningException::laterCompetencePublished($month->format('m/Y'), $absorbedBy->format('m/Y'))
            : SalesBoardCycleReopeningException::laterPositionReflectsFacts($month->format('m/Y'), $lastPublished->format('m/Y'));
    }

    /**
     * A competência posterior cuja publicação vigente absorveu este mês: a que
     * tem movimento "de competência sem posição" com o fato datado nele.
     */
    private function absorbingPublicationMonth(SalesBoardCycle $cycle, CarbonImmutable $month): ?CarbonImmutable
    {
        $cycles = (new SalesBoardCycle)->getTable();
        $publications = (new SalesBoardPublication)->getTable();
        $movements = (new SalesBoardCycleMovement)->getTable();

        $referenceMonth = DB::table($publications.' as publications')
            ->join($cycles.' as cycles', 'cycles.id', '=', 'publications.sales_board_cycle_id')
            ->join($movements.' as movements', 'movements.sales_board_cycle_baseline_id', '=', 'publications.sales_board_cycle_baseline_id')
            ->where('cycles.construction_id', $cycle->construction_id)
            ->where('cycles.reference_month', '>', InclusiveDateBound::upperBound($month->endOfMonth()))
            ->where('publications.sequence_number', '=', fn (QueryBuilder $latest): QueryBuilder => $latest
                ->from($publications.' as latest')
                ->selectRaw('max(latest.sequence_number)')
                ->whereColumn('latest.sales_board_cycle_id', 'publications.sales_board_cycle_id'))
            ->where('movements.timing', SalesBoardMovementTiming::WithoutPosition->value)
            ->whereBetween('movements.event_date', [$month->toDateString(), InclusiveDateBound::upperBound($month->endOfMonth())])
            ->orderBy('cycles.reference_month')
            ->value('cycles.reference_month');

        return $referenceMonth === null ? null : CarbonImmutable::parse(substr((string) $referenceMonth, 0, 10))->startOfMonth();
    }

    /**
     * Confere a fonte depois do commit, para a tela já dizer se é preciso
     * recalcular -- uma competência reaberta semanas depois quase sempre teve a
     * fonte alterada nesse meio-tempo.
     *
     * Melhor esforço: a reabertura já está gravada, e uma falha aqui não pode
     * desfazê-la. Se a verificação falhar, "Verificar alterações" resolve.
     */
    private function checkSourceAfterReopening(SalesBoardCycle $cycle): void
    {
        try {
            $fresh = $cycle->fresh();

            if ($fresh instanceof SalesBoardCycle && $fresh->current_baseline_id !== null) {
                $this->staleDetectionService->check($fresh);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH) {
            throw SalesBoardCycleReopeningException::reasonRequired(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH);
        }

        return mb_substr($reason, 0, SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH);
    }
}

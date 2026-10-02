<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRectificationStatus;
use App\Events\SalesBoards\SalesBoardCurrentBaselineChanged;
use App\Exceptions\SalesBoardRectificationException;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\SalesBoardCycleRectification;
use App\Models\SalesBoardPublication;
use App\Models\User;
use App\Support\SalesBoards\PublishedCompetenceBoundary;
use App\Support\SalesBoards\SalesBoardApprovalAuthority;
use App\Support\SalesBoards\SalesBoardFrozenWarnings;
use App\Support\SalesBoards\SalesBoardIssuePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Retificar competência": corrige a posição publicada da última competência
 * publicada do empreendimento, pelo mesmo fluxo de sempre.
 *
 * Abrir grava uma versão nova (V n+1) com o motivo, devolve o ciclo a "Gerado"
 * e cria a retificação aberta. Daí em diante é o fluxo normal -- enviar para a
 * validação da construtora, análise da Gestão, aprovação --, com recálculo se a
 * fonte mudar de novo. A aprovação publica de novo o mesmo quadro
 * ({@see SalesBoardPublicationService::republish()}) e encadeia a publicação.
 * Enquanto isso Quadro, leitor, garantias, relatório e a âncora da competência
 * seguinte continuam lendo a publicação vigente.
 *
 * Regras:
 *
 * - só a **última competência publicada** do empreendimento -- uma mais antiga
 *   se corrige para frente, pelos extemporâneos da seguinte, sem cascata nas
 *   âncoras e nas publicações posteriores;
 * - ciclo aprovado e sem retificação aberta;
 * - a autoridade é a da Gestão (`sales-boards.approve`), com motivo de 10 a
 *   2000 caracteres; quem abre não aprova a publicação dela (maker/checker em
 *   {@see SalesBoardApprovalAuthority});
 * - só abre se há o que retificar: fonte pronta e posição apurada diferente da
 *   publicada.
 *
 * "Desistir da retificação" substitui as rodadas abertas, devolve o ponteiro
 * para a versão publicada e o ciclo a "Aprovado". A posição publicada nunca
 * mudou, e as versões e rodadas da retificação ficam no histórico. Depois do
 * commit, a fonte é conferida de novo na versão publicada restaurada.
 *
 * Ordem de locks do Quadro: o ciclo é travado antes de qualquer outra leitura,
 * e a pergunta "outra competência foi publicada depois?" é uma leitura comum,
 * feita depois do lock. A aprovação da competência seguinte trava a dela e lê
 * esta com `sharedLock()`: ou ela termina antes e a publicação dela aparece
 * aqui, ou espera este lock e encontra esta competência em retificação.
 */
class SalesBoardCycleRectificationService
{
    public const SUPERSEDED_REASON = 'retificacao_desistida';

    public function __construct(
        private readonly SalesBoardDerivationService $derivationService,
        private readonly SalesBoardFingerprintService $fingerprintService,
        private readonly SalesBoardReadinessService $readinessService,
        private readonly SalesBoardBaselineWriter $baselineWriter,
        private readonly SalesBoardOpenReviewsSuperseder $openReviewsSuperseder,
        private readonly SalesBoardStaleDetectionService $staleDetectionService,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws SalesBoardRectificationException
     */
    public function open(SalesBoardCycle $cycle, ?User $actor, string $reason): SalesBoardCycleRectification
    {
        if ($actor === null) {
            throw SalesBoardRectificationException::actorRequired();
        }

        SalesBoardApprovalAuthority::authorize($actor);

        $reason = $this->normalizeReason($reason);

        $rectification = DB::transaction(function () use ($cycle, $actor, $reason): SalesBoardCycleRectification {
            $locked = SalesBoardCycle::query()
                ->whereKey($cycle->getKey())
                ->lockForUpdate()
                ->with('construction')
                ->firstOrFail();

            $month = CarbonImmutable::parse($locked->reference_month->toDateString())->startOfMonth();
            $monthLabel = $month->format('m/Y');

            $alreadyOpen = SalesBoardCycleRectification::query()
                ->where('sales_board_cycle_id', $locked->getKey())
                ->where('status', SalesBoardRectificationStatus::Open->value)
                ->lockForUpdate()
                ->exists();

            if ($alreadyOpen) {
                throw SalesBoardRectificationException::alreadyOpen($monthLabel);
            }

            if ($locked->status !== SalesBoardCycleStatus::Approved) {
                throw SalesBoardRectificationException::notApproved($locked->status);
            }

            $lastPublished = PublishedCompetenceBoundary::lastPublishedMonth((int) $locked->construction_id);

            if (($lastPublished !== null) && $lastPublished->greaterThan($month)) {
                throw SalesBoardRectificationException::notLastPublished($monthLabel, $lastPublished->format('m/Y'));
            }

            $publication = SalesBoardPublication::query()
                ->where('sales_board_cycle_id', $locked->getKey())
                ->orderByDesc('sequence_number')
                ->first();

            if (! $publication instanceof SalesBoardPublication) {
                throw SalesBoardRectificationException::notApproved($locked->status);
            }

            $construction = $locked->construction;
            $position = $this->derivationService->deriveForConstruction($construction, $month);
            $observation = $this->fingerprintService->observeForConstruction($construction, $month);
            $readiness = $this->readinessService->fromPosition($construction, $position);

            if (! $readiness->isReady()) {
                throw SalesBoardRectificationException::sourceIncomplete(array_map(
                    fn (array $issue): string => sprintf('%s (%s · %d)', $issue['label'], $issue['code'], (int) $issue['count']),
                    SalesBoardIssuePresenter::describe($readiness->blockingIssueCounts()),
                ));
            }

            $live = SalesBoardComparableSnapshot::fromDerived($position, $observation);
            $sourceFingerprint = $observation->fingerprint();

            if ($live->snapshot->fingerprint() === (string) $publication->snapshot_fingerprint) {
                throw SalesBoardRectificationException::nothingToRectify(
                    $monthLabel,
                    $sourceFingerprint !== (string) $publication->source_fingerprint,
                );
            }

            $previous = SalesBoardCycleBaseline::query()->find($locked->current_baseline_id);

            $baseline = $this->baselineWriter->write(
                cycle: $locked,
                version: ((int) SalesBoardCycleBaseline::query()->where('sales_board_cycle_id', $locked->getKey())->max('version')) + 1,
                comparable: $live,
                sourceFingerprint: $sourceFingerprint,
                actor: $actor,
                reason: 'Retificação: '.$reason,
                frozenWarnings: SalesBoardFrozenWarnings::fromPosition($position),
            );

            $locked->forceFill([
                'current_baseline_id' => $baseline->getKey(),
                'status' => SalesBoardCycleStatus::Generated,
            ])->save();

            $rectification = SalesBoardCycleRectification::query()->create([
                'sales_board_cycle_id' => $locked->getKey(),
                'sequence_number' => ((int) SalesBoardCycleRectification::query()->where('sales_board_cycle_id', $locked->getKey())->max('sequence_number')) + 1,
                'status' => SalesBoardRectificationStatus::Open,
                'rectified_publication_id' => $publication->getKey(),
                'opening_baseline_id' => $baseline->getKey(),
                'reason' => $reason,
                'requested_by_user_id' => $actor->getKey(),
                'requested_at' => CarbonImmutable::now(),
            ]);

            /**
             * A versão vigente mudou: as rodadas abertas que não falam dela são
             * substituídas pelos ouvintes de sempre, depois do commit. A
             * validação que sustenta a publicação vigente é rodada encerrada e
             * fica como está.
             */
            if ($previous instanceof SalesBoardCycleBaseline) {
                DB::afterCommit(static function () use ($locked, $previous, $baseline): void {
                    SalesBoardCurrentBaselineChanged::dispatch($locked, $previous, $baseline);
                });
            }

            Log::info('Sales board competence rectification opened', [
                'event' => 'sales_board_rectification_opened',
                'cycle_id' => (int) $locked->getKey(),
                'construction_id' => (int) $locked->construction_id,
                'reference_month' => $month->format('Y-m'),
                'rectification_id' => (int) $rectification->getKey(),
                'baseline_id' => (int) $baseline->getKey(),
                'actor_user_id' => (int) $actor->getKey(),
            ]);

            return $rectification;
        });

        return $rectification->refresh();
    }

    /**
     * @throws AuthorizationException
     * @throws SalesBoardRectificationException
     */
    public function abandon(SalesBoardCycleRectification $rectification, ?User $actor, string $reason): SalesBoardCycleRectification
    {
        if ($actor === null) {
            throw SalesBoardRectificationException::actorRequired();
        }

        SalesBoardApprovalAuthority::authorize($actor);

        $reason = $this->normalizeReason($reason);

        $abandoned = DB::transaction(function () use ($rectification, $actor, $reason): SalesBoardCycleRectification {
            $locked = SalesBoardCycle::query()
                ->whereKey($rectification->sales_board_cycle_id)
                ->lockForUpdate()
                ->firstOrFail();

            $open = SalesBoardCycleRectification::query()
                ->whereKey($rectification->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $open->isOpen()) {
                throw SalesBoardRectificationException::notOpen();
            }

            $now = CarbonImmutable::now();
            $superseded = $this->openReviewsSuperseder->supersede($locked, self::SUPERSEDED_REASON, $now);

            $publishedBaselineId = (int) SalesBoardPublication::query()
                ->whereKey($open->rectified_publication_id)
                ->value('sales_board_cycle_baseline_id');

            $locked->forceFill([
                'current_baseline_id' => $publishedBaselineId,
                'status' => SalesBoardCycleStatus::Approved,
            ])->save();

            $open->forceFill([
                'status' => SalesBoardRectificationStatus::Abandoned,
                'closed_at' => $now,
                'closed_by_user_id' => $actor->getKey(),
                'closing_reason' => $reason,
            ])->save();

            Log::info('Sales board competence rectification abandoned', [
                'event' => 'sales_board_rectification_abandoned',
                'cycle_id' => (int) $locked->getKey(),
                'construction_id' => (int) $locked->construction_id,
                'reference_month' => $locked->reference_month?->format('Y-m'),
                'rectification_id' => (int) $open->getKey(),
                'actor_user_id' => (int) $actor->getKey(),
                'superseded_builder_reviews' => $superseded['builder'],
                'superseded_management_reviews' => $superseded['management'],
            ]);

            return $open->refresh();
        });

        $this->checkSourceAfterAbandoning((int) $abandoned->sales_board_cycle_id);

        return $abandoned;
    }

    /**
     * Confere a fonte na versão publicada restaurada, depois do commit.
     *
     * A constatação gravada nela é de antes da retificação, e quase sempre a
     * fonte mudou desde então -- foi o que motivou abri-la. Sem conferir, a tela
     * voltaria a dizer "Sem alterações" sobre uma posição que já não confere com
     * a fonte. Como na reabertura, é melhor esforço: a desistência já está
     * gravada e uma falha aqui não pode desfazê-la; "Verificar alterações"
     * resolve.
     */
    private function checkSourceAfterAbandoning(int $cycleId): void
    {
        try {
            $cycle = SalesBoardCycle::query()->find($cycleId);

            if ($cycle instanceof SalesBoardCycle && $cycle->current_baseline_id !== null) {
                $this->staleDetectionService->check($cycle);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = trim(strip_tags((string) $reason));

        if (mb_strlen($reason) < SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH) {
            throw SalesBoardRectificationException::reasonRequired(SalesBoardManagementDecisionService::MINIMUM_REASON_LENGTH);
        }

        return mb_substr($reason, 0, SalesBoardManagementDecisionService::MAXIMUM_REASON_LENGTH);
    }
}

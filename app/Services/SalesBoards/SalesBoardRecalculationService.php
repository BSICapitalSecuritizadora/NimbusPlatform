<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\DTOs\SalesBoards\SalesBoardRecalculationResult;
use App\Enums\SalesBoardCycleStatus;
use App\Enums\SalesBoardRecalculationOutcome;
use App\Enums\SalesBoardStaleImpact;
use App\Events\SalesBoards\SalesBoardCurrentBaselineChanged;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cria a próxima versão de um ciclo a partir da fonte atual.
 *
 * Explícito, sempre. Nenhum caminho automático chega aqui: descobrir que a fonte
 * mudou não recalcula nada, e é isso que impede o passado de ser reescrito em
 * silêncio toda vez que alguém corrige um contrato.
 *
 * A versão anterior nunca é tocada. V2 nasce ao lado da V1, e o ponteiro do
 * ciclo passa a apontar para a nova -- as duas continuam consultáveis e
 * comparáveis para sempre. "Corrigir" apagando seria destruir a evidência de que
 * houve o que corrigir.
 *
 * Três recusas:
 *
 * - **sem motivo, não recalcula.** Uma versão financeira nova sem justificativa
 *   é uma mudança que ninguém consegue explicar seis meses depois;
 * - **sem prontidão, não recalcula.** Se a fonte atual está incompleta, não
 *   existe versão melhor a criar -- e a anterior continua sendo o melhor
 *   registro disponível;
 * - **sem mudança, não recalcula.** Fingerprints idênticos devolvem no-op. Uma
 *   V2 idêntica à V1 só polui o histórico e faz a próxima pessoa procurar uma
 *   diferença que não existe.
 *
 * Mudança apenas na fonte, sem impacto na posição, **cria** versão nova: a nova
 * versão passa a representar a fonte material atual, e a anterior preserva a
 * anterior. O diff mostra que o resultado ficou igual e a origem não.
 */
class SalesBoardRecalculationService
{
    public function __construct(
        private readonly SalesBoardDerivationService $derivationService,
        private readonly SalesBoardReadinessService $readinessService,
        private readonly SalesBoardFingerprintService $fingerprintService,
        private readonly SalesBoardBaselineDiffService $diffService,
        private readonly SalesBoardBaselineWriter $baselineWriter,
    ) {}

    /**
     * @param  int|null  $expectedBaselineId  versão que quem pediu o recálculo
     *                                        tinha à vista; se outra pessoa já
     *                                        criou uma mais nova, o recálculo é
     *                                        recusado em vez de sobrescrever o
     *                                        ponteiro dela
     */
    public function recalculate(
        SalesBoardCycle $cycle,
        ?User $actor,
        string $reason,
        ?int $expectedBaselineId = null,
    ): SalesBoardRecalculationResult {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('O recálculo exige um motivo.');
        }

        return DB::transaction(function () use ($cycle, $actor, $reason, $expectedBaselineId): SalesBoardRecalculationResult {
            /**
             * O lock do ciclo é o que serializa dois recálculos simultâneos:
             * enquanto ele está seguro, ninguém mais lê a versão vigente nem
             * decide qual é o próximo número. A unique de `(ciclo, versão)`
             * continua atrás, como última defesa que não depende deste código
             * estar certo.
             */
            $locked = SalesBoardCycle::query()
                ->whereKey($cycle->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /**
             * Um ciclo encerrado não recebe versão nova.
             *
             * Depois de aprovado existe um Quadro de Vendas publicado a partir
             * de uma versão específica, e criar a seguinte faria a posição
             * publicada deixar de corresponder à versão vigente do ciclo -- sem
             * que nada no banco denunciasse a diferença. Cancelado é o mesmo
             * caso pelo motivo oposto: a competência foi encerrada sem posição,
             * e uma versão nova ressuscitaria um ciclo que ninguém pretende
             * seguir. A tela já esconde o botão; esta é a garantia que não
             * depende disso.
             */
            if (in_array($locked->status, [SalesBoardCycleStatus::Approved, SalesBoardCycleStatus::Cancelled], true)) {
                throw new RuntimeException(sprintf(
                    'A competência está em "%s" e não admite nova versão.',
                    $locked->status->label(),
                ));
            }

            $current = SalesBoardCycleBaseline::query()
                ->with(['lines', 'movements', 'cycle'])
                ->find($locked->current_baseline_id);

            if (! $current instanceof SalesBoardCycleBaseline) {
                throw new RuntimeException('O ciclo não tem versão vigente para recalcular.');
            }

            $construction = $locked->construction()->firstOrFail();
            $month = CarbonImmutable::parse($locked->reference_month->toDateString())->startOfMonth();

            /**
             * Já estamos dentro da transação do recálculo, com o ciclo travado:
             * derivação, observação da fonte e escrita da versão nova enxergam
             * um mundo só, sob `REPEATABLE READ`.
             */
            $position = $this->derivationService->deriveForConstruction($construction, $month);
            $observation = $this->fingerprintService->observeForConstruction($construction, $month);
            $readiness = $this->readinessService->fromPosition($construction, $position);

            $live = SalesBoardComparableSnapshot::fromDerived($position, $observation);
            $frozen = SalesBoardComparableSnapshot::fromBaseline($current);
            $diff = $this->diffService->compare($frozen, $live);

            /**
             * A versão vigente é relida sob lock, e não aceita como a tela a
             * carregou: entre abrir a página e confirmar o recálculo alguém pode
             * ter criado uma versão mais nova, e sobrescrever o ponteiro dela
             * faria a mais recente desaparecer da vista sem ter sido apagada.
             */
            if (($expectedBaselineId !== null) && ((int) $current->getKey() !== $expectedBaselineId)) {
                return new SalesBoardRecalculationResult(
                    outcome: SalesBoardRecalculationOutcome::Blocked,
                    previousBaseline: $current,
                    baseline: null,
                    reason: $reason,
                    readiness: $readiness,
                    diff: $diff,
                    blockedReason: sprintf(
                        'A versão vigente mudou para a %s enquanto esta ação estava aberta. Reveja as alterações antes de recalcular.',
                        $current->versionLabel(),
                    ),
                );
            }

            if (! $readiness->isReady()) {
                return new SalesBoardRecalculationResult(
                    outcome: SalesBoardRecalculationOutcome::Blocked,
                    previousBaseline: $current,
                    baseline: null,
                    reason: $reason,
                    readiness: $readiness,
                    diff: $diff,
                    blockedReason: 'A fonte atual está incompleta e não permite uma versão nova: '
                        .collect($readiness->blockingIssueCounts())
                            ->map(fn (int $count, string $code): string => sprintf('%s (%d)', $code, $count))
                            ->implode('; '),
                );
            }

            $sourceFingerprint = $observation->fingerprint();
            $snapshotFingerprint = $live->snapshot->fingerprint();

            if (($sourceFingerprint === (string) $current->source_fingerprint)
                && ($snapshotFingerprint === (string) $current->snapshot_fingerprint)) {
                $this->markUnchanged($current, $sourceFingerprint, $snapshotFingerprint);

                return new SalesBoardRecalculationResult(
                    outcome: SalesBoardRecalculationOutcome::Unchanged,
                    previousBaseline: $current,
                    baseline: null,
                    reason: $reason,
                    readiness: $readiness,
                    diff: $diff,
                );
            }

            $baseline = $this->baselineWriter->write(
                cycle: $locked,
                version: $this->nextVersion($locked),
                comparable: $live,
                sourceFingerprint: $sourceFingerprint,
                actor: $actor,
                reason: $reason,
            );

            $locked->forceFill(['current_baseline_id' => $baseline->getKey()])->save();

            /**
             * O aviso de que a versão vigente mudou sai **depois** do commit.
             *
             * Quem escuta -- hoje, a validação da construtora -- toma decisões
             * irreversíveis a partir dele. Disparar dentro da transação faria um
             * recálculo que acabasse desfeito invalidar a conferência de alguém
             * por uma versão que nunca existiu.
             */
            $this->announceBaselineChange($locked, $current, $baseline);

            return new SalesBoardRecalculationResult(
                outcome: SalesBoardRecalculationOutcome::Recalculated,
                previousBaseline: $current,
                baseline: $baseline,
                reason: $reason,
                readiness: $readiness,
                diff: $diff,
            );
        });
    }

    /**
     * Anuncia a troca de versão vigente assim que ela estiver garantida.
     *
     * `afterCommit()` respeita o aninhamento: se este recálculo estiver dentro de
     * uma transação maior, o aviso espera a de fora; se não houver nenhuma, sai
     * na hora. Não há caminho em que ele saia antes de a nova versão existir.
     */
    private function announceBaselineChange(
        SalesBoardCycle $cycle,
        SalesBoardCycleBaseline $previous,
        SalesBoardCycleBaseline $current,
    ): void {
        DB::afterCommit(static function () use ($cycle, $previous, $current): void {
            SalesBoardCurrentBaselineChanged::dispatch($cycle, $previous, $current);
        });
    }

    /**
     * O próximo número da sequência, lido com o ciclo já travado.
     */
    private function nextVersion(SalesBoardCycle $cycle): int
    {
        return ((int) SalesBoardCycleBaseline::query()
            ->where('sales_board_cycle_id', $cycle->getKey())
            ->max('version')) + 1;
    }

    /**
     * Nada mudou: a versão vigente deixa de estar obsoleta e o registro de
     * quando ela divergiu pela primeira vez é preservado.
     */
    private function markUnchanged(SalesBoardCycleBaseline $baseline, string $sourceFingerprint, string $snapshotFingerprint): void
    {
        $baseline->forceFill([
            'is_stale' => false,
            'stale_impact' => SalesBoardStaleImpact::None,
            'last_checked_at' => CarbonImmutable::now(),
            'last_observed_source_fingerprint' => $sourceFingerprint,
            'last_observed_snapshot_fingerprint' => $snapshotFingerprint,
        ])->save();
    }
}

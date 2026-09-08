<?php

declare(strict_types=1);

namespace App\Services\SalesBoards;

use App\DTOs\SalesBoards\SalesBoardComparableSnapshot;
use App\DTOs\SalesBoards\SalesBoardStaleAssessment;
use App\Enums\SalesBoardStaleImpact;
use App\Models\SalesBoardCycle;
use App\Models\SalesBoardCycleBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Descobre se a fonte mudou desde que a versão vigente foi congelada.
 *
 * Verificar **não** recalcula. São duas decisões diferentes: perceber que o
 * mundo mudou é observação, e criar uma versão nova é ato deliberado, com autor
 * e motivo. Encadear as duas apagaria a versão que se queria comparar no mesmo
 * gesto em que se descobriu que valia a pena compará-la.
 *
 * A classificação separa três situações que é fácil confundir:
 *
 * - **somente fonte**: alguém mexeu num fato material e a posição derivada dele
 *   continua idêntica. Acontece de verdade -- corrigir o valor esperado de uma
 *   parcela de um contrato já distratado, por exemplo, muda a fonte e não move
 *   nenhum número congelado;
 * - **material**: o snapshot mudaria. Classificação, valor, contrato, movimento
 *   ou conformidade;
 * - **bloqueante**: a fonte atual nem sequer passa mais na prontidão. Não existe
 *   versão nova possível, e a anterior continua valendo como registro do que foi
 *   apurado quando havia dado para apurar.
 *
 * O único efeito colateral é escrever os metadados de obsolescência na versão
 * vigente. O conteúdo apurado não é tocado -- o model recusaria.
 */
class SalesBoardStaleDetectionService
{
    public function __construct(
        private readonly SalesBoardDerivationService $derivationService,
        private readonly SalesBoardReadinessService $readinessService,
        private readonly SalesBoardFingerprintService $fingerprintService,
        private readonly SalesBoardBaselineDiffService $diffService,
    ) {}

    public function check(SalesBoardCycle $cycle): SalesBoardStaleAssessment
    {
        $cycle->loadMissing(['construction', 'currentBaseline.lines', 'currentBaseline.movements', 'currentBaseline.cycle']);

        $baseline = $cycle->currentBaseline;

        if (! $baseline instanceof SalesBoardCycleBaseline) {
            throw new RuntimeException('O ciclo não tem versão vigente para verificar.');
        }

        return $this->assess($cycle, $baseline, persist: true);
    }

    /**
     * A mesma verificação sem gravar nada.
     *
     * Serve às telas que precisam mostrar a situação antes de o operador decidir
     * se quer registrar a constatação -- e ao recálculo, que faz a própria
     * avaliação dentro da sua transação.
     */
    public function assessWithoutPersisting(SalesBoardCycle $cycle, SalesBoardCycleBaseline $baseline): SalesBoardStaleAssessment
    {
        return $this->assess($cycle, $baseline, persist: false);
    }

    private function assess(SalesBoardCycle $cycle, SalesBoardCycleBaseline $baseline, bool $persist): SalesBoardStaleAssessment
    {
        $construction = $cycle->construction;
        $month = CarbonImmutable::parse($cycle->reference_month->toDateString())->startOfMonth();

        /**
         * Derivar e observar a fonte na mesma transação, pelo mesmo motivo da
         * geração: comparar um snapshot de um instante com um resumo de fonte
         * de outro acusaria diferenças que nunca existiram ao mesmo tempo.
         */
        [$position, $observation] = DB::transaction(fn (): array => [
            $this->derivationService->deriveForConstruction($construction, $month),
            $this->fingerprintService->observeForConstruction($construction, $month),
        ]);

        $readiness = $this->readinessService->fromPosition($construction, $position);

        $live = SalesBoardComparableSnapshot::fromDerived($position, $observation);
        $frozen = SalesBoardComparableSnapshot::fromBaseline($baseline);

        $observedSourceFingerprint = $observation->fingerprint();
        $liveSnapshotFingerprint = $live->snapshot->fingerprint();

        $sourceChanged = $observedSourceFingerprint !== (string) $baseline->source_fingerprint;
        $snapshotChanged = $liveSnapshotFingerprint !== (string) $baseline->snapshot_fingerprint;

        $impact = match (true) {
            ! $sourceChanged && ! $snapshotChanged => SalesBoardStaleImpact::None,
            ! $readiness->isReady() => SalesBoardStaleImpact::Blocking,
            $snapshotChanged => SalesBoardStaleImpact::Material,
            default => SalesBoardStaleImpact::SourceOnly,
        };

        $assessment = new SalesBoardStaleAssessment(
            baseline: $baseline,
            impact: $impact,
            sourceChanged: $sourceChanged,
            snapshotChanged: $snapshotChanged,
            observedSourceFingerprint: $observedSourceFingerprint,
            /**
             * Fonte bloqueada não produz fingerprint de snapshot. A posição
             * derivada dela existe, mas é uma que o Nimbus recusaria persistir;
             * gravar o resumo dela ao lado dos outros faria parecer que existe
             * uma versão candidata quando não existe.
             */
            observedSnapshotFingerprint: $readiness->isReady() ? $liveSnapshotFingerprint : null,
            readiness: $readiness,
            diff: $this->diffService->compare($frozen, $live),
        );

        if ($persist) {
            $this->recordOn($baseline, $assessment);
        }

        return $assessment;
    }

    /**
     * Grava a constatação na versão vigente.
     *
     * `stale_detected_at` marca a **primeira** vez em que aquela versão divergiu
     * e não é apagado depois. Se a fonte voltar exatamente ao que era,
     * `is_stale` volta a ser falso -- porque é verdade que ela está igual de
     * novo -- mas continua registrado que aquela versão já passou por uma
     * divergência, o que é uma informação diferente e igualmente útil.
     */
    private function recordOn(SalesBoardCycleBaseline $baseline, SalesBoardStaleAssessment $assessment): void
    {
        $now = CarbonImmutable::now();

        $baseline->forceFill([
            'is_stale' => $assessment->isStale(),
            'stale_impact' => $assessment->impact,
            'stale_detected_at' => $baseline->stale_detected_at ?? ($assessment->isStale() ? $now : null),
            'last_checked_at' => $now,
            'last_observed_source_fingerprint' => $assessment->observedSourceFingerprint,
            'last_observed_snapshot_fingerprint' => $assessment->observedSnapshotFingerprint,
        ])->save();
    }
}

<?php

namespace App\Domain\PuCalculator\Services;

use App\Actions\Emissions\GeneratePuDailyCurve;
use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\EmissionPuDailyCurve;
use Illuminate\Support\Facades\DB;

/**
 * Grava as linhas de uma versão de curva operacional.
 *
 * Gerar não publica: nada aqui escreve no Histórico de PU, no Cronograma de
 * Pagamentos ou no PU atual da emissão. A curva só alcança as outras áreas pela
 * homologação ({@see HomologatePuCurve}).
 *
 * Com o retrato de insumos (Fase 4), a gravação:
 *  - relê os insumos vivos sob trava compartilhada, dentro da transação que já
 *    travou a emissão, e recusa tudo se eles não forem mais os do retrato -- as
 *    linhas foram calculadas com o retrato, e é ele que a versão vai declarar;
 *  - grava o retrato e o fingerprint na versão (imutáveis daí em diante);
 *  - registra de qual versão oficial a nova partiu e por quê;
 *  - marca os eventos usados como governados: dali em diante só se cancelam.
 */
class PuCurvePersistenceService
{
    public function __construct(
        private readonly PuCurveVersionService $curveVersions,
        private readonly PuOperationalProfileGuard $operationalProfiles,
        private readonly PuCurveInputSnapshotService $snapshots,
        private readonly PuCurveChangeImpactClassifier $classifier,
        private readonly PuIndexerCapabilityPolicy $indexerPolicy,
    ) {}

    /**
     * @param  bool  $syncLegacyProjections  sem efeito desde a Fase 2 de governança: nenhuma
     *                                       geração projeta no legado. Mantido pela assinatura.
     * @param  PuCurveInputSnapshot|null  $inputs  retrato de que as linhas foram calculadas. Sem
     *                                             ele a versão nasce sem retrato, e a extensão
     *                                             diária a recusa: todo caminho operacional
     *                                             ({@see GeneratePuDailyCurve}) o informa.
     */
    public function handle(
        Emission $emission,
        PuCurveGenerationResult $result,
        bool $syncLegacyProjections = false,
        ?string $calculationVersion = null,
        ?PuCurveInputSnapshot $inputs = null,
    ): PuCurveGenerationResult {
        // Curva oficial: só entra linha calculada no perfil contratual. O perfil de
        // reconciliação com o sistema legado não pode virar dado operacional nem por
        // engano de um caminho futuro -- a recusa acontece ANTES de abrir a transação.
        $this->operationalProfiles->assertOperational($result->rows, 'a curva operacional');
        // Fase 6 (P0-05): só indexador com homologação operacional grava versão --
        // a fronteira por onde passa toda curva operacional, qualquer que seja o
        // chamador (tela, job, comando, serviço direto).
        $this->indexerPolicy->assert(
            $inputs instanceof PuCurveInputSnapshot
                ? PuIndexer::tryFrom((string) ($inputs->terms()['indexer'] ?? ''))
                : $this->indexerPolicy->emissionIndexer($emission),
            PuIndexerCapability::CurveGeneration,
            (int) $emission->getKey(),
        );

        $persistedResult = $result;

        DB::transaction(function () use ($emission, $result, $calculationVersion, $inputs, &$persistedResult): void {
            $requestedCalculationVersion = $calculationVersion ?? $result->calculationVersion;
            $version = $requestedCalculationVersion === null
                ? null
                : EmissionPuCurveVersion::query()
                    ->whereBelongsTo($emission)
                    ->operational()
                    ->where('calculation_version', $requestedCalculationVersion)
                    ->latest('id')
                    ->first();
            $createdVersion = ! $version instanceof EmissionPuCurveVersion;

            if (! $createdVersion) {
                $version = $this->lockProcessingVersion($emission, $version);
            }

            $version ??= $this->curveVersions->startGeneration(
                emission: $emission,
                requestedByUserId: null,
                calculationVersion: $requestedCalculationVersion,
            );

            if ($inputs instanceof PuCurveInputSnapshot) {
                $this->assertInputsUnchanged($emission, $inputs);
                $this->recordInputs($emission, $version, $inputs);
            }

            $calculationVersion = $version->calculation_version;
            $persistedResult = $result->withCalculationVersion($calculationVersion);
            $timestamp = now();
            $rows = array_map(function ($row) use ($emission, $version, $timestamp, $calculationVersion): array {
                return [
                    ...$row->toPersistenceArray($emission->id, $calculationVersion),
                    'curve_version_id' => $version->id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }, $persistedResult->rows);

            foreach (array_chunk($rows, 500) as $chunk) {
                EmissionPuDailyCurve::query()->insert($chunk);
            }

            if ($createdVersion) {
                $this->curveVersions->markGenerated($version, count($rows), $calculationVersion);
            }
        });

        return $persistedResult;
    }

    /**
     * Linhas só entram numa versão que a geração ainda possui. Substituída ou
     * invalidada no meio do cálculo, a versão não recebe linha nenhuma.
     */
    private function lockProcessingVersion(Emission $emission, EmissionPuCurveVersion $version): EmissionPuCurveVersion
    {
        Emission::query()->whereKey($emission->id)->lockForUpdate()->first();
        $locked = EmissionPuCurveVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== PuCurveStatus::Processing) {
            throw new PuCurveGovernanceException(sprintf(
                'A versão %s não está em processamento (status atual: %s); as linhas calculadas não foram gravadas nela.',
                $locked->calculation_version,
                $locked->status->label(),
            ));
        }

        return $locked;
    }

    /**
     * Relidos sob trava compartilhada, os insumos vivos têm de ser exatamente os do
     * retrato. Uma edição que comitou durante o cálculo é vista aqui; uma que tente
     * comitar agora espera esta transação.
     */
    private function assertInputsUnchanged(Emission $emission, PuCurveInputSnapshot $inputs): void
    {
        $live = $this->snapshots->capture($emission, lockForShare: true);

        if (! $live->sameInputsAs($inputs)) {
            throw new PuCurveInputsException(
                'Os insumos contratuais da emissão (parâmetros, eventos ou integralizações) mudaram durante a geração; nenhuma linha foi gravada. Gere a curva de novo.',
            );
        }
    }

    private function recordInputs(Emission $emission, EmissionPuCurveVersion $version, PuCurveInputSnapshot $inputs): void
    {
        $official = EmissionPuCurveVersion::query()
            ->where('emission_id', $emission->id)
            ->official()
            ->whereKeyNot($version->id)
            ->first();

        $version->forceFill([
            'curve_inputs_schema' => $inputs->schema,
            'curve_inputs_fingerprint' => $inputs->fingerprint,
            'curve_inputs' => $inputs->toArray(),
            'predecessor_version_id' => $official?->id,
            'generation_context' => $this->generationContext($official, $inputs),
        ])->save();

        $eventIds = array_values(array_map('intval', $inputs->provenance['event_ids'] ?? []));

        if ($eventIds !== []) {
            DB::table('emission_pu_events')
                ->whereIn('id', $eventIds)
                ->whereNull('governed_at')
                ->update(['governed_at' => now()]);
        }
    }

    /**
     * Por que esta versão existe frente à oficial vigente: primeira curva, mudança
     * contratual (com a data a partir da qual o contrato mudou), correção de índice
     * já registrada na oficial, ou regeneração com os mesmos insumos.
     *
     * @return array<string, mixed>
     */
    private function generationContext(?EmissionPuCurveVersion $official, PuCurveInputSnapshot $inputs): array
    {
        if (! $official instanceof EmissionPuCurveVersion) {
            return ['reason' => 'initial', 'inputs_fingerprint' => $inputs->fingerprint];
        }

        $context = [
            'predecessor_calculation_version' => $official->calculation_version,
            'predecessor_inputs_fingerprint' => $official->curve_inputs_fingerprint,
            'inputs_fingerprint' => $inputs->fingerprint,
        ];
        $approved = $this->snapshots->forVersion($official);
        $assessment = $approved instanceof PuCurveInputSnapshot
            ? $this->classifier->compare($approved, $inputs, $this->classifier->lastPersistedDate($official))
            : null;

        if ($assessment?->hasContractualChange() ?? false) {
            return [
                ...$context,
                'reason' => 'contractual_input_changed',
                'impact' => $assessment->impact->value,
                'earliest_affected_date' => $assessment->earliestAffectedDate?->toDateString(),
                'changes' => array_slice($assessment->toArray()['changes'], 0, 50),
            ];
        }

        $divergence = is_array($official->extension_divergence) ? $official->extension_divergence : [];

        return [
            ...$context,
            'reason' => match (true) {
                ! $approved instanceof PuCurveInputSnapshot => 'predecessor_without_inputs',
                ($divergence['cause'] ?? null) === 'index_rate_corrected' => 'index_rate_corrected',
                $official->extension_diverged_at !== null => 'official_extension_diverged',
                default => 'regenerated_with_same_inputs',
            },
            'earliest_affected_date' => $divergence['first_divergent_date'] ?? null,
        ];
    }
}

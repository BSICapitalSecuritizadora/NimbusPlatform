<?php

namespace App\Domain\PuCalculator\Services;

use App\Actions\Emissions\HomologatePuCurve;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
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
 */
class PuCurvePersistenceService
{
    public function __construct(
        private readonly PuCurveVersionService $curveVersions,
        private readonly PuOperationalProfileGuard $operationalProfiles,
    ) {}

    /**
     * @param  bool  $syncLegacyProjections  sem efeito desde a Fase 2 de governança: nenhuma
     *                                       geração projeta no legado. Mantido pela assinatura.
     */
    public function handle(
        Emission $emission,
        PuCurveGenerationResult $result,
        bool $syncLegacyProjections = false,
        ?string $calculationVersion = null,
    ): PuCurveGenerationResult {
        // Curva oficial: só entra linha calculada no perfil contratual. O perfil de
        // reconciliação com o sistema legado não pode virar dado operacional nem por
        // engano de um caminho futuro -- a recusa acontece ANTES de abrir a transação.
        $this->operationalProfiles->assertOperational($result->rows, 'a curva operacional');

        $persistedResult = $result;

        DB::transaction(function () use ($emission, $result, $calculationVersion, &$persistedResult): void {
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
}

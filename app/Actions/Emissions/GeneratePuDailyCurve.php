<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
use App\Domain\PuCalculator\Services\PuCurveInputSnapshotService;
use App\Domain\PuCalculator\Services\PuCurveInputValidator;
use App\Domain\PuCalculator\Services\PuCurvePersistenceService;
use App\Domain\PuCalculator\Services\PuCurvePrerequisiteService;
use App\Models\Emission;
use InvalidArgumentException;

class GeneratePuDailyCurve
{
    public function __construct(
        private readonly PuCurveGeneratorService $generationService,
        private readonly PuCurvePersistenceService $persistenceService,
        private readonly PuCurvePrerequisiteService $prerequisiteService,
        private readonly PuCurveInputSnapshotService $snapshots,
        private readonly PuCurveInputValidator $inputValidator,
    ) {}

    /**
     * Calcula e grava uma versão de curva. Gerar não publica nada: a curva só
     * chega ao PU oficial, aos pagamentos e ao site pela homologação.
     *
     * A curva é calculada a partir de um RETRATO dos insumos contratuais tirado
     * aqui, e não das tabelas vivas: o mesmo retrato é gravado na versão. Se os
     * insumos mudarem entre o retrato e a gravação, nada é gravado
     * ({@see PuCurvePersistenceService}) -- uma versão nunca diz ter usado um
     * contrato e ter calculado com outro.
     *
     * @param  bool  $syncLegacyProjections  sem efeito desde a Fase 2 de governança; mantido pela assinatura
     */
    public function handle(
        Emission $emission,
        bool $syncLegacyProjections = false,
        ?string $calculationVersion = null,
    ): PuCurveGenerationResult {
        $prerequisiteCheck = $this->prerequisiteService->handle($emission);

        if (! $prerequisiteCheck->passes()) {
            throw new InvalidArgumentException($prerequisiteCheck->blockingSummary());
        }

        $inputs = $this->snapshots->capture($emission);

        if (($issues = $this->inputValidator->issues($inputs)) !== []) {
            throw PuCurveInputsException::inconsistent($issues);
        }

        $result = $this->generationService->handle($this->snapshots->hydrate($emission, $inputs));

        return $this->persistenceService->handle($emission, $result, $syncLegacyProjections, $calculationVersion, $inputs);
    }
}

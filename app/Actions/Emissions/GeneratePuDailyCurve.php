<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\Services\PuCurveGeneratorService;
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
    ) {}

    /**
     * Calcula e grava uma versão de curva. Gerar não publica nada: a curva só
     * chega ao PU oficial, aos pagamentos e ao site pela homologação.
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

        $result = $this->generationService->handle($emission);

        return $this->persistenceService->handle($emission, $result, $syncLegacyProjections, $calculationVersion);
    }
}

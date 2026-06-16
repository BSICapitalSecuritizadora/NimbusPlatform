<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\Services\PuCurveGenerationService;
use App\Domain\PuCalculator\Services\PuCurvePersistenceService;
use App\Models\Emission;

class GeneratePuDailyCurve
{
    public function __construct(
        private readonly PuCurveGenerationService $generationService,
        private readonly PuCurvePersistenceService $persistenceService,
    ) {}

    public function handle(Emission $emission, bool $syncLegacyProjections = true): PuCurveGenerationResult
    {
        $result = $this->generationService->handle($emission);

        return $this->persistenceService->handle($emission, $result, $syncLegacyProjections);
    }
}

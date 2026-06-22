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

    public function handle(Emission $emission, bool $syncLegacyProjections = true): PuCurveGenerationResult
    {
        $prerequisiteCheck = $this->prerequisiteService->handle($emission);

        if (! $prerequisiteCheck->passes()) {
            throw new InvalidArgumentException($prerequisiteCheck->blockingSummary());
        }

        $result = $this->generationService->handle($emission);

        return $this->persistenceService->handle($emission, $result, $syncLegacyProjections);
    }
}

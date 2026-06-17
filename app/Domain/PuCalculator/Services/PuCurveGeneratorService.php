<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Models\Emission;

class PuCurveGeneratorService
{
    public function __construct(
        private readonly PuCurveGenerationService $generationService,
    ) {}

    public function handle(Emission $emission): PuCurveGenerationResult
    {
        return $this->generationService->handle($emission);
    }
}

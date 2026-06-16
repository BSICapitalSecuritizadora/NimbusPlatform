<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\DTOs\PuValidationReport;
use App\Domain\PuCalculator\Services\PuValidationService;
use App\Models\Emission;

class ValidatePuDailyCurve
{
    public function __construct(
        private readonly PuValidationService $validationService,
    ) {}

    public function handle(Emission $emission, string $spreadsheetPath): PuValidationReport
    {
        return $this->validationService->handle($emission, $spreadsheetPath);
    }
}

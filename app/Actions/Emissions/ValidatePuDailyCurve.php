<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\DTOs\PuValidationReport;
use App\Domain\PuCalculator\Enums\PuValidationMode;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuValidationService;
use App\Models\Emission;
use Carbon\CarbonImmutable;

class ValidatePuDailyCurve
{
    public function __construct(
        private readonly PuValidationService $validationService,
        private readonly PuAuditLogService $auditLogService,
    ) {}

    public function handle(
        Emission $emission,
        string $spreadsheetPath,
        ?string $calculationVersion = null,
        PuValidationMode $mode = PuValidationMode::RawScale,
        ?CarbonImmutable $rangeStart = null,
        ?CarbonImmutable $rangeEnd = null,
        ?int $requestedByUserId = null,
    ): PuValidationReport {
        $report = $this->validationService->handle(
            $emission,
            $spreadsheetPath,
            $calculationVersion,
            $mode,
            $rangeStart,
            $rangeEnd,
        );

        if ($requestedByUserId !== null) {
            $this->auditLogService->logValidation($emission, $report, $spreadsheetPath, $requestedByUserId);
        }

        return $report;
    }
}

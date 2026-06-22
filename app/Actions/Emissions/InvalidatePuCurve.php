<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use InvalidArgumentException;

class InvalidatePuCurve
{
    public function __construct(
        private readonly PuCurveVersionService $versionService,
        private readonly PuAuditLogService $auditLogService,
    ) {}

    public function handle(Emission $emission, ?string $calculationVersion = null, ?int $requestedByUserId = null): EmissionPuCurveVersion
    {
        $version = $this->versionService->findByCalculationVersion($emission, $calculationVersion);

        if ($version === null) {
            throw new InvalidArgumentException('Nenhuma versao de curva disponivel para invalidacao.');
        }

        $this->versionService->markInvalidated($version, $requestedByUserId);
        $this->auditLogService->logInvalidation($emission, $version->calculation_version, $requestedByUserId);

        return $version;
    }
}

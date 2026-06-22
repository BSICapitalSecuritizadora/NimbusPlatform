<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use InvalidArgumentException;

class HomologatePuCurve
{
    public function __construct(
        private readonly PuCurveVersionService $versionService,
        private readonly PuAuditLogService $auditLogService,
    ) {}

    public function handle(Emission $emission, ?string $calculationVersion = null, ?int $requestedByUserId = null): EmissionPuCurveVersion
    {
        $version = $this->versionService->findByCalculationVersion($emission, $calculationVersion);

        if ($version === null) {
            throw new InvalidArgumentException('Nenhuma versao de curva disponivel para homologacao.');
        }

        if (! in_array($version->status, [PuCurveStatus::Generated, PuCurveStatus::Validated, PuCurveStatus::Divergent], true)) {
            throw new InvalidArgumentException('Apenas curvas geradas ou validadas podem ser homologadas.');
        }

        $this->versionService->markHomologated($version, $requestedByUserId);
        $this->auditLogService->logHomologation($emission, $version->calculation_version, $requestedByUserId);

        return $version;
    }
}

<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Models\User;
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

        $this->assertCheckerIsNotMaker($version, $requestedByUserId);

        $this->versionService->markHomologated($version, $requestedByUserId);
        $this->auditLogService->logHomologation($emission, $version->calculation_version, $requestedByUserId);

        return $version;
    }

    /**
     * Segregação maker/checker: quem gerou ou validou a versão não pode homologá-la, exceto super admin.
     * Sem usuário identificado (automação) a separação não é aplicada.
     */
    private function assertCheckerIsNotMaker(EmissionPuCurveVersion $version, ?int $requestedByUserId): void
    {
        if ($requestedByUserId === null) {
            return;
        }

        $checker = User::find($requestedByUserId);

        if ($checker !== null && method_exists($checker, 'hasRole') && $checker->hasRole('super-admin')) {
            return;
        }

        $makerIds = array_filter([$version->generated_by, $version->validated_by]);

        if (in_array($requestedByUserId, $makerIds, true)) {
            throw new PuMakerCheckerException(
                'A homologação exige segregação maker/checker: o usuário que gerou ou validou a curva não pode homologá-la. Solicite a homologação a outro usuário autorizado (ou a um super admin).',
            );
        }
    }
}

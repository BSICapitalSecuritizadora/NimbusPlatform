<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Enums\BusinessArea;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Services\AreaResponsibilityService;
use InvalidArgumentException;

class HomologatePuCurve
{
    public function __construct(
        private readonly PuCurveVersionService $versionService,
        private readonly PuAuditLogService $auditLogService,
        private readonly AreaResponsibilityService $areaResponsibilities,
    ) {}

    public function handle(
        Emission $emission,
        ?string $calculationVersion = null,
        ?int $requestedByUserId = null,
        ?string $justification = null,
    ): EmissionPuCurveVersion {
        $version = $this->versionService->findByCalculationVersion($emission, $calculationVersion);

        if ($version === null) {
            throw new InvalidArgumentException('Nenhuma versao de curva disponivel para homologacao.');
        }

        if (! in_array($version->status, [PuCurveStatus::Generated, PuCurveStatus::Validated, PuCurveStatus::Divergent], true)) {
            throw new InvalidArgumentException('Apenas curvas geradas ou validadas podem ser homologadas.');
        }

        $justification = filled($justification) ? trim($justification) : null;
        $selfHomologation = $this->isSelfHomologation($version, $requestedByUserId);

        if ($selfHomologation) {
            $blocker = $this->selfHomologationBlocker($version, $requestedByUserId)
                ?? ($justification === null ? 'Informe a justificativa da auto-homologação.' : null);

            if ($blocker !== null) {
                throw new PuMakerCheckerException($blocker);
            }
        }

        $this->versionService->markHomologated($version, $requestedByUserId, $selfHomologation, $justification);
        $this->auditLogService->logHomologation(
            $emission,
            $version->calculation_version,
            $requestedByUserId,
            $selfHomologation,
            $justification,
        );

        return $version;
    }

    /**
     * Quem gerou ou validou a versão está homologando o próprio trabalho. Sem
     * usuário identificado (automação) não há segregação a aplicar.
     */
    public function isSelfHomologation(EmissionPuCurveVersion $version, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        $makerIds = array_map('intval', array_filter([$version->generated_by, $version->validated_by]));

        return in_array($userId, $makerIds, true);
    }

    /**
     * Por que o usuário não pode homologar a própria versão, ou nulo quando pode
     * (a justificativa é cobrada à parte, no momento da homologação).
     *
     * A auto-homologação vale só para o responsável pela área Curva de PU e só
     * depois de a validação contra planilha ter passado: a segunda conferência
     * passa a ser a comparação automática, e não outra pessoa.
     */
    public function selfHomologationBlocker(EmissionPuCurveVersion $version, ?int $userId): ?string
    {
        if (! $this->isSelfHomologation($version, $userId)) {
            return null;
        }

        if (! $this->areaResponsibilities->isResponsible($userId, BusinessArea::PuCurve)) {
            return sprintf(
                'Você gerou ou validou esta curva. Ela precisa ser homologada por outra pessoa ou pelo responsável da área %s (Configurações > Áreas e responsáveis).',
                BusinessArea::PuCurve->label(),
            );
        }

        if ($version->status !== PuCurveStatus::Validated) {
            return 'Para homologar a própria curva, ela precisa antes passar na validação contra planilha, sem divergências.';
        }

        return null;
    }
}

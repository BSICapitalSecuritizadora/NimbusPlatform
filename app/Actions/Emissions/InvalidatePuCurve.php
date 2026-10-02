<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Domain\PuCalculator\Services\PuPaymentScheduleService;
use App\Enums\PuSourceChange;
use App\Events\PuCalculator\EmissionPuSourceChanged;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use InvalidArgumentException;

class InvalidatePuCurve
{
    public function __construct(
        private readonly PuCurveVersionService $versionService,
        private readonly PuAuditLogService $auditLogService,
        private readonly PuPaymentScheduleService $paymentSchedule,
    ) {}

    public function handle(Emission $emission, ?string $calculationVersion = null, ?int $requestedByUserId = null): EmissionPuCurveVersion
    {
        $version = $this->versionService->findByCalculationVersion($emission, $calculationVersion);

        if ($version === null) {
            throw new InvalidArgumentException('Nenhuma versao de curva disponivel para invalidacao.');
        }

        $this->versionService->markInvalidated($version, $requestedByUserId);
        $this->auditLogService->logInvalidation($emission, $version->calculation_version, $requestedByUserId);

        // Se a versão invalidada era a oficial, os pagamentos calculados por ela
        // voltam ao previsto (ou passam para a homologada anterior).
        $this->paymentSchedule->reconcile($emission->fresh(), $requestedByUserId);

        // E o saldo devedor das garantias também volta a outra fonte.
        EmissionPuSourceChanged::dispatch(
            (int) $emission->getKey(),
            PuSourceChange::CurveInvalidated,
            $version->calculation_version,
            $requestedByUserId,
        );

        return $version;
    }
}

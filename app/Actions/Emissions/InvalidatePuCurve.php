<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Domain\PuCalculator\Services\PuFinancialObligationService;
use App\Enums\PuSourceChange;
use App\Events\PuCalculator\EmissionPuSourceChanged;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use Illuminate\Support\Facades\DB;

/**
 * Invalidação da versão NOMEADA, numa única transação com a atualização das
 * obrigações financeiras.
 *
 * A versão vira `obsolete` (motivo `invalidated`) e fica preservada para
 * auditoria. Uma versão em `processing` é recusada: a geração ainda é dona dela.
 * Invalidada a curva oficial, a homologada anterior -- se ainda homologada --
 * volta a ser a oficial; sem nenhuma, a emissão fica sem curva oficial. Nunca se
 * cai para uma versão não homologada.
 */
class InvalidatePuCurve
{
    public function __construct(
        private readonly PuCurveVersionService $versionService,
        private readonly PuAuditLogService $auditLogService,
        private readonly PuFinancialObligationService $obligations,
    ) {}

    /**
     * @param  string|null  $calculationVersion  versão revisada por quem invalida; obrigatória
     */
    public function handle(
        Emission $emission,
        ?string $calculationVersion,
        ?int $requestedByUserId = null,
        ?string $reason = null,
    ): EmissionPuCurveVersion {
        $reason = filled($reason) ? trim($reason) : null;

        return DB::transaction(function () use ($emission, $calculationVersion, $requestedByUserId, $reason): EmissionPuCurveVersion {
            $lockedEmission = Emission::query()->whereKey($emission->id)->lockForUpdate()->firstOrFail();
            $version = $this->versionService->lockForGovernance($lockedEmission, $calculationVersion);
            $previousStatus = $version->status;

            if (! $previousStatus->canBeInvalidated()) {
                throw new PuCurveGovernanceException(sprintf(
                    'A versão %s está %s e não pode ser invalidada.%s',
                    $version->calculation_version,
                    mb_strtolower($previousStatus->label()),
                    $previousStatus === PuCurveStatus::Processing ? ' Aguarde a geração terminar.' : '',
                ));
            }

            $this->versionService->markInvalidated($version, $requestedByUserId);
            $this->auditLogService->logInvalidation(
                $lockedEmission,
                $version->calculation_version,
                $requestedByUserId,
                $version->id,
                $previousStatus->value,
                $reason,
            );

            // Se a versão invalidada era a oficial, o esperado das obrigações passa
            // para a homologada anterior (ou fica sem curva oficial). A liquidação
            // registrada não muda.
            $this->obligations->refresh($lockedEmission, 'curve_invalidated', $requestedByUserId);

            // E o saldo devedor das garantias também volta a outra fonte.
            EmissionPuSourceChanged::dispatch(
                (int) $lockedEmission->getKey(),
                PuSourceChange::CurveInvalidated,
                $version->calculation_version,
                $requestedByUserId,
            );

            return $version;
        });
    }
}

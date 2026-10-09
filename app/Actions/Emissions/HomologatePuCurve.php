<?php

namespace App\Actions\Emissions;

use App\Domain\PuCalculator\DTOs\PuCurveInputSnapshot;
use App\Domain\PuCalculator\Enums\PuCurveChangeImpact;
use App\Domain\PuCalculator\Enums\PuCurveStatus;
use App\Domain\PuCalculator\Enums\PuIndexerCapability;
use App\Domain\PuCalculator\Exceptions\PuCurveGovernanceException;
use App\Domain\PuCalculator\Exceptions\PuMakerCheckerException;
use App\Domain\PuCalculator\Services\PuAuditLogService;
use App\Domain\PuCalculator\Services\PuCurveChangeImpactClassifier;
use App\Domain\PuCalculator\Services\PuCurveInputSnapshotService;
use App\Domain\PuCalculator\Services\PuCurveVersionService;
use App\Domain\PuCalculator\Services\PuFinancialObligationService;
use App\Domain\PuCalculator\Services\PuIndexerCapabilityPolicy;
use App\Enums\BusinessArea;
use App\Enums\PuSourceChange;
use App\Events\PuCalculator\EmissionPuSourceChanged;
use App\Models\Emission;
use App\Models\EmissionPuCurveVersion;
use App\Services\AreaResponsibilityService;
use Illuminate\Support\Facades\DB;

/**
 * Homologação: o único ato que torna uma curva oficial.
 *
 * Age sobre a versão NOMEADA por quem homologa -- nunca sobre "a vigente" no
 * momento da execução -- e numa única transação: trava a emissão e a versão,
 * relê o status, aplica maker/checker e justificativa, marca a versão, registra
 * a auditoria e atualiza as obrigações financeiras (o esperado oficial, nunca a
 * liquidação). Se qualquer passo falha, nada disso fica gravado e nenhum
 * consumidor vê a curva como oficial.
 *
 * Fase 4: homologar aprova também o RETRATO de insumos da versão. Se os insumos
 * contratuais vivos (relidos sob trava compartilhada) já mudaram em algum dia que
 * a versão gravou, ela não representa mais o contrato e a homologação é recusada
 * -- vale para todo caminho, inclusive a auto-homologação. Mudança só depois do
 * último dia gravado não impede: a versão é correta até lá, e a extensão a limita
 * à véspera da mudança até que outra versão seja homologada.
 */
class HomologatePuCurve
{
    public function __construct(
        private readonly PuCurveVersionService $versionService,
        private readonly PuAuditLogService $auditLogService,
        private readonly AreaResponsibilityService $areaResponsibilities,
        private readonly PuFinancialObligationService $obligations,
        private readonly PuCurveInputSnapshotService $snapshots,
        private readonly PuCurveChangeImpactClassifier $classifier,
        private readonly PuIndexerCapabilityPolicy $indexerPolicy,
    ) {}

    /**
     * @param  string|null  $calculationVersion  versão revisada por quem homologa; obrigatória
     */
    public function handle(
        Emission $emission,
        ?string $calculationVersion,
        ?int $requestedByUserId = null,
        ?string $justification = null,
    ): EmissionPuCurveVersion {
        $justification = filled($justification) ? trim($justification) : null;

        return DB::transaction(function () use ($emission, $calculationVersion, $requestedByUserId, $justification): EmissionPuCurveVersion {
            $lockedEmission = Emission::query()->whereKey($emission->id)->lockForUpdate()->firstOrFail();
            $version = $this->versionService->lockForGovernance($lockedEmission, $calculationVersion);
            $previousStatus = $version->status;

            // Fase 6 (P0-05): só indexador com homologação operacional chega ao PU
            // oficial -- a versão é lida pelo indexador que ela aprovou, e indexador
            // desconhecido é recusado. Vale para todo caminho que homologa.
            $this->indexerPolicy->assertForVersion($version, PuIndexerCapability::Homologation);

            if (! $previousStatus->canBeHomologated()) {
                throw new PuCurveGovernanceException(sprintf(
                    'A versão %s está %s e não pode ser homologada. Só curvas geradas, validadas ou divergentes (com justificativa) são homologáveis.',
                    $version->calculation_version,
                    mb_strtolower($previousStatus->label()),
                ));
            }

            $this->assertInputsStillDescribeVersion($lockedEmission, $version);

            $selfHomologation = $this->isSelfHomologation($version, $requestedByUserId);
            $blocker = $this->selfHomologationBlocker($version, $requestedByUserId)
                ?? ($justification === null ? $this->justificationRequirement($version, $requestedByUserId) : null);

            if ($blocker !== null) {
                throw new PuMakerCheckerException($blocker);
            }

            $this->versionService->markHomologated($version, $requestedByUserId, $selfHomologation, $justification);
            $this->auditLogService->logHomologation(
                $lockedEmission,
                $version->calculation_version,
                $requestedByUserId,
                $selfHomologation,
                $justification,
                $version->id,
                $previousStatus->value,
            );

            // A versão homologada passa a ser a curva oficial das outras áreas: o
            // valor esperado das obrigações vem dela (cálculo novo, o anterior fica
            // rastreável) e a conciliação é refeita contra a liquidação, que não
            // muda. Na mesma transação: se a atualização falha, a homologação não
            // acontece.
            $this->obligations->refresh($lockedEmission, 'curve_homologated', $requestedByUserId);

            // O saldo devedor das garantias passa a vir dela: as competências cujo
            // saldo gravado mudou ficam marcadas (depois do commit).
            EmissionPuSourceChanged::dispatch(
                (int) $lockedEmission->getKey(),
                PuSourceChange::CurveHomologated,
                $version->calculation_version,
                $requestedByUserId,
            );

            return $version;
        });
    }

    /**
     * Versão com retrato: os insumos vivos não podem ter mudado em dia que ela já
     * gravou. Versão sem retrato (anterior à Fase 4) segue as regras de antes -- e a
     * extensão diária a recusa.
     */
    private function assertInputsStillDescribeVersion(Emission $emission, EmissionPuCurveVersion $version): void
    {
        $approved = $this->snapshots->forVersion($version);

        if (! $approved instanceof PuCurveInputSnapshot) {
            return;
        }

        $assessment = $this->classifier->compare(
            $approved,
            $this->snapshots->capture($emission, lockForShare: true),
            $this->classifier->lastPersistedDate($version),
        );

        if ($assessment->impact === PuCurveChangeImpact::HistoricalReprocessRequired) {
            throw new PuCurveGovernanceException(sprintf(
                'A versão %s não pode ser homologada: %s Gere uma nova versão com os insumos atuais.',
                $version->calculation_version,
                $assessment->summary(),
            ));
        }
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
     * Por que esta homologação só pode sair com justificativa registrada, ou nulo
     * quando a segregação maker/checker já basta:
     *
     * - auto-homologação (regra de 2026-09-28: responsável da área, curva validada);
     * - curva divergente: a validação contra planilha encontrou diferenças;
     * - curva gerada pela rotina, sem maker identificado: não há segregação a
     *   verificar, então quem homologa registra por quê.
     *
     * Uma curva gerada por uma pessoa e homologada por outra segue o caminho
     * oficial decidido em 2026-09-28 ("Gerar Curva PU" + homologação) sem exigir
     * justificativa.
     */
    public function justificationRequirement(EmissionPuCurveVersion $version, ?int $userId): ?string
    {
        if ($this->isSelfHomologation($version, $userId)) {
            return 'Informe a justificativa da auto-homologação.';
        }

        if ($version->status === PuCurveStatus::Divergent) {
            return 'A validação contra planilha encontrou divergências nesta versão: informe a justificativa para homologá-la.';
        }

        if ($version->generated_by === null) {
            return 'Esta versão foi gerada pela rotina automática, sem responsável identificado: informe a justificativa da homologação.';
        }

        return null;
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

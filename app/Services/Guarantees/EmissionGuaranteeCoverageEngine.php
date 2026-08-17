<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\EmissionGuaranteePositionData;
use App\DTOs\Guarantees\GuaranteePositionData;
use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Models\Emission;
use App\Models\Guarantee;
use App\Models\GuaranteeMonthlyPosition;
use App\Models\GuaranteeSnapshot;
use Illuminate\Support\Collection;

/**
 * Motor de garantias da emissão: consolida as posições individuais numa
 * resposta única sobre enquadramento (§14 e §24 do escopo).
 *
 * Definições que o resto do sistema deve consumir daqui, e não recalcular:
 *
 * - **Valor bruto**: soma dos valores atuais das garantias que compõem a cobertura.
 * - **Valor elegível**: soma dos valores após deságio (haircut) de cada garantia.
 * - **Cobertura**: valor elegível ÷ saldo devedor.
 * - **Mínimo contratual**: o maior percentual exigido sobre o saldo devedor
 *   entre as garantias vigentes — a regra que efetivamente restringe a operação.
 * - **Valor exigido**: mínimo contratual × saldo devedor.
 * - **Excedente/déficit**: valor elegível − valor exigido.
 *
 * Garantias com mínimo absoluto (fundos, contas) não entram no mínimo
 * consolidado: elas são avaliadas individualmente, e um fundo desenquadrado
 * aparece como alerta próprio em vez de distorcer o índice da operação.
 */
class EmissionGuaranteeCoverageEngine
{
    public function __construct(
        private readonly GuaranteePositionCalculator $positionCalculator,
        private readonly OutstandingBalanceResolver $outstandingBalanceResolver,
    ) {}

    /**
     * Posição consolidada da emissão numa competência.
     *
     * `$referenceMonth` aceita qualquer formato que
     * {@see GuaranteeSnapshot::normalizeReferenceMonth()} entenda; o mês corrente
     * é o padrão.
     */
    public function buildPosition(Emission $emission, ?string $referenceMonth = null): EmissionGuaranteePositionData
    {
        $referenceMonth = GuaranteeSnapshot::normalizeReferenceMonth($referenceMonth ?? now()->startOfMonth()->toDateString())
            ?? now()->startOfMonth()->toDateString();

        $dataset = new EmissionOperationalDataset($emission);
        $outstandingBalance = $this->outstandingBalanceResolver->resolveOrNull($emission, $referenceMonth);
        $baseValues = $this->buildBaseValues($emission, $outstandingBalance);

        $guarantees = $this->loadGuarantees($emission);
        $manualPositions = $this->loadManualPositions($emission, $referenceMonth);

        $positions = $guarantees->map(
            fn (Guarantee $guarantee): GuaranteePositionData => $this->positionCalculator->calculate(
                guarantee: $guarantee,
                referenceMonth: $referenceMonth,
                dataset: $dataset,
                baseValues: $baseValues,
                manualPositions: $manualPositions,
            ),
        );

        return $this->consolidate($positions, $referenceMonth, $outstandingBalance);
    }

    /**
     * Histórico consolidado, da competência mais recente para a mais antiga.
     *
     * Lê os snapshots gravados em vez de reapurar cada mês: é o que garante que
     * o histórico não mude quando uma fonte operacional é corrigida (§23).
     *
     * @return Collection<int, GuaranteeSnapshot>
     */
    public function history(Emission $emission): Collection
    {
        if (! Emission::hasGuaranteeSnapshotsTable()) {
            return collect();
        }

        return $emission->guaranteeSnapshots()
            ->orderByDesc('reference_month')
            ->get();
    }

    /**
     * @return Collection<int, Guarantee>
     */
    private function loadGuarantees(Emission $emission): Collection
    {
        return $emission->guarantees()
            ->with(['valuations', 'events'])
            ->get();
    }

    /**
     * Valores já digitados para a competência, indexados por garantia.
     *
     * @return Collection<int, GuaranteeMonthlyPosition>
     */
    private function loadManualPositions(Emission $emission, string $referenceMonth): Collection
    {
        return $emission->guaranteeMonthlyPositions()
            ->whereDate('reference_month', $referenceMonth)
            ->get()
            ->keyBy('guarantee_id');
    }

    /**
     * Grandezas da competência que as regras contratuais podem referenciar.
     *
     * Bases ainda sem fonte automática ficam nulas de propósito: o resolvedor de
     * exigência trata nulo como "não apurável" e devolve a regra em texto, em
     * vez de inventar um número.
     *
     * @return array<string, float|null>
     */
    private function buildBaseValues(Emission $emission, ?float $outstandingBalance): array
    {
        $issuedVolume = $emission->issued_volume === null ? null : (float) $emission->issued_volume;

        return [
            GuaranteeRequirementBase::OutstandingBalance->value => $outstandingBalance,
            GuaranteeRequirementBase::IssuedVolume->value => $issuedVolume,
            GuaranteeRequirementBase::IntegralizedValue->value => $outstandingBalance,
            GuaranteeRequirementBase::NextInstallments->value => null,
            GuaranteeRequirementBase::InterestMonths->value => null,
            GuaranteeRequirementBase::Custom->value => null,
        ];
    }

    /**
     * @param  Collection<int, GuaranteePositionData>  $positions
     */
    private function consolidate(
        Collection $positions,
        string $referenceMonth,
        ?float $outstandingBalance,
    ): EmissionGuaranteePositionData {
        $contributing = $positions->filter(
            fn (GuaranteePositionData $position): bool => $position->contributesToCoverage,
        );

        $totalGrossValue = $this->sumOrNull($contributing, fn (GuaranteePositionData $p): ?float => $p->currentValue());
        $totalEligibleValue = $this->sumOrNull($contributing, fn (GuaranteePositionData $p): ?float => $p->eligibleValue);

        $requiredRatio = $this->resolveRequiredRatio($contributing);
        $totalRequiredValue = $this->resolveTotalRequiredValue($requiredRatio, $outstandingBalance);

        $coverageRatio = $this->calculateCoverageRatio($totalEligibleValue, $outstandingBalance);
        $surplusDeficit = ($totalEligibleValue === null || $totalRequiredValue === null)
            ? null
            : round($totalEligibleValue - $totalRequiredValue, 2);

        $hasPendingValues = $contributing->contains(fn (GuaranteePositionData $p): bool => $p->isPending());

        return new EmissionGuaranteePositionData(
            referenceMonth: $referenceMonth,
            positions: $positions->values(),
            totalGrossValue: $totalGrossValue,
            totalEligibleValue: $totalEligibleValue,
            totalRequiredValue: $totalRequiredValue,
            outstandingBalance: $outstandingBalance,
            coverageRatio: $coverageRatio,
            requiredRatio: $requiredRatio,
            surplusDeficit: $surplusDeficit,
            coverageStatus: GuaranteeCoverageStatus::resolve(
                coverageRatio: $coverageRatio,
                requiredRatio: $requiredRatio,
                hasPendingValues: $hasPendingValues,
                hasRequirement: $requiredRatio !== null,
            ),
            activeGuaranteesCount: $contributing->count(),
            pendingSources: $this->collectPendingSources($contributing),
        );
    }

    /**
     * Mínimo contratual consolidado: o maior percentual sobre saldo devedor
     * entre as garantias vigentes.
     *
     * Quando duas garantias exigem 120% e 130%, a operação só está enquadrada
     * atendendo a mais restritiva — por isso o máximo, e não a soma ou a média.
     *
     * @param  Collection<int, GuaranteePositionData>  $positions
     */
    private function resolveRequiredRatio(Collection $positions): ?float
    {
        $ratios = $positions
            ->map(fn (GuaranteePositionData $position): ?float => $this->outstandingBalanceRatio($position))
            ->filter(fn (?float $ratio): bool => $ratio !== null);

        return $ratios->isEmpty() ? null : round((float) $ratios->max(), 6);
    }

    private function outstandingBalanceRatio(GuaranteePositionData $position): ?float
    {
        $requirement = $position->requirement;

        if ($requirement->basis !== GuaranteeRequirementBasis::Percentage) {
            return null;
        }

        if ($requirement->base !== GuaranteeRequirementBase::OutstandingBalance) {
            return null;
        }

        return $requirement->ratio;
    }

    private function resolveTotalRequiredValue(?float $requiredRatio, ?float $outstandingBalance): ?float
    {
        if ($requiredRatio === null || $outstandingBalance === null) {
            return null;
        }

        return round($outstandingBalance * $requiredRatio, 2);
    }

    /**
     * Cobertura da operação. Saldo devedor zero devolve `null`: sem dívida não
     * há razão de cobertura a apurar, e dividir por zero produziria infinito.
     */
    private function calculateCoverageRatio(?float $totalEligibleValue, ?float $outstandingBalance): ?float
    {
        if ($totalEligibleValue === null || $outstandingBalance === null || $outstandingBalance <= 0) {
            return null;
        }

        return round($totalEligibleValue / $outstandingBalance, 6);
    }

    /**
     * Soma que preserva a ausência: se alguma parcela é desconhecida, o total
     * também é. Somar tratando nulo como zero devolveria um número menor que o
     * real e faria a operação parecer desenquadrada.
     *
     * @param  Collection<int, GuaranteePositionData>  $positions
     */
    private function sumOrNull(Collection $positions, callable $extractor): ?float
    {
        $relevant = $positions->reject(
            fn (GuaranteePositionData $position): bool => $position->coverageStatus === GuaranteeCoverageStatus::NotApplicable,
        );

        if ($relevant->isEmpty()) {
            return null;
        }

        if ($relevant->contains(fn (GuaranteePositionData $position): bool => $extractor($position) === null)) {
            return null;
        }

        return round((float) $relevant->sum(fn (GuaranteePositionData $position): float => (float) $extractor($position)), 2);
    }

    /**
     * @param  Collection<int, GuaranteePositionData>  $positions
     * @return array<int, string>
     */
    private function collectPendingSources(Collection $positions): array
    {
        return $positions
            ->filter(fn (GuaranteePositionData $position): bool => $position->isPending())
            ->map(fn (GuaranteePositionData $position): string => $position->guarantee->display_name)
            ->unique()
            ->values()
            ->all();
    }
}

<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\GuaranteePositionData;
use App\DTOs\Guarantees\ResolvedGuaranteeRequirement;
use App\DTOs\Guarantees\ResolvedGuaranteeValue;
use App\Enums\GuaranteeCoverageStatus;
use App\Enums\GuaranteeLegalStatus;
use App\Enums\GuaranteeValueStatus;
use App\Models\Guarantee;
use App\Models\GuaranteeMonthlyPosition;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Apura a posição de uma garantia numa competência: valor atual, elegível,
 * exigido, cobertura e excedente/déficit (§12 e §14 do escopo).
 *
 * Toda a aritmética da garantia vive aqui — nada de cálculo em Blade, Filament
 * ou controller.
 */
class GuaranteePositionCalculator
{
    public function __construct(
        private readonly GuaranteeValueResolver $valueResolver,
        private readonly GuaranteeRequirementResolver $requirementResolver,
    ) {}

    /**
     * @param  array<string, float|null>  $baseValues
     * @param  Collection<int, GuaranteeMonthlyPosition>  $manualPositions
     */
    public function calculate(
        Guarantee $guarantee,
        string $referenceMonth,
        EmissionOperationalDataset $dataset,
        array $baseValues,
        Collection $manualPositions,
    ): GuaranteePositionData {
        $referenceEnd = Carbon::parse($referenceMonth)->endOfMonth();

        $value = $this->valueResolver->resolve($guarantee, $referenceMonth, $dataset, $manualPositions);
        $eligibilityFactor = $guarantee->resolvedEligibilityFactor();
        $eligibleValue = $this->calculateEligibleValue($value, $eligibilityFactor);

        $requirement = $this->requirementResolver->resolve($guarantee, $baseValues, $value->amount);

        $legalStatus = $guarantee->legalStatusAsOf($referenceEnd);
        $contributes = $guarantee->contributesToCoverageOn($referenceEnd);

        $coverageRatio = $this->calculateCoverageRatio($eligibleValue, $requirement);
        $surplusDeficit = $this->calculateSurplusDeficit($eligibleValue, $requirement);

        return new GuaranteePositionData(
            guarantee: $guarantee,
            referenceMonth: $referenceMonth,
            value: $value,
            requirement: $requirement,
            eligibleValue: $eligibleValue,
            eligibilityFactor: $eligibilityFactor,
            coverageRatio: $coverageRatio,
            surplusDeficit: $surplusDeficit,
            coverageStatus: $this->resolveCoverageStatus($value, $requirement, $coverageRatio, $legalStatus, $contributes),
            legalStatus: $legalStatus,
            contributesToCoverage: $contributes,
        );
    }

    /**
     * Valor elegível = valor atual × fator de elegibilidade (§12).
     *
     * Sem valor atual não há elegível: aplicar o deságio sobre a ausência
     * produziria zero e apagaria a diferença entre "não vale nada" e "não
     * sabemos quanto vale".
     */
    private function calculateEligibleValue(ResolvedGuaranteeValue $value, float $eligibilityFactor): ?float
    {
        if ($value->amount === null) {
            return null;
        }

        return round($value->amount * $eligibilityFactor, 2);
    }

    private function calculateCoverageRatio(?float $eligibleValue, ResolvedGuaranteeRequirement $requirement): ?float
    {
        if ($eligibleValue === null || $requirement->amount === null || $requirement->amount <= 0) {
            return null;
        }

        return round($eligibleValue / $requirement->amount, 6);
    }

    private function calculateSurplusDeficit(?float $eligibleValue, ResolvedGuaranteeRequirement $requirement): ?float
    {
        if ($eligibleValue === null || $requirement->amount === null) {
            return null;
        }

        return round($eligibleValue - $requirement->amount, 2);
    }

    /**
     * Enquadramento da garantia isolada.
     *
     * A ordem das checagens é a regra de negócio: garantia que não compõe a
     * cobertura é "não aplicável"; dado faltante é "pendente"; só depois disso
     * faz sentido comparar valores. Inverter isso transformaria pendência em
     * desenquadramento, que é o erro que o cenário 7 do escopo proíbe.
     */
    private function resolveCoverageStatus(
        ResolvedGuaranteeValue $value,
        ResolvedGuaranteeRequirement $requirement,
        ?float $coverageRatio,
        GuaranteeLegalStatus $legalStatus,
        bool $contributesToCoverage,
    ): GuaranteeCoverageStatus {
        if (! $contributesToCoverage || $legalStatus->isClosed()) {
            return GuaranteeCoverageStatus::NotApplicable;
        }

        if (! $requirement->exists()) {
            return GuaranteeCoverageStatus::NotApplicable;
        }

        // Garantia sem posição monetária (aval, fiança) não é apurável em
        // valor, mesmo tendo um limite contratual registrado.
        if ($value->status === GuaranteeValueStatus::NotApplicable) {
            return GuaranteeCoverageStatus::NotApplicable;
        }

        if ($value->isPending()) {
            return GuaranteeCoverageStatus::PendingUpdate;
        }

        if ($requirement->isUncomputable() || $coverageRatio === null) {
            return GuaranteeCoverageStatus::InsufficientData;
        }

        return GuaranteeCoverageStatus::resolve(
            coverageRatio: $coverageRatio,
            requiredRatio: 1.0,
            hasPendingValues: false,
        );
    }
}

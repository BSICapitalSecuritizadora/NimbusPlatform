<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuBaselineCandidate;
use App\Domain\PuCalculator\DTOs\PuBaselineRequirement;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementCategory;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementSeverity;
use App\Domain\PuCalculator\Enums\PuBaselineRequirementStatus;

/**
 * O que a engine sabe fazer com o candidato comprovado.
 *
 * Nenhuma regra aqui conhece emissão, IF ou ISIN: cada requisito compara uma
 * capacidade declarada da engine com o que o contrato comprovou. Quando o
 * contrato exige algo que a engine não implementa, o gate bloqueia em vez de
 * gerar curva com uma aproximação silenciosa.
 */
final class PuBaselineEngineCapabilityService
{
    /**
     * Percentual do indexador que a engine consegue representar.
     *
     * `emission_pu_parameters` não possui coluna de percentual e
     * `CdiFactorCompositionService` compõe o fator diário direto da taxa do
     * índice — ou seja, 100%. Um contrato com percentual diferente não é
     * calculável hoje, e o gate precisa dizer isso.
     */
    private const SUPPORTED_INDEX_PERCENTAGE = '100.00000000';

    /** @return array<string, PuBaselineRequirement> */
    public function requirements(PuBaselineCandidate $candidate): array
    {
        $requirements = [];
        $indexerSupported = $candidate->indexer?->isHomologated() ?? false;
        $requirements['engine_calculation_capability'] = $this->requirement(
            code: 'engine_calculation_capability',
            name: 'Método de cálculo do indexador',
            capability: 'calculation.'.($candidate->configuration['calculation_method'] ?? 'pending'),
            supported: $indexerSupported,
        );

        $percentage = $candidate->configuration['index_percentage'] ?? null;

        // PENDING já é bloqueado pelo requisito contratual do indexador; repetir
        // aqui só produziria uma segunda mensagem sobre a mesma ausência.
        if (is_string($percentage) && is_numeric($percentage)) {
            $requirements['engine_index_percentage_capability'] = $this->requirement(
                code: 'engine_index_percentage_capability',
                name: 'Percentual do indexador representável',
                capability: 'index_percentage.'.$percentage,
                supported: $percentage === self::SUPPORTED_INDEX_PERCENTAGE,
                expected: self::SUPPORTED_INDEX_PERCENTAGE,
                found: $percentage,
            );
        }

        if ($candidate->indexer?->requiresIndexRates() ?? true) {
            $requirements['engine_index_lookup_capability'] = $this->requirement(
                code: 'engine_index_lookup_capability',
                name: 'Consulta de snapshots do índice',
                capability: 'index_lookup.'.($candidate->configuration['index_rate_lookup_mode'] ?? 'pending'),
                supported: $candidate->lookupMode !== null,
            );
        }

        if ($candidate->configuration['first_coupon_pre_integralization_premium_enabled'] === true) {
            $requirements['engine_opening_premium_capability'] = $this->requirement(
                code: 'engine_opening_premium_capability',
                name: 'Prêmio pré-integralização do primeiro cupom',
                capability: 'first_coupon_pre_integralization_premium',
                supported: true,
            );
        }

        return $requirements;
    }

    private function requirement(
        string $code,
        string $name,
        string $capability,
        bool $supported,
        mixed $expected = 'supported',
        mixed $found = null,
    ): PuBaselineRequirement {
        return new PuBaselineRequirement(
            code: $code,
            name: $name,
            category: PuBaselineRequirementCategory::EngineCapability,
            status: $supported
                ? PuBaselineRequirementStatus::Satisfied
                : PuBaselineRequirementStatus::Blocking,
            severity: $supported
                ? PuBaselineRequirementSeverity::Information
                : PuBaselineRequirementSeverity::Critical,
            reason: $supported
                ? sprintf('A engine declara suporte à capacidade %s.', $capability)
                : sprintf('A capacidade %s não está homologada ou ainda não pôde ser determinada.', $capability),
            expected: $expected,
            found: $found ?? ($supported ? 'supported' : 'unsupported_or_pending'),
            blocks: ['candidate_configuration', 'numeric_homologation'],
        );
    }
}

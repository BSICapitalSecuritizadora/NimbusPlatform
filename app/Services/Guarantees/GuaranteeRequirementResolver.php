<?php

namespace App\Services\Guarantees;

use App\DTOs\Guarantees\ResolvedGuaranteeRequirement;
use App\Enums\GuaranteeRequirementBase;
use App\Enums\GuaranteeRequirementBasis;
use App\Models\Guarantee;

/**
 * Traduz a regra contratual da garantia em valor exigido na competência (§13).
 *
 * Três formas convivem: valor absoluto ("R$ 5.000.000"), percentual sobre uma
 * base ("120% do saldo devedor") e fórmula por contagem ("3 próximas PMTs",
 * "6 meses de juros"). O que não for computável com os dados da competência
 * volta com valor nulo e o texto literal do contrato — nunca com um número
 * estimado.
 */
class GuaranteeRequirementResolver
{
    /**
     * @param  array<string, float|null>  $baseValues  grandezas da competência,
     *                                                 indexadas pelo valor do enum {@see GuaranteeRequirementBase}
     */
    public function resolve(Guarantee $guarantee, array $baseValues, ?float $currentValue = null): ResolvedGuaranteeRequirement
    {
        return match ($guarantee->resolvedRequirementBasis()) {
            GuaranteeRequirementBasis::None => $this->resolveLegacyMinimum($guarantee),
            GuaranteeRequirementBasis::Absolute => $this->resolveAbsolute($guarantee),
            GuaranteeRequirementBasis::Percentage => $this->resolvePercentage($guarantee, $baseValues, $currentValue),
            GuaranteeRequirementBasis::Formula => $this->resolveFormula($guarantee, $baseValues),
        };
    }

    /**
     * Garantia sem regra tipada, mas com o mínimo do cadastro antigo preenchido.
     *
     * Mantém o comportamento anterior ao módulo para registros que ainda não
     * foram reclassificados, em vez de deixá-los sem exigência nenhuma.
     */
    private function resolveLegacyMinimum(Guarantee $guarantee): ResolvedGuaranteeRequirement
    {
        if ($guarantee->minimum_value === null) {
            return ResolvedGuaranteeRequirement::none();
        }

        return ResolvedGuaranteeRequirement::absolute(
            (float) $guarantee->minimum_value,
            'Valor mínimo cadastrado manualmente.',
        );
    }

    private function resolveAbsolute(Guarantee $guarantee): ResolvedGuaranteeRequirement
    {
        $amount = $guarantee->requirement_value ?? $guarantee->minimum_value;

        if ($amount === null) {
            return ResolvedGuaranteeRequirement::none();
        }

        return ResolvedGuaranteeRequirement::absolute((float) $amount);
    }

    private function resolvePercentage(
        Guarantee $guarantee,
        array $baseValues,
        ?float $currentValue,
    ): ResolvedGuaranteeRequirement {
        $ratio = $guarantee->requirement_percentage;

        if ($ratio === null) {
            return ResolvedGuaranteeRequirement::none();
        }

        $ratio = $this->normalizeRatio((float) $ratio);
        $base = $guarantee->requirement_base ?? GuaranteeRequirementBase::OutstandingBalance;
        $baseValue = $this->baseValue($base, $baseValues, $currentValue);
        $description = sprintf('%s do %s', $this->formatRatio($ratio), mb_strtolower($base->label()));

        if ($baseValue === null) {
            return ResolvedGuaranteeRequirement::percentage(
                null,
                $ratio,
                $base,
                $description,
                ['reason' => 'Base de cálculo indisponível na competência.'],
            );
        }

        return ResolvedGuaranteeRequirement::percentage(
            round($baseValue * $ratio, 2),
            $ratio,
            $base,
            $description,
            ['base_value' => $baseValue],
        );
    }

    /**
     * Fórmulas por contagem. Sem multiplicador ou sem base unitária a regra
     * permanece descrita em texto, e o motor a trata como não apurada — o que
     * leva a operação a "dados insuficientes", não a "enquadrada".
     */
    private function resolveFormula(Guarantee $guarantee, array $baseValues): ResolvedGuaranteeRequirement
    {
        $multiplier = $guarantee->requirement_multiplier;
        $base = $guarantee->requirement_base;
        $literal = $guarantee->requirement_formula;

        if ($multiplier === null || $base === null) {
            return ResolvedGuaranteeRequirement::formula(null, $base, $literal, [
                'reason' => 'Fórmula contratual registrada apenas em texto.',
            ]);
        }

        $unitValue = $baseValues[$base->value] ?? null;
        $description = $literal ?? sprintf(
            '%s × %s',
            rtrim(rtrim(number_format((float) $multiplier, 2, ',', '.'), '0'), ','),
            mb_strtolower($base->label()),
        );

        if ($unitValue === null) {
            return ResolvedGuaranteeRequirement::formula(null, $base, $description, [
                'reason' => 'Base unitária indisponível na competência.',
            ]);
        }

        return ResolvedGuaranteeRequirement::formula(
            round($unitValue * (float) $multiplier, 2),
            $base,
            $description,
            ['unit_value' => $unitValue, 'multiplier' => (float) $multiplier],
        );
    }

    private function baseValue(
        GuaranteeRequirementBase $base,
        array $baseValues,
        ?float $currentValue,
    ): ?float {
        if ($base === GuaranteeRequirementBase::GuaranteeCurrentValue) {
            return $currentValue;
        }

        return $baseValues[$base->value] ?? null;
    }

    /**
     * Aceita "120" e "1.2" como a mesma coisa: os contratos falam em percentual
     * e a digitação varia, mas um mínimo contratual de 120 vezes o saldo
     * devedor não existe no mercado.
     */
    private function normalizeRatio(float $ratio): float
    {
        return $ratio > 10.0 ? $ratio / 100 : $ratio;
    }

    private function formatRatio(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio * 100, 2, ',', '.'), '0'), ',').'%';
    }
}

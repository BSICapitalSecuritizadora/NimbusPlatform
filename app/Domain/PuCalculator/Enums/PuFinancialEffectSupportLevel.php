<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Classificação de um efeito contratual na matriz de suporte financeiro da
 * Fase 5. Nada fora de `FullyCalculated`/`NoFinancialObligation` vira valor: o
 * evento ativo com efeito não suportado bloqueia a geração da curva, com motivo.
 */
enum PuFinancialEffectSupportLevel: string
{
    case FullyCalculated = 'fully_calculated';
    case InformedOnly = 'informed_only';
    case LifecycleOnly = 'lifecycle_only';
    case NoFinancialObligation = 'no_financial_obligation';
    case Deferred = 'deferred';

    public function label(): string
    {
        return match ($this) {
            self::FullyCalculated => 'Calculado na Fase 5',
            self::InformedOnly => 'Valor informado (não calculado pela curva)',
            self::LifecycleOnly => 'Ciclo de vida representado; cálculo financeiro não suportado',
            self::NoFinancialObligation => 'Sem obrigação financeira',
            self::Deferred => 'Adiado para implementação contratual futura',
        };
    }
}

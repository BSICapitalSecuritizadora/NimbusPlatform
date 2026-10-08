<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * Componentes financeiros de uma obrigação, sempre separados.
 *
 * Nenhum componente se funde com outro: juros ordinários não absorvem juros
 * extraordinários nem moratórios; amortização ordinária não absorve a
 * extraordinária nem o principal acelerado; prêmio não é multa. O total esperado
 * de uma obrigação é a soma canônica (2 casas) dos componentes disponíveis.
 *
 * Só a curva oficial calcula juros ordinários, amortização ordinária,
 * amortização extraordinária (de evento explícito) e principal acelerado. Os
 * demais não têm regra contratual de cálculo na Fase 5: ou vêm informados
 * (prêmio do cronograma), ou ficam como não suportados -- nunca como zero.
 */
enum PuObligationComponent: string
{
    case OrdinaryInterest = 'ordinary_interest';
    case OrdinaryAmortization = 'ordinary_amortization';
    case ExtraordinaryInterest = 'extraordinary_interest';
    case ExtraordinaryAmortization = 'extraordinary_amortization';
    case AcceleratedPrincipal = 'accelerated_principal';
    case DefaultInterest = 'default_interest';
    case Premium = 'premium';
    case Penalty = 'penalty';
    case OtherCharges = 'other_charges';

    public function label(): string
    {
        return match ($this) {
            self::OrdinaryInterest => 'Juros ordinários',
            self::OrdinaryAmortization => 'Amortização ordinária',
            self::ExtraordinaryInterest => 'Juros extraordinários',
            self::ExtraordinaryAmortization => 'Amortização extraordinária',
            self::AcceleratedPrincipal => 'Principal acelerado',
            self::DefaultInterest => 'Juros moratórios',
            self::Premium => 'Prêmio',
            self::Penalty => 'Multa',
            self::OtherCharges => 'Outros encargos',
        };
    }

    /**
     * Componente que a curva oficial calcula quando há regra contratual explícita.
     */
    public function isCalculatedByOfficialCurve(): bool
    {
        return in_array($this, [
            self::OrdinaryInterest,
            self::OrdinaryAmortization,
            self::ExtraordinaryAmortization,
            self::AcceleratedPrincipal,
        ], true);
    }
}

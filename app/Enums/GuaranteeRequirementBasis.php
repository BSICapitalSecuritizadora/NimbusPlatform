<?php

namespace App\Enums;

/**
 * Como a regra contratual expressa o mínimo exigido (§13 do escopo).
 *
 * Existe porque nem toda garantia tem valor mínimo fixo: "120% do saldo
 * devedor", "3 próximas PMTs" e "R$ 5.000.000" são regras de naturezas
 * diferentes e precisam ser computáveis, não texto livre.
 */
enum GuaranteeRequirementBasis: string
{
    case None = 'none';
    case Absolute = 'absolute';
    case Percentage = 'percentage';
    case Formula = 'formula';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem mínimo contratual',
            self::Absolute => 'Valor absoluto',
            self::Percentage => 'Percentual sobre base de cálculo',
            self::Formula => 'Fórmula contratual',
        };
    }

    public function requiresBase(): bool
    {
        return $this === self::Percentage || $this === self::Formula;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $basis) {
            $options[$basis->value] = $basis->label();
        }

        return $options;
    }
}

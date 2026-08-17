<?php

namespace App\Enums;

/**
 * Critério que originou o valor registrado numa avaliação (§20 e §21 do escopo).
 */
enum GuaranteeValuationBasis: string
{
    case Appraisal = 'appraisal';
    case NominalValue = 'nominal_value';
    case ShareCapital = 'share_capital';
    case EquityProportional = 'equity_proportional';
    case Contractual = 'contractual';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Appraisal => 'Laudo de avaliação',
            self::NominalValue => 'Valor nominal',
            self::ShareCapital => 'Capital social proporcional',
            self::EquityProportional => 'Patrimônio líquido proporcional',
            self::Contractual => 'Valor informado contratualmente',
            self::Manual => 'Valor manual da competência',
        };
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

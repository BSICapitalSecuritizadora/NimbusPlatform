<?php

namespace App\Enums;

/**
 * Sinal da diferença financeira que uma revisão introduz em um empreendimento:
 * o valor aprovado revisado menos o valor aprovado pela revisão substituída,
 * ambos derivados dos snapshots congelados da Engenharia.
 */
enum MeasurementRevisionDifferenceType: string
{
    case NoDifference = 'no_difference';
    case PositiveDifference = 'positive_difference';
    case NegativeDifference = 'negative_difference';
    case ReferenceUnavailable = 'reference_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::NoDifference => 'Sem diferença',
            self::PositiveDifference => 'Diferença positiva',
            self::NegativeDifference => 'Diferença negativa',
            self::ReferenceUnavailable => 'Sem referência financeira',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NoDifference => 'success',
            self::PositiveDifference => 'info',
            self::NegativeDifference => 'warning',
            self::ReferenceUnavailable => 'gray',
        };
    }

    public static function fromDifference(?string $difference): self
    {
        if ($difference === null) {
            return self::ReferenceUnavailable;
        }

        return match (bccomp($difference, '0', 2)) {
            0 => self::NoDifference,
            1 => self::PositiveDifference,
            default => self::NegativeDifference,
        };
    }
}

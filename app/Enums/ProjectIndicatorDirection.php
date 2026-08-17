<?php

namespace App\Enums;

enum ProjectIndicatorDirection: string
{
    case LessThanOrEqual = 'less_than_or_equal';
    case GreaterThanOrEqual = 'greater_than_or_equal';

    public function label(): string
    {
        return match ($this) {
            self::LessThanOrEqual => 'Menor ou igual ao ideal',
            self::GreaterThanOrEqual => 'Maior ou igual ao ideal',
        };
    }

    public function compactLabel(): string
    {
        return match ($this) {
            self::LessThanOrEqual => '≤ Ideal',
            self::GreaterThanOrEqual => '≥ Ideal',
        };
    }

    public function classify(
        ?float $value,
        ?float $ideal,
        ?float $limit,
    ): ProjectIndicatorClassification {
        if ($value === null || $ideal === null) {
            return ProjectIndicatorClassification::NaoInformado;
        }

        if (! $this->thresholdsAreCoherent($ideal, $limit)) {
            return ProjectIndicatorClassification::NaoInformado;
        }

        if ($this === self::GreaterThanOrEqual) {
            return match (true) {
                $value >= $ideal => ProjectIndicatorClassification::Enquadrado,
                $limit !== null && $value < $limit => ProjectIndicatorClassification::Desenquadrado,
                default => ProjectIndicatorClassification::Analisar,
            };
        }

        return match (true) {
            $value <= $ideal => ProjectIndicatorClassification::Enquadrado,
            $limit !== null && $value > $limit => ProjectIndicatorClassification::Desenquadrado,
            default => ProjectIndicatorClassification::Analisar,
        };
    }

    public function thresholdsAreCoherent(?float $ideal, ?float $limit): bool
    {
        if ($ideal === null || $limit === null) {
            return true;
        }

        return match ($this) {
            self::LessThanOrEqual => $limit >= $ideal,
            self::GreaterThanOrEqual => $limit <= $ideal,
        };
    }

    public function incoherentThresholdsMessage(): string
    {
        return match ($this) {
            self::LessThanOrEqual => 'Para este indicador, o Limite deve ser maior ou igual ao Ideal.',
            self::GreaterThanOrEqual => 'Para este indicador, o Limite deve ser menor ou igual ao Ideal.',
        };
    }
}

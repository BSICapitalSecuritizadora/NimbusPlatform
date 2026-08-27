<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuBaselineRequirementCategory: string
{
    case Contract = 'contract';
    case Governance = 'governance';
    case OperationalData = 'operational_data';
    case EngineCapability = 'engine_capability';
    case IndependentValidation = 'independent_validation';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Regra contratual',
            self::Governance => 'Governança',
            self::OperationalData => 'Dados operacionais',
            self::EngineCapability => 'Capacidade da engine',
            self::IndependentValidation => 'Validação independente',
        };
    }

    public function dimension(): string
    {
        return match ($this) {
            self::Contract, self::EngineCapability => 'contract',
            self::Governance => 'governance',
            self::OperationalData => 'operational_data',
            self::IndependentValidation => 'independent_validation',
        };
    }
}

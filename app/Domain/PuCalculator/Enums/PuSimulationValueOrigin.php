<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

/**
 * De onde veio cada valor usado na simulação. Existe para que a tela nunca
 * apresente uma hipótese do usuário com a mesma autoridade de uma cláusula
 * contratual comprovada.
 */
enum PuSimulationValueOrigin: string
{
    case Contractual = 'contractual';
    case Persisted = 'persisted';
    case SimulationOverride = 'simulation_override';
    case Undefined = 'undefined';

    public function label(): string
    {
        return match ($this) {
            self::Contractual => 'Contratual',
            self::Persisted => 'Persistido',
            self::SimulationOverride => 'Override de simulação',
            self::Undefined => 'Não definido',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Contractual => 'success',
            self::Persisted => 'info',
            self::SimulationOverride => 'warning',
            self::Undefined => 'gray',
        };
    }
}

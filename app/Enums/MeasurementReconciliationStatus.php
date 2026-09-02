<?php

namespace App\Enums;

/**
 * Situação derivada da conciliação financeira de uma medição.
 *
 * O estado não é persistido: nasce da comparação entre o valor esperado --
 * ancorado no snapshot da Engenharia -- e o que foi registrado/informado.
 * Os rótulos são deliberadamente neutros porque uma divergência pode ter
 * motivo legítimo, e nesta V1 ela avisa sem bloquear.
 */
enum MeasurementReconciliationStatus: string
{
    case Matched = 'matched';
    case Under = 'under';
    case Over = 'over';
    case ReferenceUnavailable = 'reference_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Conciliado',
            self::Under => 'Divergência para menos',
            self::Over => 'Divergência para mais',
            self::ReferenceUnavailable => 'Sem referência financeira',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Matched => 'success',
            self::Under, self::Over => 'warning',
            self::ReferenceUnavailable => 'gray',
        };
    }
}

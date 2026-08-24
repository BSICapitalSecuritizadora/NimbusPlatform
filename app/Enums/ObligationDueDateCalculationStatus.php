<?php

namespace App\Enums;

enum ObligationDueDateCalculationStatus: string
{
    case Calculated = 'calculated';
    case AwaitingCalendar = 'awaiting_calendar';
    case Provided = 'provided';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => 'Vencimento calculado',
            self::AwaitingCalendar => 'Aguardando cobertura do calendário',
            self::Provided => 'Vencimento informado',
        };
    }
}

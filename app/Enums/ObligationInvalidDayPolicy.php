<?php

namespace App\Enums;

enum ObligationInvalidDayPolicy: string
{
    case LastValidDay = 'last_valid_day';
    case SkipOccurrence = 'skip_occurrence';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $policy): array => [$policy->value => $policy->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::LastValidDay => 'Usar o último dia válido do mês',
            self::SkipOccurrence => 'Não gerar a competência naquele mês',
        };
    }
}

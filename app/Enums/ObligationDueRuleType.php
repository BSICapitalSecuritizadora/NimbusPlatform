<?php

namespace App\Enums;

enum ObligationDueRuleType: string
{
    case FixedDay = 'fixed_day';
    case LastDay = 'last_day';
    case NthBusinessDay = 'nth_business_day';
    case CalendarDaysAfterCompetenceEnd = 'calendar_days_after_competence_end';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $rule): array => [$rule->value => $rule->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::FixedDay => 'Dia fixo do mês',
            self::LastDay => 'Último dia do mês',
            self::NthBusinessDay => 'Nº dia útil do mês',
            self::CalendarDaysAfterCompetenceEnd => 'N dias corridos após o fim da competência',
        };
    }
}

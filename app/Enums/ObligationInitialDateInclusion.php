<?php

namespace App\Enums;

enum ObligationInitialDateInclusion: string
{
    case Excluded = 'excluded';
    case Included = 'included';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $inclusion): array => [$inclusion->value => $inclusion->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::Excluded => 'Não contar a data do evento',
            self::Included => 'Contar a data do evento, se for dia útil',
        };
    }
}

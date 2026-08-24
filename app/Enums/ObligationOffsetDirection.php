<?php

namespace App\Enums;

enum ObligationOffsetDirection: string
{
    case Before = 'before';
    case After = 'after';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $direction): array => [$direction->value => $direction->label()])
            ->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::Before => 'Antes do evento',
            self::After => 'Após o evento',
        };
    }

    public function step(): int
    {
        return $this === self::After ? 1 : -1;
    }
}

<?php

namespace App\Enums;

enum TableDensity: string
{
    case Comfortable = 'comfortable';
    case Compact = 'compact';

    public function label(): string
    {
        return match ($this) {
            self::Comfortable => __('Confortável'),
            self::Compact => __('Compacta'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Comfortable->value => self::Comfortable->label(),
            self::Compact->value => self::Compact->label(),
        ];
    }
}

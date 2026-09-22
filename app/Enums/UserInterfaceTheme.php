<?php

namespace App\Enums;

enum UserInterfaceTheme: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Light => __('Claro'),
            self::Dark => __('Escuro'),
            self::System => __('Sistema'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Light->value => self::Light->label(),
            self::Dark->value => self::Dark->label(),
            self::System->value => self::System->label(),
        ];
    }
}

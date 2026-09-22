<?php

namespace App\Enums;

enum SidebarBehavior: string
{
    case Expanded = 'expanded';
    case Collapsed = 'collapsed';
    case Remember = 'remember';

    public function label(): string
    {
        return match ($this) {
            self::Expanded => __('Sempre expandida'),
            self::Collapsed => __('Sempre recolhida'),
            self::Remember => __('Lembrar último estado'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Expanded->value => self::Expanded->label(),
            self::Collapsed->value => self::Collapsed->label(),
            self::Remember->value => self::Remember->label(),
        ];
    }
}

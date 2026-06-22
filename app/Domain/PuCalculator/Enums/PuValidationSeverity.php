<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuValidationSeverity: string
{
    case Low = 'baixa';
    case Medium = 'media';
    case High = 'alta';

    public function badgeColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }
}

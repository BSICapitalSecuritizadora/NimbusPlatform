<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuBaselineRequirementStatus: string
{
    case Satisfied = 'satisfied';
    case AdministrativePending = 'administrative_pending';
    case Blocking = 'blocking';
    case Recommended = 'recommended';

    public function symbol(): string
    {
        return match ($this) {
            self::Satisfied => '✓',
            self::AdministrativePending, self::Recommended => '!',
            self::Blocking => '✕',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Satisfied => 'Satisfeito',
            self::AdministrativePending => 'Pendência administrativa',
            self::Blocking => 'Bloqueio',
            self::Recommended => 'Recomendável',
        };
    }
}

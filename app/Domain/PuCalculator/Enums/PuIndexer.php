<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuIndexer: string
{
    case Cdi = 'CDI';
    case Prefixed = 'PREFIXED';
    case Ipca = 'IPCA';

    public function label(): string
    {
        return match ($this) {
            self::Cdi => 'CDI + spread',
            self::Prefixed => 'Prefixado',
            self::Ipca => 'IPCA (em preparação)',
        };
    }

    /**
     * Indexadores que dependem de uma série de índices (index_rates) no período.
     */
    public function requiresIndexRates(): bool
    {
        return match ($this) {
            self::Cdi, self::Ipca => true,
            self::Prefixed => false,
        };
    }

    public function usesSpread(): bool
    {
        return $this === self::Cdi;
    }

    public function usesAnnualRate(): bool
    {
        return $this === self::Prefixed;
    }

    /**
     * Indexadores cuja engine está homologada para uso operacional.
     */
    public function isHomologated(): bool
    {
        return match ($this) {
            self::Cdi, self::Prefixed => true,
            self::Ipca => false,
        };
    }
}

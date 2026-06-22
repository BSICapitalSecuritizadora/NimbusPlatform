<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCalculationMethod: string
{
    case CdiSpread = 'cdi_spread';
    case FixedRate = 'fixed_rate';
    case IpcaCorrected = 'ipca_corrected';

    public function label(): string
    {
        return match ($this) {
            self::CdiSpread => 'CDI + spread (dias úteis)',
            self::FixedRate => 'Prefixado (taxa anual, dias úteis)',
            self::IpcaCorrected => 'IPCA + cupom (experimental)',
        };
    }

    public function engineVersion(): string
    {
        return match ($this) {
            self::CdiSpread => 'phase1-cdi-v1',
            self::FixedRate => 'phase3-fixed-v1',
            self::IpcaCorrected => 'phase3-ipca-experimental',
        };
    }

    public static function forIndexer(PuIndexer $indexer): self
    {
        return match ($indexer) {
            PuIndexer::Cdi => self::CdiSpread,
            PuIndexer::Prefixed => self::FixedRate,
            PuIndexer::Ipca => self::IpcaCorrected,
        };
    }
}

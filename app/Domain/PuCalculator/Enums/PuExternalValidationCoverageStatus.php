<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuExternalValidationCoverageStatus: string
{
    case Full = 'full';
    case Partial = 'partial';
    case None = 'none';
}

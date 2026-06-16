<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuAmortizationType: string
{
    case None = 'none';
    case UnitValue = 'unit_value';
    case Percentage = 'percentage';
    case Residual = 'residual';
}

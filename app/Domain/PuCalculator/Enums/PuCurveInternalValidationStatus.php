<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCurveInternalValidationStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
}

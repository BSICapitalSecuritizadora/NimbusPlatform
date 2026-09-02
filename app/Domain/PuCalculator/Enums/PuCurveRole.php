<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCurveRole: string
{
    case Candidate = 'candidate';
    case Operational = 'operational';
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuCurveExternalValidationStatus: string
{
    case Pending = 'pending';
    case Validated = 'validated';
    case Rejected = 'rejected';
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuExternalValidationDecision: string
{
    case Validate = 'validate';
    case Reject = 'reject';
}

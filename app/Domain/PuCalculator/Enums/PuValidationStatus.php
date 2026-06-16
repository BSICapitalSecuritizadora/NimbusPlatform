<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuValidationStatus: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}

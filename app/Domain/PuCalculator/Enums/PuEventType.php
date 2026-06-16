<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuEventType: string
{
    case InterestPayment = 'interest_payment';
    case Amortization = 'amortization';
}

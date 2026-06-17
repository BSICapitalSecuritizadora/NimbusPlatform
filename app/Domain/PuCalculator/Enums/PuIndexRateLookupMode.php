<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Enums;

enum PuIndexRateLookupMode: string
{
    case PreviousAvailableBusinessDay = 'previous_available_business_day';
    case PreviousCalendarDayExact = 'previous_calendar_day_exact';
    case BusinessDayLagExact = 'business_day_lag_exact';
}

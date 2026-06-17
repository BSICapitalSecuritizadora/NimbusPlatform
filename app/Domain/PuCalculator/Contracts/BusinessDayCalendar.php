<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Contracts;

use Carbon\CarbonImmutable;

interface BusinessDayCalendar
{
    public function isBusinessDay(CarbonImmutable $date, ?string $calendarCode = null): bool;

    public function nextBusinessDay(CarbonImmutable $date, ?string $calendarCode = null): CarbonImmutable;

    public function shiftBusinessDays(CarbonImmutable $date, int $offset, ?string $calendarCode = null): CarbonImmutable;
}

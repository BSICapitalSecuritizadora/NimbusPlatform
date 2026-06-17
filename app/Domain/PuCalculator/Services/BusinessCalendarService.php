<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Models\BusinessCalendarDate;
use Carbon\CarbonImmutable;
use RuntimeException;

class BusinessCalendarService implements BusinessDayCalendar
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function flushCache(): void
    {
        $this->cache = [];
    }

    public function isBusinessDay(CarbonImmutable $date, ?string $calendarCode = null): bool
    {
        $calendarKey = sprintf('%s|%s', $calendarCode ?? 'B3', $date->toDateString());

        if (array_key_exists($calendarKey, $this->cache)) {
            return $this->cache[$calendarKey];
        }

        $storedValue = BusinessCalendarDate::query()
            ->where('calendar_code', $calendarCode ?? 'B3')
            ->whereDate('calendar_date', $date)
            ->value('is_business_day');

        return $this->cache[$calendarKey] = $storedValue !== null
            ? (bool) $storedValue
            : ! $date->isWeekend();
    }

    public function nextBusinessDay(CarbonImmutable $date, ?string $calendarCode = null): CarbonImmutable
    {
        $candidate = $date;

        for ($attempt = 0; $attempt < 370; $attempt++) {
            if ($this->isBusinessDay($candidate, $calendarCode)) {
                return $candidate;
            }

            $candidate = $candidate->addDay();
        }

        throw new RuntimeException('Unable to resolve next business day for the requested date.');
    }

    public function shiftBusinessDays(CarbonImmutable $date, int $offset, ?string $calendarCode = null): CarbonImmutable
    {
        if ($offset === 0) {
            return $date;
        }

        $candidate = $date;
        $remaining = abs($offset);
        $step = $offset > 0 ? 1 : -1;

        for ($attempt = 0; $attempt < 3700; $attempt++) {
            $candidate = $step > 0 ? $candidate->addDay() : $candidate->subDay();

            if (! $this->isBusinessDay($candidate, $calendarCode)) {
                continue;
            }

            $remaining--;

            if ($remaining === 0) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to shift the requested number of business days.');
    }
}

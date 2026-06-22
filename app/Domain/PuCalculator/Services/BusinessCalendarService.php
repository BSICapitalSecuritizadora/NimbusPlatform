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

    /** @var array<string, array<int, bool>> */
    private array $loadedYears = [];

    public function flushCache(): void
    {
        $this->cache = [];
        $this->loadedYears = [];
    }

    public function isBusinessDay(CarbonImmutable $date, ?string $calendarCode = null): bool
    {
        $resolvedCalendarCode = $calendarCode ?? 'B3';
        $calendarKey = sprintf('%s|%s', $resolvedCalendarCode, $date->toDateString());

        if (array_key_exists($calendarKey, $this->cache)) {
            return $this->cache[$calendarKey];
        }

        $this->loadYearIntoCache($resolvedCalendarCode, (int) $date->format('Y'));

        if (array_key_exists($calendarKey, $this->cache)) {
            return $this->cache[$calendarKey];
        }

        return $this->cache[$calendarKey] = ! $date->isWeekend();
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

    private function loadYearIntoCache(string $calendarCode, int $year): void
    {
        if (($this->loadedYears[$calendarCode][$year] ?? false) === true) {
            return;
        }

        $startOfYear = CarbonImmutable::create($year, 1, 1, 0, 0, 0);
        $endOfYear = $startOfYear->endOfYear();

        BusinessCalendarDate::query()
            ->where('calendar_code', $calendarCode)
            ->whereBetween('calendar_date', [$startOfYear->toDateString(), $endOfYear->toDateString()])
            ->get(['calendar_date', 'is_business_day'])
            ->each(function (BusinessCalendarDate $calendarDate) use ($calendarCode): void {
                if ($calendarDate->calendar_date === null) {
                    return;
                }

                $this->cache[sprintf(
                    '%s|%s',
                    $calendarCode,
                    CarbonImmutable::instance($calendarDate->calendar_date)->toDateString(),
                )] = (bool) $calendarDate->is_business_day;
            });

        $this->loadedYears[$calendarCode][$year] = true;
    }
}

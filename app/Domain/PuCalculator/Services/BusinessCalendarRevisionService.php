<?php

namespace App\Domain\PuCalculator\Services;

use App\Models\BusinessCalendarYear;
use Illuminate\Support\Facades\Cache;

final class BusinessCalendarRevisionService
{
    public function current(string $calendarCode, int $year): int
    {
        $key = $this->cacheKey($calendarCode, $year);
        $cached = Cache::get($key);

        if (is_int($cached)) {
            return $cached;
        }

        $revision = (int) (BusinessCalendarYear::query()
            ->where('calendar_code', strtoupper($calendarCode))
            ->where('year', $year)
            ->value('revision') ?? 0);

        Cache::forever($key, $revision);

        return $revision;
    }

    public function publish(BusinessCalendarYear $calendarYear): void
    {
        Cache::forever(
            $this->cacheKey($calendarYear->calendar_code, (int) $calendarYear->year),
            (int) $calendarYear->revision,
        );
    }

    public function forget(string $calendarCode, int $year): void
    {
        Cache::forget($this->cacheKey($calendarCode, $year));
    }

    private function cacheKey(string $calendarCode, int $year): string
    {
        return sprintf('business-calendar:revision:%s:%d', strtoupper($calendarCode), $year);
    }
}

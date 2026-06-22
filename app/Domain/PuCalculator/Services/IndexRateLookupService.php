<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\DTOs\IndexRateData;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;

class IndexRateLookupService implements IndexRateProvider
{
    /** @var array<string, array<string, IndexRateData>> */
    private array $timelineCache = [];

    /** @var array<string, list<string>> */
    private array $timelineDatesCache = [];

    public function flushCache(): void
    {
        $this->timelineCache = [];
        $this->timelineDatesCache = [];
    }

    public function rateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData
    {
        $dates = $this->timelineDates($indexer);
        $targetDate = $date->toDateString();
        $left = 0;
        $right = count($dates) - 1;
        $matchedDate = null;

        while ($left <= $right) {
            $mid = intdiv($left + $right, 2);
            $candidate = $dates[$mid];

            if ($candidate <= $targetDate) {
                $matchedDate = $candidate;
                $left = $mid + 1;

                continue;
            }

            $right = $mid - 1;
        }

        if ($matchedDate === null) {
            return null;
        }

        return $this->timeline($indexer)[$matchedDate] ?? null;
    }

    public function exactRateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData
    {
        return $this->timeline($indexer)[$date->toDateString()] ?? null;
    }

    /**
     * @return array<string, IndexRateData>
     */
    private function timeline(PuIndexer $indexer): array
    {
        if (isset($this->timelineCache[$indexer->value])) {
            return $this->timelineCache[$indexer->value];
        }

        $timeline = [];
        $dates = [];

        IndexRate::query()
            ->forIndexer($indexer)
            ->orderBy('rate_date')
            ->get()
            ->each(function (IndexRate $rate) use (&$timeline, &$dates): void {
                if ($rate->rate_date === null) {
                    return;
                }

                $dateKey = $rate->rate_date->toDateString();
                $timeline[$dateKey] = new IndexRateData(
                    date: CarbonImmutable::instance($rate->rate_date),
                    value: (string) $rate->rate_value,
                    isProjected: (string) $rate->source_reference === 'forward_projection',
                );
                $dates[] = $dateKey;
            });

        $this->timelineDatesCache[$indexer->value] = $dates;

        return $this->timelineCache[$indexer->value] = $timeline;
    }

    /**
     * @return list<string>
     */
    private function timelineDates(PuIndexer $indexer): array
    {
        $this->timeline($indexer);

        return $this->timelineDatesCache[$indexer->value] ?? [];
    }
}

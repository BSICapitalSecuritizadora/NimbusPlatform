<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\DTOs\IndexRateData;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class IndexRateLookupService implements IndexRateProvider
{
    /** @var array<string, Collection<int, IndexRate>> */
    private array $timelineCache = [];

    public function rateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData
    {
        $timeline = $this->timelineCache[$indexer->value] ??= IndexRate::query()
            ->forIndexer($indexer)
            ->orderBy('rate_date')
            ->get();

        /** @var IndexRate|null $matchingRate */
        $matchingRate = $timeline
            ->filter(fn (IndexRate $rate): bool => $rate->rate_date?->toDateString() <= $date->toDateString())
            ->last();

        if (! $matchingRate instanceof IndexRate || $matchingRate->rate_date === null) {
            return null;
        }

        return new IndexRateData(
            date: CarbonImmutable::instance($matchingRate->rate_date),
            value: (string) $matchingRate->rate_value,
        );
    }
}

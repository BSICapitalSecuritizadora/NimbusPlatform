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

    public function flushCache(): void
    {
        $this->timelineCache = [];
    }

    public function rateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData
    {
        /** @var IndexRate|null $matchingRate */
        $matchingRate = $this->timeline($indexer)
            ->filter(fn (IndexRate $rate): bool => $rate->rate_date?->toDateString() <= $date->toDateString())
            ->last();

        return $this->mapToData($matchingRate);
    }

    public function exactRateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData
    {
        /** @var IndexRate|null $matchingRate */
        $matchingRate = $this->timeline($indexer)
            ->first(fn (IndexRate $rate): bool => $rate->rate_date?->toDateString() === $date->toDateString());

        return $this->mapToData($matchingRate);
    }

    /**
     * @return Collection<int, IndexRate>
     */
    private function timeline(PuIndexer $indexer): Collection
    {
        return $this->timelineCache[$indexer->value] ??= IndexRate::query()
            ->forIndexer($indexer)
            ->orderBy('rate_date')
            ->get();
    }

    private function mapToData(?IndexRate $matchingRate): ?IndexRateData
    {
        if (! $matchingRate instanceof IndexRate || $matchingRate->rate_date === null) {
            return null;
        }

        return new IndexRateData(
            date: CarbonImmutable::instance($matchingRate->rate_date),
            value: (string) $matchingRate->rate_value,
            isProjected: (string) $matchingRate->source_reference === 'forward_projection',
        );
    }
}

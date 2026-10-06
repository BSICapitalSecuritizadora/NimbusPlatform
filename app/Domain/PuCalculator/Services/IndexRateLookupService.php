<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\Contracts\RealizedIndexRateProvider;
use App\Domain\PuCalculator\DTOs\IndexRateData;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;

/**
 * Linha do tempo de `index_rates` em memória, por indexador.
 *
 * Ciclo de vida: o container a registra como `scoped`, então cada requisição e
 * cada job da fila (o worker esquece as instâncias `scoped` antes de cada job)
 * começa com o cache vazio e relê o banco. Dentro do mesmo job, toda gravação de
 * `IndexRate` pelo Eloquent esvazia o cache ({@see IndexRate::booted()}); escrita
 * em lote pelo query builder chama {@see self::flushCache()} explicitamente.
 */
class IndexRateLookupService implements IndexRateProvider, RealizedIndexRateProvider
{
    /** @var array<string, array<string, IndexRateData>> */
    private array $timelineCache = [];

    /** @var array<string, list<string>> */
    private array $timelineDatesCache = [];

    /** @var array<string, string|null> */
    private array $latestRealizedDateCache = [];

    public function flushCache(): void
    {
        $this->timelineCache = [];
        $this->timelineDatesCache = [];
        $this->latestRealizedDateCache = [];
    }

    /**
     * Última linha em data igual ou anterior, de qualquer natureza.
     *
     * Primitiva de inventário. NUNCA alimenta o cálculo realizado: repetir a
     * última taxa conhecida numa data cuja taxa ainda não existe é projeção, e o
     * cálculo realizado só aceita {@see self::realizedRateForDate()}.
     */
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

    /**
     * A linha exata da data, publicada OU projetada. Quem consome projeção (IPCA
     * sob política de mercado) decide o que aceitar pela natureza devolvida.
     */
    public function exactRateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData
    {
        return $this->timeline($indexer)[$date->toDateString()] ?? null;
    }

    public function realizedRateForDate(PuIndexer $indexer, CarbonImmutable $date): ?IndexRateData
    {
        $rate = $this->exactRateForDate($indexer, $date);

        return $rate !== null && ! $rate->isProjected ? $rate : null;
    }

    public function latestRealizedRateDate(PuIndexer $indexer): ?CarbonImmutable
    {
        if (! array_key_exists($indexer->value, $this->latestRealizedDateCache)) {
            $latest = null;

            foreach ($this->timeline($indexer) as $dateKey => $rate) {
                if (! $rate->isProjected) {
                    $latest = $dateKey;
                }
            }

            $this->latestRealizedDateCache[$indexer->value] = $latest;
        }

        $latest = $this->latestRealizedDateCache[$indexer->value];

        return $latest !== null ? CarbonImmutable::parse($latest)->startOfDay() : null;
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
            ->with('projectionSeries:id,status')
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
                    isProjected: $rate->isProjectedRate(),
                    source: $rate->source,
                    projectionSource: $rate->projection_source,
                    projectionReferenceDate: $rate->projection_reference_date !== null
                        ? CarbonImmutable::instance($rate->projection_reference_date)
                        : null,
                    projectionSeriesId: $rate->index_projection_series_id,
                    projectionSeriesStatus: $rate->projectionSeries?->status?->value,
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

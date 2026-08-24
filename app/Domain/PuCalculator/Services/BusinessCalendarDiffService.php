<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Models\BusinessCalendarDate;
use App\Models\BusinessCalendarYear;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class BusinessCalendarDiffService
{
    public function __construct(
        private readonly BusinessCalendarCatalogService $catalog,
        private readonly BusinessCalendarYearService $yearService,
    ) {}

    /** @return array<string, mixed> */
    public function compare(
        string $calendarA,
        string $calendarB,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        if ($to->lt($from)) {
            throw new InvalidArgumentException('A data final não pode ser anterior à data inicial.');
        }

        if ($from->diffInDays($to) > 3660) {
            throw new InvalidArgumentException('A comparação administrativa está limitada a dez anos por execução.');
        }

        $calendarA = $this->catalog->findOrFail($calendarA)->code;
        $calendarB = $this->catalog->findOrFail($calendarB)->code;
        $coverageA = $this->yearService->coverageForRange($calendarA, $from, $to);
        $coverageB = $this->yearService->coverageForRange($calendarB, $from, $to);

        $persistedDates = BusinessCalendarDate::query()
            ->whereIn('calendar_code', [$calendarA, $calendarB])
            ->whereDate('calendar_date', '>=', $from->toDateString())
            ->whereDate('calendar_date', '<=', $to->toDateString())
            ->get()
            ->groupBy('calendar_code')
            ->map(fn ($dates) => $dates->keyBy(fn (BusinessCalendarDate $date): string => $date->calendar_date?->toDateString() ?? ''));
        $yearMetadata = BusinessCalendarYear::query()
            ->whereIn('calendar_code', [$calendarA, $calendarB])
            ->whereBetween('year', [$from->year, $to->year])
            ->get()
            ->keyBy(fn (BusinessCalendarYear $year): string => $year->calendar_code.'|'.$year->year);

        $divergences = [];
        $identicalDates = 0;
        $businessOnlyA = 0;
        $businessOnlyB = 0;
        $metadataDifferences = 0;

        for ($date = $from->startOfDay(); $date->lte($to); $date = $date->addDay()) {
            $dateKey = $date->toDateString();
            $decisionA = $this->decision(
                $date,
                $persistedDates->get($calendarA)?->get($dateKey),
                $yearMetadata->get($calendarA.'|'.$date->year),
                $coverageA[$date->year]['state'],
            );
            $decisionB = $this->decision(
                $date,
                $persistedDates->get($calendarB)?->get($dateKey),
                $yearMetadata->get($calendarB.'|'.$date->year),
                $coverageB[$date->year]['state'],
            );

            if ($decisionA['is_business_day'] !== $decisionB['is_business_day']) {
                if ($decisionA['is_business_day']) {
                    $businessOnlyA++;
                    $category = 'business_day_only_a';
                } else {
                    $businessOnlyB++;
                    $category = 'business_day_only_b';
                }

                $divergences[] = [
                    'date' => $dateKey,
                    'category' => $category,
                    'differences' => ['business_day'],
                    'a' => $decisionA,
                    'b' => $decisionB,
                ];

                continue;
            }

            $identicalDates++;
            $differences = $this->metadataDifferences($decisionA, $decisionB);

            if ($differences !== []) {
                $metadataDifferences++;
                $divergences[] = [
                    'date' => $dateKey,
                    'category' => 'metadata_difference',
                    'differences' => $differences,
                    'a' => $decisionA,
                    'b' => $decisionB,
                ];
            }
        }

        return [
            'calendar_a' => $calendarA,
            'calendar_b' => $calendarB,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_dates' => (int) $from->diffInDays($to) + 1,
            'identical_dates' => $identicalDates,
            'business_day_only_a' => $businessOnlyA,
            'business_day_only_b' => $businessOnlyB,
            'non_business_day_only_a' => $businessOnlyB,
            'non_business_day_only_b' => $businessOnlyA,
            'metadata_differences' => $metadataDifferences,
            'identical_calendars' => $divergences === [],
            'coverage' => ['a' => $coverageA, 'b' => $coverageB],
            'divergences' => $divergences,
        ];
    }

    /** @return array<string, mixed> */
    private function decision(
        CarbonImmutable $date,
        ?BusinessCalendarDate $persistedDate,
        ?BusinessCalendarYear $calendarYear,
        string $coverageState,
    ): array {
        if ($persistedDate instanceof BusinessCalendarDate) {
            return [
                'is_business_day' => (bool) $persistedDate->is_business_day,
                'description' => $persistedDate->description ?: ($persistedDate->is_business_day ? 'Dia útil persistido.' : 'Dia não útil persistido.'),
                'source' => $persistedDate->source ?? $persistedDate->data_origin ?? 'legacy_unclassified',
                'source_document' => $persistedDate->source_document ?? $calendarYear?->source_document,
                'source_revision' => $persistedDate->source_revision ?? $calendarYear?->source_revision,
                'revision' => (int) ($persistedDate->revision ?? $calendarYear?->revision ?? 0),
                'year_status' => $calendarYear?->status,
                'coverage_state' => $coverageState,
                'persisted' => true,
            ];
        }

        $isBusinessDay = ! $date->isWeekend();

        return [
            'is_business_day' => $isBusinessDay,
            'description' => $isBusinessDay
                ? 'Dia útil inferido pela ausência de exceção persistida (fallback legado).'
                : 'Dia não útil inferido exclusivamente por ser final de semana.',
            'source' => 'inferred',
            'source_document' => $calendarYear?->source_document,
            'source_revision' => $calendarYear?->source_revision,
            'revision' => (int) ($calendarYear?->revision ?? 0),
            'year_status' => $calendarYear?->status,
            'coverage_state' => $coverageState,
            'persisted' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $decisionA
     * @param  array<string, mixed>  $decisionB
     * @return list<string>
     */
    private function metadataDifferences(array $decisionA, array $decisionB): array
    {
        if (! $decisionA['persisted'] && ! $decisionB['persisted']) {
            return [];
        }

        $differences = [];

        foreach (['description', 'source', 'source_document', 'source_revision'] as $field) {
            if ($decisionA[$field] !== $decisionB[$field]) {
                $differences[] = $field;
            }
        }

        if (($decisionA['persisted'] || $decisionB['persisted']) && $decisionA['revision'] !== $decisionB['revision']) {
            $differences[] = 'revision';
        }

        return $differences;
    }
}

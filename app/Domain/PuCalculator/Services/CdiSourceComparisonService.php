<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\CdiSourceComparisonResult;
use App\Domain\PuCalculator\DTOs\CdiSourceDataset;
use App\Domain\PuCalculator\DTOs\CdiSourceRecord;
use Carbon\CarbonImmutable;

final class CdiSourceComparisonService
{
    public function compare(
        CdiSourceDataset $b3,
        CdiSourceDataset $bcb,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): CdiSourceComparisonResult {
        $b3ByDate = $this->groupByDate($b3, $from, $to);
        $bcbByDate = $this->groupByDate($bcb, $from, $to);
        $dates = collect([...array_keys($b3ByDate), ...array_keys($bcbByDate)])
            ->unique()
            ->sort()
            ->values();

        $rows = [];
        $byYear = [];
        $summary = $this->emptyCounters();
        $onlyB3Dates = [];
        $onlyBcbDates = [];
        $valueDivergences = [];

        foreach ($dates as $date) {
            $b3Records = $b3ByDate[$date] ?? [];
            $bcbRecords = $bcbByDate[$date] ?? [];
            $status = $this->classify($b3Records, $bcbRecords);
            $b3Record = $b3Records[0] ?? null;
            $bcbRecord = $bcbRecords[0] ?? null;
            $difference = $status === 'present_different'
                ? $this->absoluteDifference((string) $b3Record?->normalizedValue, (string) $bcbRecord?->normalizedValue)
                : null;

            $row = [
                'date' => $date,
                'status' => $status,
                'b3_raw_value' => $b3Record?->rawValue,
                'b3_normalized_value' => $b3Record?->normalizedValue,
                'b3_source_reference' => $b3Record?->sourceReference,
                'bcb_raw_value' => $bcbRecord?->rawValue,
                'bcb_normalized_value' => $bcbRecord?->normalizedValue,
                'bcb_source_reference' => $bcbRecord?->sourceReference,
                'absolute_difference_percentage_points' => $difference,
                'difference_bps' => $difference === null ? null : bcmul($difference, '100', 2),
                'b3_occurrences' => count($b3Records),
                'bcb_occurrences' => count($bcbRecords),
            ];
            $rows[] = $row;
            $summary[$status]++;

            $year = (int) substr($date, 0, 4);
            $byYear[$year] ??= $this->emptyCounters();
            $byYear[$year][$status]++;

            if ($status === 'only_b3') {
                $onlyB3Dates[] = $date;
            } elseif ($status === 'only_bcb') {
                $onlyBcbDates[] = $date;
            } elseif ($status === 'present_different') {
                $valueDivergences[] = $row;
            }
        }

        ksort($byYear);
        $commonDates = $summary['present_equal'] + $summary['present_different'];
        $maximumDivergence = collect($valueDivergences)
            ->sort(fn (array $left, array $right): int => bccomp(
                (string) $right['absolute_difference_percentage_points'],
                (string) $left['absolute_difference_percentage_points'],
                2,
            ))
            ->first();

        $summary = [
            ...$summary,
            'b3_records' => $b3->countBetween($from, $to),
            'bcb_records' => $bcb->countBetween($from, $to),
            'union_dates' => count($rows),
            'common_dates' => $commonDates,
            'only_b3_dates' => $onlyB3Dates,
            'only_bcb_dates' => $onlyBcbDates,
            'first_value_divergence' => $valueDivergences[0] ?? null,
            'maximum_value_divergence' => $maximumDivergence,
            'b3_dataset_issues' => count($b3->issues),
            'bcb_dataset_issues' => count($bcb->issues),
        ];

        return new CdiSourceComparisonResult($summary, $byYear, $rows);
    }

    /** @return array<string, list<CdiSourceRecord>> */
    private function groupByDate(CdiSourceDataset $dataset, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return collect($dataset->recordsBetween($from, $to))
            ->groupBy(fn (CdiSourceRecord $record): string => $record->referenceDate?->toDateString() ?? '')
            ->map(fn ($records): array => $records->values()->all())
            ->all();
    }

    /**
     * @param  list<CdiSourceRecord>  $b3Records
     * @param  list<CdiSourceRecord>  $bcbRecords
     */
    private function classify(array $b3Records, array $bcbRecords): string
    {
        if (count($b3Records) > 1 || count($bcbRecords) > 1) {
            return 'duplicate_date';
        }

        $b3 = $b3Records[0] ?? null;
        $bcb = $bcbRecords[0] ?? null;

        if (($b3 !== null && ($b3->issue !== null || $b3->normalizedValue === null))
            || ($bcb !== null && ($bcb->issue !== null || $bcb->normalizedValue === null))) {
            return 'invalid_value';
        }

        if ($b3 === null) {
            return 'only_bcb';
        }

        if ($bcb === null) {
            return 'only_b3';
        }

        return bccomp((string) $b3->normalizedValue, (string) $bcb->normalizedValue, 2) === 0
            ? 'present_equal'
            : 'present_different';
    }

    /** @return array<string, int> */
    private function emptyCounters(): array
    {
        return [
            'present_equal' => 0,
            'present_different' => 0,
            'only_b3' => 0,
            'only_bcb' => 0,
            'invalid_value' => 0,
            'duplicate_date' => 0,
        ];
    }

    private function absoluteDifference(string $left, string $right): string
    {
        $difference = bcsub($left, $right, 2);

        return str_starts_with($difference, '-') ? substr($difference, 1) : $difference;
    }
}

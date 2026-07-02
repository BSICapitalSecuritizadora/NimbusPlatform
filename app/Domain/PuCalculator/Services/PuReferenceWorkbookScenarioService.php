<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\DTOs\SpreadsheetReferenceRowData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\BusinessCalendarDate;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\IndexRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PuReferenceWorkbookScenarioService
{
    public function __construct(
        private readonly PuSpreadsheetReferenceReader $reader,
        private readonly DailyFactorCalculator $dailyFactorCalculator,
        private readonly DecimalRounder $rounder,
        private readonly BusinessDayCalendarService $businessDayCalendarService,
        private readonly IndexRateService $indexRateService,
    ) {}

    /**
     * @return array{
     *     sheet_name: string,
     *     row_count: int,
     *     spread_rate: string,
     *     index_lookup_mode: string,
     *     index_lag_business_days: int,
     *     calendar_rows: int,
     *     index_rate_rows: int,
     *     event_rows: int,
     *     integralization_rows_created: int
     * }
     */
    public function sync(Emission $emission, string $spreadsheetPath): array
    {
        ['sheet_name' => $sheetName, 'rows' => $rows] = $this->reader->read($spreadsheetPath);

        if ($rows === []) {
            throw new InvalidArgumentException('The reference spreadsheet does not contain readable PU daily rows.');
        }

        $businessDayMap = $this->inferBusinessDayMap($rows);
        $lookupMode = $this->inferIndexLookupMode($rows, $businessDayMap);
        $lagBusinessDays = $lookupMode === PuIndexRateLookupMode::BusinessDayLagExact
            ? $this->inferIndexLagBusinessDays($rows, $businessDayMap)
            : 1;
        $spreadRate = $this->inferSpreadRate($rows, 252);

        $calendarRows = 0;
        $indexRateRows = 0;
        $eventRows = 0;
        $integralizationRowsCreated = 0;

        DB::transaction(function () use (
            $emission,
            $spreadsheetPath,
            $rows,
            $businessDayMap,
            $lookupMode,
            $lagBusinessDays,
            $spreadRate,
            &$calendarRows,
            &$indexRateRows,
            &$eventRows,
            &$integralizationRowsCreated,
        ): void {
            $emission->puParameter()->updateOrCreate([], [
                'curve_start_date' => $rows[0]->date->toDateString(),
                'curve_end_date' => $rows[array_key_last($rows)]->date->toDateString(),
                'initial_unit_value' => $rows[0]->unitBaseValue ?? '1000.0000000000000000',
                'spread_rate' => $spreadRate,
                'indexer' => PuIndexer::Cdi->value,
                'business_day_basis' => 252,
                'calendar_code' => 'B3',
                'index_rate_lookup_mode' => $lookupMode->value,
                'index_rate_lag_business_days' => $lagBusinessDays,
                'legacy_projection_enabled' => $emission->puParameter?->legacy_projection_enabled ?? true,
            ]);

            $calendarRows = $this->syncCalendarDates($businessDayMap, 'B3', basename($spreadsheetPath));
            $indexRateRows = $this->syncIndexRates($rows, $businessDayMap, basename($spreadsheetPath));
            $eventRows = $this->syncEvents($emission, $rows, basename($spreadsheetPath));
            $integralizationRowsCreated = $this->syncIntegralizations($emission, $rows);
        });

        $this->businessDayCalendarService->flushCache();
        $this->indexRateService->flushCache();
        $emission->unsetRelation('puParameter');
        $emission->unsetRelation('puEvents');
        $emission->unsetRelation('integralizationHistories');

        return [
            'sheet_name' => $sheetName,
            'row_count' => count($rows),
            'spread_rate' => $spreadRate,
            'index_lookup_mode' => $lookupMode->value,
            'index_lag_business_days' => $lagBusinessDays,
            'calendar_rows' => $calendarRows,
            'index_rate_rows' => $indexRateRows,
            'event_rows' => $eventRows,
            'integralization_rows_created' => $integralizationRowsCreated,
        ];
    }

    /**
     * @param  list<SpreadsheetReferenceRowData>  $rows
     * @return array<string, bool>
     */
    private function inferBusinessDayMap(array $rows): array
    {
        $map = [];
        $previousRow = null;

        foreach ($rows as $index => $row) {
            $dateKey = $row->date->toDateString();

            if ($index === 0) {
                $map[$dateKey] = ! $row->date->isWeekend();
                $previousRow = $row;

                continue;
            }

            if ($row->date->isWeekend()) {
                $map[$dateKey] = false;
            } else {
                $previousDupInterest = $previousRow?->dupInterest ?? 0;
                $currentDupInterest = $row->dupInterest ?? 0;

                $map[$dateKey] = $currentDupInterest > $previousDupInterest
                    || ($previousRow?->hasPayment() && $currentDupInterest === 1);
            }

            $previousRow = $row;
        }

        return $map;
    }

    /**
     * @param  list<SpreadsheetReferenceRowData>  $rows
     * @param  array<string, bool>  $businessDayMap
     */
    private function inferIndexLookupMode(array $rows, array $businessDayMap): PuIndexRateLookupMode
    {
        foreach ($rows as $row) {
            if (
                $row->factorDi !== null
                && bccomp($row->factorDi, '1', 8) === 1
                && ($businessDayMap[$row->date->toDateString()] ?? false) === false
            ) {
                return PuIndexRateLookupMode::PreviousCalendarDayExact;
            }
        }

        return PuIndexRateLookupMode::BusinessDayLagExact;
    }

    /**
     * @param  list<SpreadsheetReferenceRowData>  $rows
     * @param  array<string, bool>  $businessDayMap
     */
    private function inferIndexLagBusinessDays(array $rows, array $businessDayMap): int
    {
        $lagCounts = [];

        foreach ($rows as $row) {
            if (
                $row->indexRateDate === null
                || $row->factorDi === null
                || bccomp($row->factorDi, '1', 8) !== 1
            ) {
                continue;
            }

            $lag = $this->countBusinessDaysBetween(
                $row->indexRateDate,
                $row->date,
                $businessDayMap,
            );

            if ($lag > 0) {
                $lagCounts[] = $lag;
            }
        }

        if ($lagCounts === []) {
            return -1;
        }

        $frequencies = array_count_values($lagCounts);
        arsort($frequencies);

        return -((int) array_key_first($frequencies));
    }

    /**
     * @param  list<SpreadsheetReferenceRowData>  $rows
     */
    private function inferSpreadRate(array $rows, int $businessDayBasis): string
    {
        $candidates = [];

        foreach ($rows as $row) {
            if (
                $row->factorSpread === null
                || $row->dupInterest === null
                || $row->dupInterest <= 0
                || bccomp($row->factorSpread, '1', 9) !== 1
            ) {
                continue;
            }

            $base = $this->dailyFactorCalculator->powRatio(
                $row->factorSpread,
                $businessDayBasis,
                $row->dupInterest,
                DecimalRounder::FACTOR_SCALE,
            );

            $spreadRate = $this->rounder->round(
                bcmul(
                    bcsub($base, '1', DecimalRounder::INTERNAL_SCALE),
                    '100',
                    DecimalRounder::INTERNAL_SCALE,
                ),
                DecimalRounder::RATE_SCALE,
            );

            $candidates[] = $this->rounder->round($spreadRate, 4);

            if (count($candidates) >= 20) {
                break;
            }
        }

        if ($candidates === []) {
            return '0.00000000';
        }

        $frequencies = array_count_values($candidates);
        arsort($frequencies);

        return $this->rounder->round((string) array_key_first($frequencies), DecimalRounder::RATE_SCALE);
    }

    /**
     * @param  array<string, bool>  $businessDayMap
     */
    private function syncCalendarDates(array $businessDayMap, string $calendarCode, string $sourceReference): int
    {
        $timestamp = now();
        $rows = [];

        foreach ($businessDayMap as $date => $isBusinessDay) {
            $rows[] = [
                'calendar_code' => $calendarCode,
                'calendar_date' => $date,
                'is_business_day' => $isBusinessDay,
                'description' => 'reference:'.$sourceReference,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        BusinessCalendarDate::query()->upsert(
            $rows,
            ['calendar_code', 'calendar_date'],
            ['is_business_day', 'description', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * @param  list<SpreadsheetReferenceRowData>  $rows
     * @param  array<string, bool>  $businessDayMap
     */
    private function syncIndexRates(array $rows, array $businessDayMap, string $sourceReference): int
    {
        $explicitRates = [];

        foreach ($rows as $row) {
            if ($row->indexRateDate === null || $row->indexRateValue === null) {
                continue;
            }

            $explicitRates[$row->indexRateDate->toDateString()] = [
                'rate_value' => $row->indexRateValue,
                'source_reference' => $sourceReference,
            ];
        }

        if ($explicitRates === []) {
            return 0;
        }

        ksort($explicitRates);
        $lastExplicitRateDate = array_key_last($explicitRates);
        $lastExplicitRateValue = $explicitRates[$lastExplicitRateDate]['rate_value'];

        foreach ($businessDayMap as $date => $isBusinessDay) {
            if (! $isBusinessDay || $date <= $lastExplicitRateDate || isset($explicitRates[$date])) {
                continue;
            }

            $explicitRates[$date] = [
                'rate_value' => $lastExplicitRateValue,
                'source_reference' => 'forward_projection',
            ];
        }

        ksort($explicitRates);
        $timestamp = now();
        $payload = [];

        foreach ($explicitRates as $date => $rateData) {
            $payload[] = [
                'indexer' => PuIndexer::Cdi->value,
                'rate_date' => $date,
                'rate_value' => $rateData['rate_value'],
                'source' => 'reference_workbook',
                'source_reference' => $rateData['source_reference'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        IndexRate::query()
            ->forIndexer(PuIndexer::Cdi)
            ->where('source', 'reference_workbook')
            ->whereBetween('rate_date', [
                $payload[0]['rate_date'],
                $payload[array_key_last($payload)]['rate_date'],
            ])
            ->delete();

        IndexRate::query()->upsert(
            $payload,
            ['indexer', 'rate_date'],
            ['rate_value', 'source', 'source_reference', 'updated_at'],
        );

        return count($payload);
    }

    /**
     * @param  list<SpreadsheetReferenceRowData>  $rows
     */
    private function syncEvents(Emission $emission, array $rows, string $sourceReference): int
    {
        EmissionPuEvent::query()
            ->where('emission_id', $emission->id)
            ->delete();

        $timestamp = now();
        $payload = [];

        foreach ($rows as $row) {
            if (! $row->hasPayment()) {
                continue;
            }

            $sequence = 1;
            $originalDate = $row->eventOriginalDate?->toDateString() ?? $row->date->toDateString();
            $effectiveDate = $row->eventDueDate?->toDateString() ?? $row->date->toDateString();

            if ($row->hasInterestPayment()) {
                $payload[] = [
                    'emission_id' => $emission->id,
                    'event_type' => PuEventType::InterestPayment->value,
                    'original_date' => $originalDate,
                    'effective_date' => $effectiveDate,
                    'amortization_type' => PuAmortizationType::None->value,
                    'amortization_value' => null,
                    'sequence' => $sequence++,
                    'description' => 'reference:'.$sourceReference,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            if ($row->hasAmortization()) {
                $payload[] = [
                    'emission_id' => $emission->id,
                    'event_type' => PuEventType::Amortization->value,
                    'original_date' => $originalDate,
                    'effective_date' => $effectiveDate,
                    'amortization_type' => PuAmortizationType::UnitValue->value,
                    'amortization_value' => $row->amortizationUnitValue,
                    'sequence' => $sequence++,
                    'description' => 'reference:'.$sourceReference,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        if ($payload !== []) {
            EmissionPuEvent::query()->insert($payload);
        }

        return count($payload);
    }

    /**
     * @param  list<SpreadsheetReferenceRowData>  $rows
     */
    private function syncIntegralizations(Emission $emission, array $rows): int
    {
        $emission->integralizationHistories()
            ->where('investor_fund', 'PU Reference Sync')
            ->delete();

        $created = 0;

        foreach ($rows as $row) {
            $currentQuantity = $row->quantity ?? '0.0000';
            $existingQuantity = $this->existingIntegralizedQuantityOnDate($emission, $row->date);
            $deltaQuantity = $this->rounder->round(
                bcsub($currentQuantity, $existingQuantity, DecimalRounder::INTERNAL_SCALE),
                DecimalRounder::QUANTITY_SCALE,
            );

            if (bccomp($deltaQuantity, '0', DecimalRounder::QUANTITY_SCALE) === 1) {
                $alreadyExists = $emission->integralizationHistories()
                    ->whereDate('date', $row->date)
                    ->where('quantity', $deltaQuantity)
                    ->exists();

                if (! $alreadyExists) {
                    $unitValue = $row->unitBaseValue ?? '1000.00000000';
                    $financialValue = $this->rounder->round(
                        bcmul($deltaQuantity, $unitValue, DecimalRounder::INTERNAL_SCALE),
                        2,
                    );

                    $emission->integralizationHistories()->create([
                        'date' => $row->date->toDateString(),
                        'quantity' => $deltaQuantity,
                        'unit_value' => $this->rounder->round($unitValue, 8),
                        'financial_value' => $financialValue,
                        'investor_fund' => 'PU Reference Sync',
                    ]);

                    $created++;
                }
            }
        }

        return $created;
    }

    private function existingIntegralizedQuantityOnDate(Emission $emission, CarbonImmutable $date): string
    {
        $quantity = $emission->integralizationHistories()
            ->whereDate('date', '<=', $date)
            ->sum('quantity');

        return $this->rounder->round((string) $quantity, DecimalRounder::QUANTITY_SCALE);
    }

    /**
     * @param  array<string, bool>  $businessDayMap
     */
    private function countBusinessDaysBetween(
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        array $businessDayMap,
    ): int {
        $count = 0;

        for ($date = $startDate; $date->lt($endDate); $date = $date->addDay()) {
            if ($businessDayMap[$date->toDateString()] ?? false) {
                $count++;
            }
        }

        return $count;
    }
}

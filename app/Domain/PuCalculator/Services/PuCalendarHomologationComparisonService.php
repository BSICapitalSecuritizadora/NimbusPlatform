<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCalendarHomologationComparison;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;

final class PuCalendarHomologationComparisonService
{
    /** @var list<string> */
    private const COMPARABLE_FIELDS = [
        'is_business_day',
        'dup',
        'index_rate_date',
        'index_rate_value',
        'factor_di',
        'factor_spread',
        'factor_total',
        'interest',
        'amortization',
        'pu',
        'residual',
        'payment',
        'balance',
        'event',
    ];

    /** @var list<string> */
    private const NUMERIC_DIFFERENCE_FIELDS = [
        'dup',
        'index_rate_value',
        'factor_di',
        'factor_spread',
        'factor_total',
        'interest',
        'amortization',
        'pu',
        'residual',
        'payment',
        'balance',
    ];

    public function __construct(
        private readonly PuCurveGenerationService $curveGenerator,
        private readonly PuCalendarHomologationGuard $guard,
    ) {}

    public function compare(
        Emission $emission,
        string $candidateCalendarCode,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): PuCalendarHomologationComparison {
        $emission->loadMissing(['puParameter', 'puEvents', 'integralizationHistories']);
        $activeParameter = $this->guard->activeCdiParameter($emission);
        $candidateCalendar = $this->guard->assertCandidateCalendarAllowed($candidateCalendarCode);
        $this->guard->assertPeriod($activeParameter, $periodStart, $periodEnd);

        $calculationStart = CarbonImmutable::instance($activeParameter->curve_start_date);
        $coverage = $this->guard->calendarCoverageSnapshot(
            $candidateCalendar->code,
            $calculationStart,
            $periodEnd,
            $activeParameter->index_rate_lookup_mode_enum,
            (int) $activeParameter->index_rate_lag_business_days,
        );

        $legacyParameter = $this->scenarioParameter($activeParameter, (string) $activeParameter->calendar_code, $periodEnd);
        $candidateParameter = $this->scenarioParameter($activeParameter, $candidateCalendar->code, $periodEnd);
        $legacyRows = $this->rowsForPeriod($this->generate($emission, $legacyParameter), $periodStart, $periodEnd);
        $candidateRows = $this->rowsForPeriod($this->generate($emission, $candidateParameter), $periodStart, $periodEnd);
        $dates = collect(array_keys($legacyRows))
            ->merge(array_keys($candidateRows))
            ->unique()
            ->sort()
            ->values();
        $dailyDiff = $dates
            ->map(fn (string $date): array => $this->compareDate(
                $date,
                $legacyRows[$date] ?? null,
                $candidateRows[$date] ?? null,
            ))
            ->all();
        $firstDivergence = collect($dailyDiff)->firstWhere('diverges', true);

        if (is_array($firstDivergence)) {
            $firstDivergence['causal_chain'] = $this->causalChain($firstDivergence);
        }

        $summary = [
            'legacy_calendar_code' => (string) $activeParameter->calendar_code,
            'candidate_calendar_code' => $candidateCalendar->code,
            'index_rate_lookup_mode' => $activeParameter->index_rate_lookup_mode_enum->value,
            'index_rate_lag_business_days' => (int) $activeParameter->index_rate_lag_business_days,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'calculation_start' => $calculationStart->toDateString(),
            'rows_compared' => count($dailyDiff),
            'divergent_rows' => collect($dailyDiff)->where('diverges', true)->count(),
            'identical' => $firstDivergence === null,
            'first_divergence_date' => $firstDivergence['date'] ?? null,
            'critical_dates' => collect($dailyDiff)
                ->filter(fn (array $row): bool => ($row['legacy']['is_business_day'] ?? null) !== ($row['candidate']['is_business_day'] ?? null))
                ->pluck('date')
                ->values()
                ->all(),
            'accumulated_impacts' => $this->accumulatedImpacts($dailyDiff, $firstDivergence, $emission, $periodEnd),
            'governance_warnings' => collect($coverage)
                ->reject(fn (array $snapshot): bool => ($snapshot['state'] ?? null) === 'confirmed')
                ->map(fn (array $snapshot, int $year): string => sprintf(
                    '%s/%d possui cobertura completa, mas governança %s.',
                    $candidateCalendar->code,
                    $year,
                    $snapshot['state'] ?? 'não confirmada',
                ))
                ->values()
                ->all(),
        ];
        $checksumPayload = [
            'emission_id' => $emission->getKey(),
            'legacy' => $this->parameterSnapshot($legacyParameter),
            'candidate' => $this->parameterSnapshot($candidateParameter),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'calendar_governance' => $coverage,
            'daily_diff' => $dailyDiff,
        ];
        $checksum = hash('sha256', json_encode($checksumPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new PuCalendarHomologationComparison(
            dailyDiff: $dailyDiff,
            summary: $summary,
            firstDivergence: $firstDivergence,
            calendarGovernance: $coverage,
            checksum: $checksum,
        );
    }

    /** @return array<string, mixed> */
    public function parameterSnapshot(EmissionPuParameter $parameter): array
    {
        return [
            'curve_start_date' => CarbonImmutable::instance($parameter->curve_start_date)->toDateString(),
            'curve_end_date' => CarbonImmutable::instance($parameter->curve_end_date)->toDateString(),
            'initial_unit_value' => (string) $parameter->initial_unit_value,
            'spread_rate' => (string) $parameter->spread_rate,
            'indexer' => $parameter->indexer,
            'calculation_method' => $parameter->calculation_method,
            'method_version' => $parameter->method_version,
            'rounding_policy' => $parameter->rounding_policy,
            'business_day_basis' => (int) $parameter->business_day_basis,
            'calendar_code' => $parameter->calendar_code,
            'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode_enum->value,
            'index_rate_lag_business_days' => (int) $parameter->index_rate_lag_business_days,
            'legacy_projection_enabled' => false,
        ];
    }

    private function scenarioParameter(
        EmissionPuParameter $activeParameter,
        string $calendarCode,
        CarbonImmutable $periodEnd,
    ): EmissionPuParameter {
        $parameter = $activeParameter->replicate();
        $parameter->forceFill([
            'calendar_code' => $calendarCode,
            'curve_end_date' => $periodEnd->toDateString(),
            'legacy_projection_enabled' => false,
        ]);

        return $parameter;
    }

    /** @return list<PuDailyCurveRowData> */
    private function generate(Emission $emission, EmissionPuParameter $parameter): array
    {
        $scenario = clone $emission;
        $scenario->setRelation('puParameter', $parameter);

        return $this->curveGenerator->handle($scenario)->rows;
    }

    /**
     * @param  list<PuDailyCurveRowData>  $rows
     * @return array<string, PuDailyCurveRowData>
     */
    private function rowsForPeriod(array $rows, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return collect($rows)
            ->filter(fn (PuDailyCurveRowData $row): bool => $row->date->betweenIncluded($start, $end))
            ->keyBy(fn (PuDailyCurveRowData $row): string => $row->date->toDateString())
            ->all();
    }

    /** @return array<string, mixed> */
    private function compareDate(
        string $date,
        ?PuDailyCurveRowData $legacyRow,
        ?PuDailyCurveRowData $candidateRow,
    ): array {
        $legacy = $this->rowSnapshot($legacyRow);
        $candidate = $this->rowSnapshot($candidateRow);
        $changedFields = collect(self::COMPARABLE_FIELDS)
            ->filter(fn (string $field): bool => ($legacy[$field] ?? null) !== ($candidate[$field] ?? null))
            ->values()
            ->all();
        $difference = [];

        foreach (self::NUMERIC_DIFFERENCE_FIELDS as $field) {
            $difference[$field] = $this->subtract($candidate[$field] ?? null, $legacy[$field] ?? null);
        }

        $difference['pu_percentage'] = $this->percentageDifference($candidate['pu'] ?? null, $legacy['pu'] ?? null);

        return [
            'date' => $date,
            'legacy' => $legacy,
            'candidate' => $candidate,
            'difference' => $difference,
            'changed_fields' => $changedFields,
            'diverges' => $changedFields !== [],
        ];
    }

    /** @return array<string, mixed> */
    private function rowSnapshot(?PuDailyCurveRowData $row): array
    {
        if (! $row instanceof PuDailyCurveRowData) {
            return [
                'present' => false,
                ...array_fill_keys(self::COMPARABLE_FIELDS, null),
            ];
        }

        return [
            'present' => true,
            'is_business_day' => $row->isBusinessDay,
            'dup' => $row->dupInterest,
            'index_rate_date' => $row->indexRateDate?->toDateString(),
            'index_rate_value' => $row->indexRateValue,
            'factor_di' => $row->factorDi,
            'factor_spread' => $row->factorSpread,
            'factor_total' => $row->factorSpreadDi,
            'interest' => $row->interestRealUnitValue,
            'amortization' => $row->amortizationUnitValue,
            'pu' => $row->updatedUnitValue,
            'residual' => $row->residualUnitValue,
            'payment' => $row->paymentTotalValue,
            'balance' => $row->totalValue,
            'event' => $row->calculationMemory['event_types'] ?? [],
            'interest_payment' => $row->interestPaymentValue,
            'amortization_payment' => $row->amortizationValue,
        ];
    }

    /** @return list<string> */
    private function causalChain(array $firstDivergence): array
    {
        $chain = [];
        $legacy = $firstDivergence['legacy'];
        $candidate = $firstDivergence['candidate'];

        if (($legacy['is_business_day'] ?? null) !== ($candidate['is_business_day'] ?? null)) {
            $chain[] = sprintf(
                'A decisão de dia útil mudou: legacy=%s; candidate=%s.',
                ($legacy['is_business_day'] ?? null) === true ? 'sim' : 'não',
                ($candidate['is_business_day'] ?? null) === true ? 'sim' : 'não',
            );
        }

        if (($legacy['dup'] ?? null) !== ($candidate['dup'] ?? null)) {
            $chain[] = sprintf('O DUP mudou de %s para %s.', $legacy['dup'] ?? '—', $candidate['dup'] ?? '—');
        }

        if (($legacy['index_rate_date'] ?? null) !== ($candidate['index_rate_date'] ?? null)) {
            $chain[] = sprintf(
                'A data resolvida da Taxa DI mudou de %s para %s.',
                $legacy['index_rate_date'] ?? '—',
                $candidate['index_rate_date'] ?? '—',
            );
        }

        if (($legacy['factor_total'] ?? null) !== ($candidate['factor_total'] ?? null)) {
            $chain[] = 'A diferença de calendário/índice propagou-se para o fator Spread × DI.';
        }

        if (($legacy['pu'] ?? null) !== ($candidate['pu'] ?? null)) {
            $chain[] = sprintf(
                'O PU passou de %s para %s (diferença %s).',
                $legacy['pu'] ?? '—',
                $candidate['pu'] ?? '—',
                $firstDivergence['difference']['pu'] ?? '—',
            );
        }

        if ($chain === []) {
            $chain[] = 'Os cenários deixaram de produzir a mesma linha; consulte os campos alterados para a causa terminal.';
        }

        return $chain;
    }

    /**
     * @param  list<array<string, mixed>>  $dailyDiff
     * @param  array<string, mixed>|null  $firstDivergence
     * @return array<string, array<string, mixed>>
     */
    private function accumulatedImpacts(
        array $dailyDiff,
        ?array $firstDivergence,
        Emission $emission,
        CarbonImmutable $periodEnd,
    ): array {
        if ($firstDivergence === null) {
            return [];
        }

        $firstDate = CarbonImmutable::parse((string) $firstDivergence['date']);
        $nextEventDate = collect($dailyDiff)
            ->first(fn (array $row): bool => $row['date'] >= $firstDate->toDateString()
                && (($row['legacy']['event'] ?? []) !== [] || ($row['candidate']['event'] ?? []) !== []))['date'] ?? null;
        $cutoffs = [
            'next_event' => $nextEventDate,
            'end_of_month' => min($firstDate->endOfMonth()->toDateString(), $periodEnd->toDateString()),
            'end_of_curve' => $periodEnd->toDateString(),
        ];

        if ($emission->maturity_date !== null) {
            $maturity = CarbonImmutable::instance($emission->maturity_date);

            if ($maturity->betweenIncluded($firstDate, $periodEnd)) {
                $cutoffs['maturity'] = $maturity->toDateString();
            }
        }

        return collect($cutoffs)
            ->filter()
            ->map(fn (string $cutoff): array => $this->impactUntil($dailyDiff, $firstDate, CarbonImmutable::parse($cutoff)))
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $dailyDiff
     * @return array<string, mixed>
     */
    private function impactUntil(array $dailyDiff, CarbonImmutable $from, CarbonImmutable $cutoff): array
    {
        $rows = collect($dailyDiff)
            ->filter(fn (array $row): bool => $row['date'] >= $from->toDateString() && $row['date'] <= $cutoff->toDateString());
        $last = $rows->last();

        return [
            'cutoff' => $cutoff->toDateString(),
            'pu_difference' => $last['difference']['pu'] ?? null,
            'pu_difference_percentage' => $last['difference']['pu_percentage'] ?? null,
            'interest_position_difference' => $last['difference']['interest'] ?? null,
            'residual_difference' => $last['difference']['residual'] ?? null,
            'balance_difference' => $last['difference']['balance'] ?? null,
            'interest_payment_difference' => $this->sumScenarioDifference($rows->all(), 'interest_payment'),
            'amortization_payment_difference' => $this->sumScenarioDifference($rows->all(), 'amortization_payment'),
            'total_payment_difference' => $this->sumScenarioDifference($rows->all(), 'payment'),
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function sumScenarioDifference(array $rows, string $field): string
    {
        $legacy = '0';
        $candidate = '0';

        foreach ($rows as $row) {
            $legacy = bcadd($legacy, (string) ($row['legacy'][$field] ?? '0'), 16);
            $candidate = bcadd($candidate, (string) ($row['candidate'][$field] ?? '0'), 16);
        }

        return bcsub($candidate, $legacy, 16);
    }

    private function subtract(mixed $candidate, mixed $legacy): ?string
    {
        if ($candidate === null || $legacy === null || ! is_numeric($candidate) || ! is_numeric($legacy)) {
            return null;
        }

        return bcsub((string) $candidate, (string) $legacy, 16);
    }

    private function percentageDifference(mixed $candidate, mixed $legacy): ?string
    {
        if ($candidate === null || $legacy === null || ! is_numeric($candidate) || ! is_numeric($legacy)) {
            return null;
        }

        if (bccomp((string) $legacy, '0', 16) === 0) {
            return null;
        }

        return bcmul(
            bcdiv(bcsub((string) $candidate, (string) $legacy, 16), (string) $legacy, 16),
            '100',
            12,
        );
    }
}

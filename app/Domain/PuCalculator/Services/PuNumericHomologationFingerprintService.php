<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuBaselineReadinessReport;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuNumericPreparationPlan;
use App\Models\BusinessCalendarYear;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;

final class PuNumericHomologationFingerprintService
{
    /**
     * Outer curve decimals are canonicalized to 10 places for the checksum.
     * SQLite stores decimal(24,16) as REAL and cannot round-trip 16 places
     * (production MySQL preserves them exactly); 10 places still exceed the
     * 6-place operational validation while remaining stable on both drivers.
     */
    private const CHECKSUM_DECIMAL_SCALE = 10;

    public function __construct(
        private readonly DecimalRounder $rounder,
    ) {}

    /**
     * @return array{fingerprint:string,payload:array<string,mixed>}
     */
    public function input(
        EmissionPuParameter $parameter,
        PuNumericPreparationPlan $preparation,
        PuBaselineReadinessReport $readiness,
        CarbonImmutable $asOf,
    ): array {
        $rates = array_map(
            fn (array $rate): array => [
                'date' => $rate['date'] ?? null,
                'value' => isset($rate['value']) ? (string) $rate['value'] : null,
                'source' => $rate['source'] ?? null,
                'source_reference' => $rate['source_reference'] ?? null,
                'external_series_code' => $rate['external_series_code'] ?? null,
                'is_projected' => false,
            ],
            $preparation->presentRates,
        );
        usort($rates, fn (array $left, array $right): int => (string) $left['date'] <=> (string) $right['date']);

        $events = array_map(
            fn (array $event): array => [
                'event_type' => $event['event_type'] ?? null,
                'original_date' => $event['original_date'] ?? null,
                'effective_date' => $event['effective_date'] ?? null,
                'amortization_type' => $event['amortization_type'] ?? null,
                'amortization_value' => isset($event['amortization_value'])
                    ? (string) $event['amortization_value']
                    : null,
                'sequence' => (int) ($event['sequence'] ?? 0),
            ],
            $preparation->presentEvents,
        );
        usort($events, fn (array $left, array $right): int => sprintf(
            '%s|%s|%010d',
            (string) $left['effective_date'],
            (string) $left['event_type'],
            $left['sequence'],
        ) <=> sprintf(
            '%s|%s|%010d',
            (string) $right['effective_date'],
            (string) $right['event_type'],
            $right['sequence'],
        ));

        $payload = $this->canonicalize([
            'emission_id' => $preparation->emissionId,
            'parameter_id' => $parameter->id,
            'parameter' => $this->parameterSnapshot($parameter),
            'as_of' => $asOf->toDateString(),
            'homologation_end_date' => $preparation->homologationEndDate,
            'calendar' => [
                'calendar_code' => $parameter->calendar_code,
                'required_from' => $readiness->calendarDiagnostics['required_from'] ?? null,
                'required_to' => $readiness->calendarDiagnostics['required_to'] ?? null,
                'years' => $this->calendarYears($preparation),
            ],
            'required_rate_dates' => $preparation->requiredRateDates,
            'rates' => $rates,
            'events' => $events,
            'engine' => [
                'engine_version' => PuAuditLogService::ENGINE_VERSION,
                'entrypoint' => PuCurveGeneratorService::class,
                'calculation_method' => $parameter->resolvedCalculationMethod()->value,
                'method_version' => $parameter->method_version,
                'rounding_policy' => $parameter->rounding_policy,
                'unit_curve_only' => true,
            ],
        ]);

        return [
            'fingerprint' => hash('sha256', $this->json($payload)),
            'payload' => $payload,
        ];
    }

    /** @param list<PuDailyCurveRowData> $rows */
    public function curveChecksum(array $rows): string
    {
        return hash('sha256', $this->json([
            'engine_version' => PuAuditLogService::ENGINE_VERSION,
            'rows' => $this->curveRows($rows),
        ]));
    }

    /**
     * @param  list<PuDailyCurveRowData>  $rows
     * @return list<array<string, mixed>>
     */
    public function curveRows(array $rows): array
    {
        $canonicalRows = array_map(fn (PuDailyCurveRowData $row): array => [
            'curve_date' => $row->date->toDateString(),
            'is_business_day' => $row->isBusinessDay,
            'unit_base_value' => $this->checksumDecimal($row->unitBaseValue),
            'unit_corrected_value' => $this->checksumDecimal($row->unitCorrectedValue),
            'factor_di' => $this->checksumDecimal($row->factorDi),
            'factor_di_accumulated' => $this->checksumDecimal($row->factorDiAccumulated),
            'factor_spread' => $this->checksumDecimal($row->factorSpread),
            'factor_spread_di' => $this->checksumDecimal($row->factorSpreadDi),
            'interest_real_unit_value' => $this->checksumDecimal($row->interestRealUnitValue),
            'updated_unit_value' => $this->checksumDecimal($row->updatedUnitValue),
            'amortization_ratio' => $this->checksumDecimal($row->amortizationRatio),
            'amortization_unit_value' => $this->checksumDecimal($row->amortizationUnitValue),
            'residual_unit_value' => $this->checksumDecimal($row->residualUnitValue),
            'interest_payment_unit_value' => $this->checksumDecimal($row->interestPaymentUnitValue),
            'payment_total_unit_value' => $this->checksumDecimal($row->paymentTotalUnitValue),
            'dup_correction' => $row->dupCorrection,
            'dut_correction' => $row->dutCorrection,
            'dup_interest' => $row->dupInterest,
            'dut_interest' => $row->dutInterest,
            'index_rate_date' => $row->indexRateDate?->toDateString(),
            'index_rate_value' => $row->indexRateValue,
            'event_original_date' => $row->eventOriginalDate?->toDateString(),
            'event_effective_date' => $row->eventEffectiveDate?->toDateString(),
            'calculation_memory' => $this->calculationMemory($row),
        ], $rows);
        usort($canonicalRows, fn (array $left, array $right): int => $left['curve_date'] <=> $right['curve_date']);

        return $this->canonicalize($canonicalRows);
    }

    /** @return array<string, mixed> */
    public function parameterSnapshot(EmissionPuParameter $parameter): array
    {
        // O calendário de divulgação só entra quando existe: sem ele a defasagem
        // segue o calendário da curva, e os fingerprints já gravados continuam
        // reproduzíveis.
        $indexRateCalendar = filled($parameter->index_rate_calendar_code)
            ? ['index_rate_calendar_code' => $parameter->index_rate_calendar_code]
            : [];

        return $this->canonicalize([
            ...$indexRateCalendar,
            'curve_start_date' => $parameter->curve_start_date?->toDateString(),
            'curve_end_date' => $parameter->curve_end_date?->toDateString(),
            'initial_unit_value' => (string) $parameter->initial_unit_value,
            'spread_rate' => $parameter->spread_rate !== null ? (string) $parameter->spread_rate : null,
            'annual_rate' => $parameter->annual_rate !== null ? (string) $parameter->annual_rate : null,
            'indexer' => $parameter->indexer,
            'calculation_method' => $parameter->resolvedCalculationMethod()->value,
            'method_version' => $parameter->method_version,
            'rounding_policy' => $parameter->rounding_policy,
            'business_day_basis' => (int) $parameter->business_day_basis,
            'calendar_code' => $parameter->calendar_code,
            'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode_enum->value,
            'index_rate_lag_business_days' => (int) $parameter->index_rate_lag_business_days,
            'first_coupon_pre_integralization_premium_enabled' => $parameter->hasFirstCouponPreIntegralizationPremium(),
            'first_coupon_pre_integralization_business_days' => $parameter->first_coupon_pre_integralization_business_days,
            'first_coupon_pre_integralization_apply_index_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_index_factor,
            'first_coupon_pre_integralization_apply_spread_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_spread_factor,
            'index_lag_months' => $parameter->index_lag_months,
            'base_index_date' => $parameter->base_index_date?->toDateString(),
            'correction_frequency' => $parameter->correction_frequency,
            'index_projection_policy' => $parameter->index_projection_policy,
            'legacy_projection_enabled' => (bool) $parameter->legacy_projection_enabled,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function calendarYears(PuNumericPreparationPlan $preparation): array
    {
        $years = collect($preparation->calendarWindow['years'] ?? [])
            ->pluck('year')
            ->filter(fn (mixed $year): bool => is_numeric($year))
            ->map(fn (mixed $year): int => (int) $year)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($years === []) {
            return [];
        }

        return BusinessCalendarYear::query()
            ->where('calendar_code', $preparation->calendarWindow['calendar_code'] ?? '')
            ->whereIn('year', $years)
            ->orderBy('year')
            ->get()
            ->map(fn (BusinessCalendarYear $calendarYear): array => [
                'year' => (int) $calendarYear->year,
                'governance_status' => $calendarYear->status,
                'revision' => (int) $calendarYear->revision,
                'source_revision' => $calendarYear->source_revision,
                'checksum' => $calendarYear->checksum,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function calculationMemory(PuDailyCurveRowData $row): array
    {
        $memory = $row->calculationMemory;
        $eventTypes = array_values(array_map('strval', $memory['event_types'] ?? []));
        sort($eventTypes);
        $premium = $memory['first_coupon_pre_integralization_premium'] ?? null;

        if (is_array($premium)) {
            unset($premium['evidence']);
        }

        return $this->canonicalize([
            'engine_version' => $memory['engine_version'] ?? null,
            'calendar_code' => $memory['calendar_code'] ?? null,
            'index_rate_lookup_mode' => $memory['index_rate_lookup_mode'] ?? null,
            'base_unit_value_raw' => $memory['base_unit_value_raw'] ?? null,
            'factor_di_raw' => $memory['factor_di_raw'] ?? null,
            'factor_di_accumulated_raw' => $memory['factor_di_accumulated_raw'] ?? null,
            'factor_spread_raw' => $memory['factor_spread_raw'] ?? null,
            'factor_spread_di_raw' => $memory['factor_spread_di_raw'] ?? null,
            'factor_spread_di_before_first_coupon_premium_raw' => $memory['factor_spread_di_before_first_coupon_premium_raw'] ?? null,
            'interest_real_unit_value_raw' => $memory['interest_real_unit_value_raw'] ?? null,
            'updated_unit_value_raw' => $memory['updated_unit_value_raw'] ?? null,
            'interest_payment_unit_value_raw' => $memory['interest_payment_unit_value_raw'] ?? null,
            'amortization_unit_value_raw' => $memory['amortization_unit_value_raw'] ?? null,
            'payment_total_unit_value_raw' => $memory['payment_total_unit_value_raw'] ?? null,
            'residual_unit_value_raw' => $memory['residual_unit_value_raw'] ?? null,
            'index_rate_date' => $memory['index_rate_date'] ?? null,
            'index_rate_value' => $memory['index_rate_value'] ?? null,
            'dup_interest' => $memory['dup_interest'] ?? null,
            'dut_interest' => $memory['dut_interest'] ?? null,
            'reset_after_payment' => $memory['reset_after_payment'] ?? false,
            'first_coupon_pre_integralization_premium_applied' => $memory['first_coupon_pre_integralization_premium_applied'] ?? false,
            'first_coupon_pre_integralization_premium' => $premium,
            'event_types' => $eventTypes,
        ]);
    }

    private function checksumDecimal(string $value): string
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return $this->rounder->normalize($value, self::CHECKSUM_DECIMAL_SCALE);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}

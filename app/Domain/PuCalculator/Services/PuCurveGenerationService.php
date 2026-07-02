<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\DTOs\IndexRateData;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IntegralizationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;

class PuCurveGenerationService
{
    public function __construct(
        private readonly BusinessDayCalendar $businessDayCalendar,
        private readonly IndexRateProvider $indexRateProvider,
        private readonly DailyFactorCalculator $dailyFactorCalculator,
        private readonly DecimalRounder $rounder,
    ) {}

    public function handle(Emission $emission): PuCurveGenerationResult
    {
        $emission->loadMissing(['puParameter', 'puEvents', 'integralizationHistories']);

        if ($emission->puParameter === null) {
            throw new InvalidArgumentException('The emission does not have PU calculation parameters configured.');
        }

        $parameter = $emission->puParameter;
        $startDate = CarbonImmutable::instance($parameter->curve_start_date);
        $endDate = CarbonImmutable::instance($parameter->curve_end_date);
        $eventGroups = $this->groupEventsByDate($emission->puEvents);
        $quantityTimeline = $this->buildQuantityTimeline($emission->integralizationHistories);

        $baseUnitValue = $this->rounder->normalize((string) $parameter->initial_unit_value, DecimalRounder::CALCULATION_SCALE);
        $lastResidualUnitValue = $baseUnitValue;
        $factorDiAccumulated = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
        $factorSpread = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
        $businessDaysSinceReset = 0;
        $rows = [];

        for ($currentDate = $startDate; $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            if ($currentDate->isAfter($startDate) && $this->shouldResetAfterPreviousRow($rows)) {
                $baseUnitValue = $lastResidualUnitValue;
                $factorDiAccumulated = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $factorSpread = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $businessDaysSinceReset = 0;
            }

            $isBusinessDay = $this->businessDayCalendar->isBusinessDay($currentDate, $parameter->calendar_code);
            $quantity = $this->quantityForDate($quantityTimeline, $currentDate);
            $rateSnapshot = $this->resolveRateSnapshot($parameter, $currentDate, $isBusinessDay);

            if ($this->reachedRealizedTail($parameter, $currentDate, $startDate, $isBusinessDay, $rateSnapshot)) {
                break;
            }

            if ($currentDate->equalTo($startDate)) {
                $factorDi = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $factorSpread = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
                $factorSpreadDi = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
                $interestRealUnitValue = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
                $updatedUnitValue = $baseUnitValue;
                $dupInterest = 0;
                $dutInterest = 0;
            } else {
                if ($isBusinessDay) {
                    $businessDaysSinceReset++;
                    $factorSpread = $this->dailyFactorCalculator->factorSpreadForBusinessDays(
                        (string) $parameter->spread_rate,
                        $businessDaysSinceReset,
                        (int) $parameter->business_day_basis,
                        DecimalRounder::CALCULATION_SCALE,
                    );
                }

                $shouldApplyDi = $this->shouldApplyDi(
                    $parameter,
                    $isBusinessDay,
                    $rateSnapshot,
                );

                $factorDi = $this->dailyFactorCalculator->factorDiForDay(
                    $rateSnapshot?->value,
                    $shouldApplyDi,
                    (int) $parameter->business_day_basis,
                    DecimalRounder::CALCULATION_SCALE,
                );

                if ($parameter->index_rate_lookup_mode_enum === PuIndexRateLookupMode::BusinessDayLagExact) {
                    $factorDi = $this->rounder->normalize(
                        $this->rounder->round($factorDi, 8),
                        DecimalRounder::CALCULATION_SCALE,
                    );
                }

                $factorDiAccumulated = $this->rounder->round(
                    bcmul($factorDiAccumulated, $factorDi, DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE,
                );

                if ($businessDaysSinceReset === 0) {
                    $factorSpread = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                }

                if ($parameter->index_rate_lookup_mode_enum === PuIndexRateLookupMode::BusinessDayLagExact) {
                    $factorSpread = $this->rounder->normalize(
                        $this->rounder->round($factorSpread, 9),
                        DecimalRounder::CALCULATION_SCALE,
                    );
                }

                $factorSpreadDiBase = $parameter->index_rate_lookup_mode_enum === PuIndexRateLookupMode::BusinessDayLagExact
                    ? $this->rounder->normalize(
                        $this->rounder->round($factorDiAccumulated, 8),
                        DecimalRounder::CALCULATION_SCALE,
                    )
                    : $factorDiAccumulated;

                $factorSpreadDi = $this->rounder->round(
                    bcmul($factorSpreadDiBase, $factorSpread, DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE,
                );
                $interestRealUnitValue = $this->rounder->round(
                    bcmul(
                        $baseUnitValue,
                        bcsub($this->factorSpreadDiForInterest($parameter, $factorSpreadDi), '1', DecimalRounder::CALCULATION_SCALE + 4),
                        DecimalRounder::CALCULATION_SCALE + 4,
                    ),
                    DecimalRounder::CALCULATION_SCALE,
                );
                $updatedUnitValue = $this->rounder->round(
                    bcadd($baseUnitValue, $interestRealUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE,
                );
                $dupInterest = $businessDaysSinceReset;
                $dutInterest = (int) $parameter->business_day_basis;
            }

            $interestPaymentUnitValue = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
            $amortizationUnitValue = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
            $amortizationRatio = $this->rounder->normalize('0', DecimalRounder::UNIT_SCALE);
            $eventOriginalDate = null;
            $eventEffectiveDate = null;
            $groupedEvents = $eventGroups[$currentDate->toDateString()] ?? collect();

            if ($groupedEvents->isNotEmpty()) {
                $interestPaymentUnitValue = $groupedEvents
                    ->contains(fn (EmissionPuEvent $event): bool => $event->event_type_enum === PuEventType::InterestPayment)
                    ? $interestRealUnitValue
                    : $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);

                $remainingAfterInterest = $this->rounder->round(
                    bcsub($updatedUnitValue, $interestPaymentUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE,
                );

                /** @var EmissionPuEvent $event */
                foreach ($groupedEvents as $event) {
                    if ($event->event_type_enum !== PuEventType::Amortization) {
                        continue;
                    }

                    $resolvedAmortization = $this->resolveAmortizationUnitValue(
                        event: $event,
                        baseUnitValue: $baseUnitValue,
                        remainingResidualUnitValue: $this->rounder->round(
                            bcsub($remainingAfterInterest, $amortizationUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                            DecimalRounder::CALCULATION_SCALE,
                        ),
                    );

                    $amortizationUnitValue = $this->rounder->round(
                        bcadd($amortizationUnitValue, $resolvedAmortization, DecimalRounder::CALCULATION_SCALE + 4),
                        DecimalRounder::CALCULATION_SCALE,
                    );

                    if ($event->amortization_type_enum === PuAmortizationType::Percentage && $event->amortization_value !== null) {
                        $amortizationRatio = $this->rounder->round((string) $event->amortization_value, DecimalRounder::UNIT_SCALE);
                    }
                }

                if (bccomp($baseUnitValue, '0', DecimalRounder::UNIT_SCALE) === 1 && bccomp($amortizationUnitValue, '0', DecimalRounder::UNIT_SCALE) === 1 && bccomp($amortizationRatio, '0', DecimalRounder::UNIT_SCALE) === 0) {
                    $amortizationRatio = $this->rounder->round(
                        bcdiv($amortizationUnitValue, $baseUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                        DecimalRounder::UNIT_SCALE,
                    );
                }

                $eventOriginalDate = $groupedEvents
                    ->pluck('original_date')
                    ->filter()
                    ->map(fn ($date) => CarbonImmutable::instance($date))
                    ->sort()
                    ->first();
                $eventEffectiveDate = $currentDate;
            }

            $paymentTotalUnitValue = $this->rounder->round(
                bcadd($interestPaymentUnitValue, $amortizationUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                DecimalRounder::CALCULATION_SCALE,
            );
            $residualUnitValue = $this->rounder->round(
                bcsub($updatedUnitValue, $paymentTotalUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                DecimalRounder::CALCULATION_SCALE,
            );

            if (bccomp($residualUnitValue, '0', DecimalRounder::UNIT_SCALE) < 0) {
                $residualUnitValue = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
            }
            $totalValue = $this->rounder->round(
                bcmul($residualUnitValue, $quantity, DecimalRounder::CALCULATION_SCALE + 4),
                DecimalRounder::TOTAL_SCALE,
            );
            $interestPaymentValue = $this->rounder->round(
                bcmul($interestPaymentUnitValue, $quantity, DecimalRounder::CALCULATION_SCALE + 4),
                DecimalRounder::TOTAL_SCALE,
            );
            $amortizationValue = $this->rounder->round(
                bcmul($amortizationUnitValue, $quantity, DecimalRounder::CALCULATION_SCALE + 4),
                DecimalRounder::TOTAL_SCALE,
            );
            $paymentTotalValue = $this->rounder->round(
                bcmul($paymentTotalUnitValue, $quantity, DecimalRounder::CALCULATION_SCALE + 4),
                DecimalRounder::TOTAL_SCALE,
            );
            $calculationMemory = [
                'engine_version' => PuAuditLogService::ENGINE_VERSION,
                'is_business_day' => $isBusinessDay,
                'calendar_code' => $parameter->calendar_code,
                'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode,
                'base_unit_value_raw' => $baseUnitValue,
                'factor_di_raw' => $factorDi,
                'factor_di_accumulated_raw' => $factorDiAccumulated,
                'factor_spread_raw' => $factorSpread,
                'factor_spread_di_raw' => $factorSpreadDi,
                'interest_real_unit_value_raw' => $interestRealUnitValue,
                'updated_unit_value_raw' => $updatedUnitValue,
                'interest_payment_unit_value_raw' => $interestPaymentUnitValue,
                'amortization_unit_value_raw' => $amortizationUnitValue,
                'payment_total_unit_value_raw' => $paymentTotalUnitValue,
                'residual_unit_value_raw' => $residualUnitValue,
                'quantity_raw' => $quantity,
                'total_value_raw' => $totalValue,
                'payment_total_value_raw' => $paymentTotalValue,
                'index_rate_date' => $rateSnapshot?->reportedDate(),
                'index_rate_value' => $rateSnapshot?->reportedValue(),
                'dup_interest' => $dupInterest,
                'dut_interest' => $dutInterest,
                'reset_after_payment' => bccomp($paymentTotalUnitValue, '0', DecimalRounder::UNIT_SCALE) === 1,
                'event_types' => $groupedEvents
                    ->map(fn (EmissionPuEvent $event): string => $event->event_type)
                    ->values()
                    ->all(),
            ];

            $rows[] = new PuDailyCurveRowData(
                date: $currentDate,
                isBusinessDay: $isBusinessDay,
                unitBaseValue: $this->rounder->round($baseUnitValue, DecimalRounder::UNIT_SCALE),
                unitCorrectedValue: $this->rounder->round($baseUnitValue, DecimalRounder::UNIT_SCALE),
                factorDi: $this->rounder->round($factorDi, DecimalRounder::FACTOR_SCALE),
                factorDiAccumulated: $this->rounder->round($factorDiAccumulated, DecimalRounder::FACTOR_SCALE),
                factorSpread: $this->rounder->round($factorSpread, DecimalRounder::FACTOR_SCALE),
                factorSpreadDi: $this->rounder->round($factorSpreadDi, DecimalRounder::FACTOR_SCALE),
                interestRealUnitValue: $this->rounder->round($interestRealUnitValue, DecimalRounder::UNIT_SCALE),
                updatedUnitValue: $this->rounder->round($updatedUnitValue, DecimalRounder::UNIT_SCALE),
                amortizationRatio: $amortizationRatio,
                amortizationUnitValue: $this->rounder->round($amortizationUnitValue, DecimalRounder::UNIT_SCALE),
                amortizationValue: $amortizationValue,
                residualUnitValue: $this->rounder->round($residualUnitValue, DecimalRounder::UNIT_SCALE),
                quantity: $quantity,
                totalValue: $totalValue,
                interestPaymentUnitValue: $this->rounder->round($interestPaymentUnitValue, DecimalRounder::UNIT_SCALE),
                interestPaymentValue: $interestPaymentValue,
                paymentTotalUnitValue: $this->rounder->round($paymentTotalUnitValue, DecimalRounder::UNIT_SCALE),
                paymentTotalValue: $paymentTotalValue,
                dupCorrection: 0,
                dutCorrection: 0,
                dupInterest: $dupInterest,
                dutInterest: $dutInterest,
                indexRateDate: $rateSnapshot?->reportedDate(),
                indexRateValue: $rateSnapshot?->reportedValue(),
                eventOriginalDate: $eventOriginalDate,
                eventEffectiveDate: $eventEffectiveDate,
                calculationMemory: $calculationMemory,
            );

            $lastResidualUnitValue = $residualUnitValue;
        }

        return new PuCurveGenerationResult($rows);
    }

    /**
     * @param  EloquentCollection<int, EmissionPuEvent>  $events
     * @return array<string, \Illuminate\Support\Collection<int, EmissionPuEvent>>
     */
    private function groupEventsByDate(EloquentCollection $events): array
    {
        return $events
            ->sortBy(fn (EmissionPuEvent $event): string => sprintf(
                '%s|%010d|%010d',
                CarbonImmutable::instance($event->effective_date)->toDateString(),
                $event->sequence,
                $event->id,
            ))
            ->groupBy(fn (EmissionPuEvent $event): string => CarbonImmutable::instance($event->effective_date)->toDateString())
            ->all();
    }

    /**
     * @param  EloquentCollection<int, IntegralizationHistory>  $integralizations
     * @return array<string, string>
     */
    private function buildQuantityTimeline(EloquentCollection $integralizations): array
    {
        $cumulativeQuantity = '0.0000';
        $timeline = [];

        /** @var IntegralizationHistory $integralization */
        foreach ($integralizations->sortBy(fn (IntegralizationHistory $integralization): string => sprintf(
            '%s|%010d',
            $integralization->date !== null ? CarbonImmutable::instance($integralization->date)->toDateString() : '9999-12-31',
            $integralization->id,
        )) as $integralization) {
            if ($integralization->date === null) {
                continue;
            }

            $cumulativeQuantity = $this->rounder->round(
                bcadd($cumulativeQuantity, (string) $integralization->quantity, DecimalRounder::INTERNAL_SCALE),
                DecimalRounder::QUANTITY_SCALE,
            );
            $timeline[CarbonImmutable::instance($integralization->date)->toDateString()] = $cumulativeQuantity;
        }

        return $timeline;
    }

    /**
     * @param  array<string, string>  $quantityTimeline
     */
    private function quantityForDate(array $quantityTimeline, CarbonImmutable $date): string
    {
        $quantity = '0.0000';

        foreach ($quantityTimeline as $timelineDate => $timelineQuantity) {
            if ($timelineDate > $date->toDateString()) {
                break;
            }

            $quantity = $timelineQuantity;
        }

        return $quantity;
    }

    private function shouldResetAfterPreviousRow(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        /** @var PuDailyCurveRowData $lastRow */
        $lastRow = $rows[array_key_last($rows)];

        return $lastRow->hasPayment();
    }

    private function resolveAmortizationUnitValue(
        EmissionPuEvent $event,
        string $baseUnitValue,
        string $remainingResidualUnitValue,
    ): string {
        $resolvedValue = match ($event->amortization_type_enum) {
            PuAmortizationType::None => $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE),
            PuAmortizationType::Residual => $remainingResidualUnitValue,
            PuAmortizationType::Percentage => $this->rounder->round(
                bcmul(
                    $baseUnitValue,
                    (string) ($event->amortization_value ?? '0'),
                    DecimalRounder::CALCULATION_SCALE + 4,
                ),
                DecimalRounder::CALCULATION_SCALE,
            ),
            PuAmortizationType::UnitValue => $this->rounder->normalize(
                (string) ($event->amortization_value ?? '0'),
                DecimalRounder::CALCULATION_SCALE,
            ),
        };

        if (bccomp($resolvedValue, $remainingResidualUnitValue, DecimalRounder::CALCULATION_SCALE) === 1) {
            return $remainingResidualUnitValue;
        }

        return $resolvedValue;
    }

    /**
     * No modo de offset exato do CDI a curva realizada termina no último dia útil cujo índice já foi
     * publicado. Uma data-alvo sem CDI (futuro ainda não divulgado) não é projetada silenciosamente:
     * apenas trunca a geração. A parte futura entra automaticamente quando o índice for sincronizado.
     * O lookup do BusinessDayLagExact sempre cai em um dia útil, então um snapshot nulo significa
     * genuinamente "sem CDI publicado" — diferente do PreviousCalendarDayExact, cujo alvo pode ser
     * um fim de semana sem cotação por natureza.
     */
    private function reachedRealizedTail(
        EmissionPuParameter $parameter,
        CarbonImmutable $currentDate,
        CarbonImmutable $startDate,
        bool $isBusinessDay,
        ?IndexRateData $rateSnapshot,
    ): bool {
        return ! $currentDate->equalTo($startDate)
            && $isBusinessDay
            && $rateSnapshot === null
            && $parameter->indexer_enum === PuIndexer::Cdi
            && $parameter->index_rate_lookup_mode_enum === PuIndexRateLookupMode::BusinessDayLagExact;
    }

    /**
     * A engine externa espelhada pelos modos "Exact" arredonda o fator Spread×DI em 9 casas ANTES de
     * calcular os juros (comprovado linha-a-linha nos gabaritos AMANI 2026-03-02 e TROUPE 2025-06-05:
     * juros do gabarito = base × (round9(fator) − 1), exato). O fator persistido/exibido permanece sem
     * esse arredondamento, como nas planilhas de origem. Modos não espelhados mantêm o fator íntegro.
     */
    private function factorSpreadDiForInterest(EmissionPuParameter $parameter, string $factorSpreadDi): string
    {
        if (! in_array($parameter->index_rate_lookup_mode_enum, [
            PuIndexRateLookupMode::BusinessDayLagExact,
            PuIndexRateLookupMode::PreviousCalendarDayExact,
        ], true)) {
            return $factorSpreadDi;
        }

        return $this->rounder->normalize(
            $this->rounder->round($factorSpreadDi, 9),
            DecimalRounder::CALCULATION_SCALE,
        );
    }

    private function resolveRateSnapshot(
        EmissionPuParameter $parameter,
        CarbonImmutable $currentDate,
        bool $isBusinessDay,
    ): ?IndexRateData {
        $lookupMode = $parameter->index_rate_lookup_mode_enum;

        return match ($lookupMode) {
            PuIndexRateLookupMode::PreviousAvailableBusinessDay => $isBusinessDay
                ? $this->indexRateProvider->rateForDate($parameter->indexer_enum, $currentDate)
                : null,
            PuIndexRateLookupMode::PreviousCalendarDayExact => $this->indexRateProvider->exactRateForDate(
                $parameter->indexer_enum,
                $currentDate->subDay(),
            ),
            PuIndexRateLookupMode::BusinessDayLagExact => $this->indexRateProvider->exactRateForDate(
                $parameter->indexer_enum,
                $this->businessDayCalendar->shiftBusinessDays(
                    $currentDate,
                    (int) $parameter->index_rate_lag_business_days,
                    $parameter->calendar_code,
                ),
            ),
        };
    }

    private function shouldApplyDi(
        EmissionPuParameter $parameter,
        bool $isBusinessDay,
        ?IndexRateData $rateSnapshot,
    ): bool {
        if ($rateSnapshot === null) {
            return false;
        }

        return match ($parameter->index_rate_lookup_mode_enum) {
            PuIndexRateLookupMode::PreviousCalendarDayExact => true,
            PuIndexRateLookupMode::PreviousAvailableBusinessDay,
            PuIndexRateLookupMode::BusinessDayLagExact => $isBusinessDay,
        };
    }
}

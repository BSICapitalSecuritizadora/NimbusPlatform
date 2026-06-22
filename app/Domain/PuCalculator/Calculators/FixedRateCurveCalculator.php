<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Calculators;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\Contracts\PuIndexCalculatorInterface;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuCurveEventSupport;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Engine de curva diária para operações PREFIXADAS (taxa fixa anual, base de dias úteis).
 *
 * Fator prefixado acumulado em DUP dias úteis = (1 + taxa_anual/100) ^ (DUP / base).
 * Juros real = PU base corrigido x (fator - 1); PU atualizado = base + juros real.
 * Eventos de juros/amortização, reset pós-pagamento e quantidade vigente seguem a mesma
 * mecânica do CDI (via PuCurveEventSupport). Não consulta índices (CDI/IPCA).
 */
class FixedRateCurveCalculator implements PuIndexCalculatorInterface
{
    public function __construct(
        private readonly BusinessDayCalendar $businessDayCalendar,
        private readonly DailyFactorCalculator $dailyFactorCalculator,
        private readonly DecimalRounder $rounder,
        private readonly PuCurveEventSupport $eventSupport,
    ) {}

    public function calculate(Emission $emission): PuCurveGenerationResult
    {
        $emission->loadMissing(['puParameter', 'puEvents', 'integralizationHistories']);

        $parameter = $emission->puParameter;

        if ($parameter === null) {
            throw new InvalidArgumentException('The emission does not have PU calculation parameters configured.');
        }

        if ($parameter->annual_rate === null) {
            throw new InvalidArgumentException('A taxa anual (annual_rate) é obrigatória para operações prefixadas.');
        }

        $startDate = CarbonImmutable::instance($parameter->curve_start_date);
        $endDate = CarbonImmutable::instance($parameter->curve_end_date);
        $eventGroups = $this->eventSupport->groupEventsByDate($emission->puEvents);
        $quantityTimeline = $this->eventSupport->buildQuantityTimeline($emission->integralizationHistories);

        $annualRate = (string) $parameter->annual_rate;
        $basis = (int) $parameter->business_day_basis;
        $method = $parameter->resolvedCalculationMethod();

        $baseUnitValue = $this->rounder->normalize((string) $parameter->initial_unit_value, DecimalRounder::CALCULATION_SCALE);
        $lastResidualUnitValue = $baseUnitValue;
        $businessDaysSinceReset = 0;
        $rows = [];

        for ($currentDate = $startDate; $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            if ($currentDate->isAfter($startDate) && $this->eventSupport->shouldResetAfterPreviousRow($rows)) {
                $baseUnitValue = $lastResidualUnitValue;
                $businessDaysSinceReset = 0;
            }

            $isBusinessDay = $this->businessDayCalendar->isBusinessDay($currentDate, $parameter->calendar_code);
            $quantity = $this->eventSupport->quantityForDate($quantityTimeline, $currentDate);

            if ($currentDate->equalTo($startDate)) {
                $fixedFactor = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $interestRealUnitValue = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
                $updatedUnitValue = $baseUnitValue;
                $dupInterest = 0;
                $dutInterest = 0;
            } else {
                if ($isBusinessDay) {
                    $businessDaysSinceReset++;
                }

                $fixedFactor = $this->dailyFactorCalculator->factorSpreadForBusinessDays(
                    $annualRate,
                    $businessDaysSinceReset,
                    $basis,
                    DecimalRounder::CALCULATION_SCALE,
                );

                $interestRealUnitValue = $this->rounder->round(
                    bcmul(
                        $baseUnitValue,
                        bcsub($fixedFactor, '1', DecimalRounder::CALCULATION_SCALE + 4),
                        DecimalRounder::CALCULATION_SCALE + 4,
                    ),
                    DecimalRounder::CALCULATION_SCALE,
                );
                $updatedUnitValue = $this->rounder->round(
                    bcadd($baseUnitValue, $interestRealUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE,
                );
                $dupInterest = $businessDaysSinceReset;
                $dutInterest = $basis;
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

                    $resolvedAmortization = $this->eventSupport->resolveAmortizationUnitValue(
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

            $oneFactor = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
            $calculationMemory = [
                'engine_version' => $method->engineVersion(),
                'calculation_method' => $method->value,
                'indexer' => $parameter->indexer,
                'is_business_day' => $isBusinessDay,
                'calendar_code' => $parameter->calendar_code,
                'annual_rate' => $annualRate,
                'base_unit_value_raw' => $baseUnitValue,
                'factor_di_raw' => $oneFactor,
                'factor_di_accumulated_raw' => $oneFactor,
                'factor_spread_raw' => $fixedFactor,
                'factor_spread_di_raw' => $fixedFactor,
                'interest_real_unit_value_raw' => $interestRealUnitValue,
                'updated_unit_value_raw' => $updatedUnitValue,
                'interest_payment_unit_value_raw' => $interestPaymentUnitValue,
                'amortization_unit_value_raw' => $amortizationUnitValue,
                'payment_total_unit_value_raw' => $paymentTotalUnitValue,
                'residual_unit_value_raw' => $residualUnitValue,
                'quantity_raw' => $quantity,
                'total_value_raw' => $totalValue,
                'payment_total_value_raw' => $paymentTotalValue,
                'index_rate_date' => null,
                'index_rate_value' => null,
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
                factorDi: $this->rounder->round($oneFactor, DecimalRounder::FACTOR_SCALE),
                factorDiAccumulated: $this->rounder->round($oneFactor, DecimalRounder::FACTOR_SCALE),
                factorSpread: $this->rounder->round($fixedFactor, DecimalRounder::FACTOR_SCALE),
                factorSpreadDi: $this->rounder->round($fixedFactor, DecimalRounder::FACTOR_SCALE),
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
                indexRateDate: null,
                indexRateValue: null,
                eventOriginalDate: $eventOriginalDate,
                eventEffectiveDate: $eventEffectiveDate,
                calculationMemory: $calculationMemory,
            );

            $lastResidualUnitValue = $residualUnitValue;
        }

        return new PuCurveGenerationResult($rows);
    }
}

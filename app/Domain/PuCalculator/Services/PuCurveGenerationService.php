<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Calculators\DailyFactorCalculator;
use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
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

        $baseUnitValue = $this->rounder->round((string) $parameter->initial_unit_value, DecimalRounder::UNIT_SCALE);
        $lastResidualUnitValue = $baseUnitValue;
        $factorDiAccumulated = '1.0000000000000000';
        $factorSpread = '1.0000000000000000';
        $lastResetDate = $startDate;
        $businessDaysSinceReset = 0;
        $rows = [];

        for ($currentDate = $startDate; $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            if ($currentDate->isAfter($startDate) && $this->shouldResetAfterPreviousRow($rows)) {
                $baseUnitValue = $lastResidualUnitValue;
                $factorDiAccumulated = '1.0000000000000000';
                $factorSpread = '1.0000000000000000';
                $businessDaysSinceReset = 0;
                $lastResetDate = $currentDate->subDay();
            }

            $isBusinessDay = $this->businessDayCalendar->isBusinessDay($currentDate, $parameter->calendar_code);
            $quantity = $this->quantityForDate($quantityTimeline, $currentDate);
            $rateSnapshot = $this->indexRateProvider->rateForDate($parameter->indexer_enum, $currentDate);

            if ($currentDate->equalTo($startDate)) {
                $factorDi = '1.0000000000000000';
                $factorSpread = '0.0000000000000000';
                $factorSpreadDi = '0.0000000000000000';
                $interestRealUnitValue = '0.000000000000';
                $updatedUnitValue = $baseUnitValue;
                $dupInterest = 0;
                $dutInterest = 0;
            } else {
                if ($isBusinessDay) {
                    $businessDaysSinceReset++;
                    $factorDi = $this->dailyFactorCalculator->factorDiForDay(
                        $rateSnapshot?->value,
                        true,
                        (int) $parameter->business_day_basis,
                    );
                    $factorDiAccumulated = $this->rounder->round(
                        bcmul($factorDiAccumulated, $factorDi, DecimalRounder::INTERNAL_SCALE),
                        DecimalRounder::FACTOR_SCALE,
                    );
                    $factorSpread = $this->dailyFactorCalculator->factorSpreadForBusinessDays(
                        (string) $parameter->spread_rate,
                        $businessDaysSinceReset,
                        (int) $parameter->business_day_basis,
                    );
                } else {
                    $factorDi = '1.0000000000000000';
                }

                if ($businessDaysSinceReset === 0) {
                    $factorSpread = '1.0000000000000000';
                }

                $factorSpreadDi = $this->rounder->round(
                    bcmul($factorDiAccumulated, $factorSpread, DecimalRounder::INTERNAL_SCALE),
                    DecimalRounder::FACTOR_SCALE,
                );
                $interestRealUnitValue = $this->rounder->round(
                    bcmul(
                        $baseUnitValue,
                        bcsub($factorSpreadDi, '1', DecimalRounder::INTERNAL_SCALE),
                        DecimalRounder::INTERNAL_SCALE,
                    ),
                    DecimalRounder::UNIT_SCALE,
                );
                $updatedUnitValue = $this->rounder->round(
                    bcadd($baseUnitValue, $interestRealUnitValue, DecimalRounder::INTERNAL_SCALE),
                    DecimalRounder::UNIT_SCALE,
                );
                $dupInterest = $lastResetDate->diffInDays($currentDate);
                $dutInterest = $businessDaysSinceReset;
            }

            $interestPaymentUnitValue = '0.000000000000';
            $amortizationUnitValue = '0.000000000000';
            $amortizationRatio = '0.000000000000';
            $eventOriginalDate = null;
            $eventEffectiveDate = null;
            $groupedEvents = $eventGroups[$currentDate->toDateString()] ?? collect();

            if ($groupedEvents->isNotEmpty()) {
                $interestPaymentUnitValue = $groupedEvents
                    ->contains(fn (EmissionPuEvent $event): bool => $event->event_type_enum === PuEventType::InterestPayment)
                    ? $interestRealUnitValue
                    : '0.000000000000';

                $remainingAfterInterest = $this->rounder->round(
                    bcsub($updatedUnitValue, $interestPaymentUnitValue, DecimalRounder::INTERNAL_SCALE),
                    DecimalRounder::UNIT_SCALE,
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
                            bcsub($remainingAfterInterest, $amortizationUnitValue, DecimalRounder::INTERNAL_SCALE),
                            DecimalRounder::UNIT_SCALE,
                        ),
                    );

                    $amortizationUnitValue = $this->rounder->round(
                        bcadd($amortizationUnitValue, $resolvedAmortization, DecimalRounder::INTERNAL_SCALE),
                        DecimalRounder::UNIT_SCALE,
                    );

                    if ($event->amortization_type_enum === PuAmortizationType::Percentage && $event->amortization_value !== null) {
                        $amortizationRatio = $this->rounder->round((string) $event->amortization_value, DecimalRounder::UNIT_SCALE);
                    }
                }

                if (bccomp($baseUnitValue, '0', DecimalRounder::UNIT_SCALE) === 1 && bccomp($amortizationUnitValue, '0', DecimalRounder::UNIT_SCALE) === 1 && bccomp($amortizationRatio, '0', DecimalRounder::UNIT_SCALE) === 0) {
                    $amortizationRatio = $this->rounder->round(
                        bcdiv($amortizationUnitValue, $baseUnitValue, DecimalRounder::INTERNAL_SCALE),
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
                bcadd($interestPaymentUnitValue, $amortizationUnitValue, DecimalRounder::INTERNAL_SCALE),
                DecimalRounder::UNIT_SCALE,
            );
            $residualUnitValue = $this->rounder->round(
                bcsub($updatedUnitValue, $paymentTotalUnitValue, DecimalRounder::INTERNAL_SCALE),
                DecimalRounder::UNIT_SCALE,
            );

            if (bccomp($residualUnitValue, '0', DecimalRounder::UNIT_SCALE) < 0) {
                $residualUnitValue = '0.000000000000';
            }
            $totalValue = $this->rounder->round(
                bcmul($residualUnitValue, $quantity, DecimalRounder::INTERNAL_SCALE),
                DecimalRounder::TOTAL_SCALE,
            );
            $interestPaymentValue = $this->rounder->round(
                bcmul($interestPaymentUnitValue, $quantity, DecimalRounder::INTERNAL_SCALE),
                DecimalRounder::TOTAL_SCALE,
            );
            $paymentTotalValue = $this->rounder->round(
                bcmul($paymentTotalUnitValue, $quantity, DecimalRounder::INTERNAL_SCALE),
                DecimalRounder::TOTAL_SCALE,
            );

            $rows[] = new PuDailyCurveRowData(
                date: $currentDate,
                isBusinessDay: $isBusinessDay,
                unitBaseValue: $baseUnitValue,
                unitCorrectedValue: $baseUnitValue,
                factorDi: $factorDi,
                factorDiAccumulated: $factorDiAccumulated,
                factorSpread: $factorSpread,
                factorSpreadDi: $factorSpreadDi,
                interestRealUnitValue: $interestRealUnitValue,
                updatedUnitValue: $updatedUnitValue,
                amortizationRatio: $amortizationRatio,
                amortizationUnitValue: $amortizationUnitValue,
                residualUnitValue: $residualUnitValue,
                quantity: $quantity,
                totalValue: $totalValue,
                interestPaymentUnitValue: $interestPaymentUnitValue,
                interestPaymentValue: $interestPaymentValue,
                paymentTotalUnitValue: $paymentTotalUnitValue,
                paymentTotalValue: $paymentTotalValue,
                dupCorrection: 0,
                dutCorrection: 0,
                dupInterest: $dupInterest,
                dutInterest: $dutInterest,
                indexRateDate: $rateSnapshot?->date,
                indexRateValue: $rateSnapshot?->value,
                eventOriginalDate: $eventOriginalDate,
                eventEffectiveDate: $eventEffectiveDate,
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
            PuAmortizationType::None => '0.000000000000',
            PuAmortizationType::Residual => $remainingResidualUnitValue,
            PuAmortizationType::Percentage => $this->rounder->round(
                bcmul(
                    $baseUnitValue,
                    (string) ($event->amortization_value ?? '0'),
                    DecimalRounder::INTERNAL_SCALE,
                ),
                DecimalRounder::UNIT_SCALE,
            ),
            PuAmortizationType::UnitValue => $this->rounder->round(
                (string) ($event->amortization_value ?? '0'),
                DecimalRounder::UNIT_SCALE,
            ),
        };

        if (bccomp($resolvedValue, $remainingResidualUnitValue, DecimalRounder::UNIT_SCALE) === 1) {
            return $remainingResidualUnitValue;
        }

        return $resolvedValue;
    }
}

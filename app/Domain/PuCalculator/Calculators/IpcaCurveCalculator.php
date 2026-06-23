<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Calculators;

use App\Domain\PuCalculator\Contracts\IndexRateProvider;
use App\Domain\PuCalculator\Contracts\PuIndexCalculatorInterface;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Exceptions\IndexerNotSupportedException;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\PuCurveEventSupport;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Engine de curva diária para operações IPCA + cupom real.
 *
 * Mecânica homologada a partir do gabarito real (CRI RIO BRANCO 15ª série) na janela
 * pré-amortização (correção monetária + cupom mensal):
 *
 *  - Aniversário mensal no dia `base_index_date->day`; períodos vão do aniversário ao próximo.
 *  - Correção pró-rata em DIAS CORRIDOS: corrigido = base_corrigido × max(NI_ref/NI_prev, 1)^(dup/dut),
 *    onde NI_ref é o número-índice do mês de referência (1º do mês do aniversário de abertura, defasado
 *    de `index_lag_months`) e NI_prev é o do mês anterior. O piso `max(..., 1)` aplica a regra de
 *    deflação observada no gabarito (meses de IPCA negativo não corrigem para baixo).
 *  - Cupom real (`annual_rate`) em base 30/360 mensal: fator = (1 + taxa/100)^(dup/(dut×12));
 *    juros = corrigido × (fator − 1); PU atualizado = corrigido + juros. Juros são pagos no aniversário
 *    (evento de juros) e o fator reinicia no período seguinte.
 *
 * IMPORTANTE: a interação das amortizações intermediárias com aniversário/deflação ainda NÃO está
 * homologada. Por isso a geração operacional permanece bloqueada (`PuIndexer::Ipca::isHomologated()`
 * = false e bloqueio no `PuCurvePrerequisiteService`). Esta engine é validada por teste apenas na
 * janela sem amortização intermediária. Datas sem número-índice cadastrado lançam exceção (a política
 * de projeção de mercado ainda não foi implementada).
 *
 * O bloco de eventos/pagamentos/total replica conscientemente o do Prefixado (FixedRateCurveCalculator)
 * para não tocar no CDI calibrado nesta fase.
 */
class IpcaCurveCalculator implements PuIndexCalculatorInterface
{
    private const RATIO_SCALE = DecimalRounder::CALCULATION_SCALE;

    public function __construct(
        private readonly DailyFactorCalculator $dailyFactorCalculator,
        private readonly DecimalRounder $rounder,
        private readonly PuCurveEventSupport $eventSupport,
        private readonly IndexRateProvider $indexRateProvider,
    ) {}

    public function calculate(Emission $emission): PuCurveGenerationResult
    {
        $emission->loadMissing(['puParameter', 'puEvents', 'integralizationHistories']);

        $parameter = $emission->puParameter;

        if ($parameter === null) {
            throw new InvalidArgumentException('The emission does not have PU calculation parameters configured.');
        }

        if ($parameter->annual_rate === null) {
            throw new InvalidArgumentException('A taxa real anual (annual_rate) é obrigatória para operações IPCA.');
        }

        if ($parameter->base_index_date === null) {
            throw new InvalidArgumentException('A data-base do índice (base_index_date) é obrigatória para operações IPCA.');
        }

        $startDate = CarbonImmutable::instance($parameter->curve_start_date);
        $endDate = CarbonImmutable::instance($parameter->curve_end_date);
        $anniversaryDay = (int) CarbonImmutable::instance($parameter->base_index_date)->day;
        $lagMonths = (int) ($parameter->index_lag_months ?? 0);
        $couponBase = $this->rounder->round(
            bcadd('1', bcdiv((string) $parameter->annual_rate, '100', self::RATIO_SCALE + 4), self::RATIO_SCALE + 4),
            self::RATIO_SCALE,
        );

        $eventGroups = $this->eventSupport->groupEventsByDate($emission->puEvents);
        $quantityTimeline = $this->eventSupport->buildQuantityTimeline($emission->integralizationHistories);
        $method = $parameter->resolvedCalculationMethod();

        $baseUnitValue = $this->rounder->normalize((string) $parameter->initial_unit_value, DecimalRounder::CALCULATION_SCALE);
        $correctionBase = $baseUnitValue;
        $lastResidualUnitValue = $baseUnitValue;
        $lastPeriodKey = null;
        $rows = [];

        for ($currentDate = $startDate; $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            [$closingAnniversary, $openingAnniversary, $dut, $dup] = $this->periodBounds($currentDate, $anniversaryDay, $startDate);
            $periodKey = $openingAnniversary->toDateString();

            if ($lastPeriodKey !== null && $periodKey !== $lastPeriodKey) {
                $correctionBase = $lastResidualUnitValue;
            }

            $referenceMonth = $openingAnniversary->startOfMonth()->subMonthsNoOverflow($lagMonths);
            $correctionRatio = $this->correctionRatio($referenceMonth, $currentDate);

            $isBusinessDay = ! $currentDate->isWeekend();
            $quantity = $this->eventSupport->quantityForDate($quantityTimeline, $currentDate);

            if ($dup === 0) {
                $correctionFactor = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $couponFactor = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $correctedUnitValue = $correctionBase;
                $interestRealUnitValue = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
                $updatedUnitValue = $correctedUnitValue;
            } else {
                $correctionFactor = $this->dailyFactorCalculator->powRatio($correctionRatio, $dup, $dut, DecimalRounder::CALCULATION_SCALE);
                $correctedUnitValue = $this->rounder->round(
                    bcmul($correctionBase, $correctionFactor, DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE,
                );

                $couponFactor = $this->dailyFactorCalculator->powRatio($couponBase, $dup, $dut * 12, DecimalRounder::CALCULATION_SCALE);
                $interestRealUnitValue = $this->rounder->round(
                    bcmul(
                        $correctedUnitValue,
                        bcsub($couponFactor, '1', DecimalRounder::CALCULATION_SCALE + 4),
                        DecimalRounder::CALCULATION_SCALE + 4,
                    ),
                    DecimalRounder::CALCULATION_SCALE,
                );
                $updatedUnitValue = $this->rounder->round(
                    bcadd($correctedUnitValue, $interestRealUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE,
                );
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
                        baseUnitValue: $correctionBase,
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
                'coupon_annual_rate' => (string) $parameter->annual_rate,
                'anniversary_day' => $anniversaryDay,
                'index_lag_months' => $lagMonths,
                'correction_base_raw' => $correctionBase,
                'correction_ratio_raw' => $correctionRatio,
                'correction_factor_raw' => $correctionFactor,
                'corrected_unit_value_raw' => $correctedUnitValue,
                'coupon_factor_raw' => $couponFactor,
                'interest_real_unit_value_raw' => $interestRealUnitValue,
                'updated_unit_value_raw' => $updatedUnitValue,
                'amortization_unit_value_raw' => $amortizationUnitValue,
                'payment_total_unit_value_raw' => $paymentTotalUnitValue,
                'residual_unit_value_raw' => $residualUnitValue,
                'quantity_raw' => $quantity,
                'total_value_raw' => $totalValue,
                'index_rate_date' => $referenceMonth->toDateString(),
                'index_rate_value' => $this->indexRateProvider->exactRateForDate(PuIndexer::Ipca, $referenceMonth)?->value,
                'dup_correction' => $dup,
                'dut_correction' => $dut,
                'event_types' => $groupedEvents
                    ->map(fn (EmissionPuEvent $event): string => $event->event_type)
                    ->values()
                    ->all(),
            ];

            $rows[] = new PuDailyCurveRowData(
                date: $currentDate,
                isBusinessDay: $isBusinessDay,
                unitBaseValue: $this->rounder->round($baseUnitValue, DecimalRounder::UNIT_SCALE),
                unitCorrectedValue: $this->rounder->round($correctedUnitValue, DecimalRounder::UNIT_SCALE),
                factorDi: $this->rounder->round($oneFactor, DecimalRounder::FACTOR_SCALE),
                factorDiAccumulated: $this->rounder->round($oneFactor, DecimalRounder::FACTOR_SCALE),
                factorSpread: $this->rounder->round($couponFactor, DecimalRounder::FACTOR_SCALE),
                factorSpreadDi: $this->rounder->round($couponFactor, DecimalRounder::FACTOR_SCALE),
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
                dupCorrection: $dup,
                dutCorrection: $dut,
                dupInterest: $dup,
                dutInterest: $dut,
                indexRateDate: $referenceMonth,
                indexRateValue: $this->indexRateProvider->exactRateForDate(PuIndexer::Ipca, $referenceMonth)?->value,
                eventOriginalDate: $eventOriginalDate,
                eventEffectiveDate: $eventEffectiveDate,
                calculationMemory: $calculationMemory,
            );

            $lastResidualUnitValue = $residualUnitValue;
            $lastPeriodKey = $periodKey;
        }

        return new PuCurveGenerationResult($rows);
    }

    /**
     * Resolve a razão de correção do período, com piso de deflação (fator >= 1).
     */
    private function correctionRatio(CarbonImmutable $referenceMonth, CarbonImmutable $currentDate): string
    {
        $referenceRate = $this->indexRateProvider->exactRateForDate(PuIndexer::Ipca, $referenceMonth);
        $previousRate = $this->indexRateProvider->exactRateForDate(PuIndexer::Ipca, $referenceMonth->subMonthNoOverflow());

        if ($referenceRate === null || $previousRate === null) {
            throw new IndexerNotSupportedException(sprintf(
                'Não há número-índice IPCA cadastrado para %s (curva em %s). A política de projeção de mercado ainda não foi implementada.',
                $referenceMonth->toDateString(),
                $currentDate->toDateString(),
            ));
        }

        $ratio = bcdiv($referenceRate->value, $previousRate->value, self::RATIO_SCALE);

        if (bccomp($ratio, '1', self::RATIO_SCALE) < 0) {
            return $this->rounder->normalize('1', self::RATIO_SCALE);
        }

        return $ratio;
    }

    /**
     * Bordas do período de correção (aniversário no dia configurado, em dias corridos).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int, 3: int}
     */
    private function periodBounds(CarbonImmutable $date, int $anniversaryDay, CarbonImmutable $emissionDate): array
    {
        $closing = $date->day <= $anniversaryDay
            ? $date->day($anniversaryDay)
            : $date->addMonthNoOverflow()->day($anniversaryDay);
        $opening = $closing->subMonthNoOverflow()->day($anniversaryDay);

        $dut = (int) $opening->diffInDays($closing);
        $anchor = $emissionDate->greaterThan($opening) ? $emissionDate : $opening;
        $dup = (int) $anchor->diffInDays($date);

        return [$closing, $opening, $dut, $dup];
    }
}

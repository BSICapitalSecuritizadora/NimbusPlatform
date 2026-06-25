<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Calculators;

use App\Domain\PuCalculator\Contracts\PuIndexCalculatorInterface;
use App\Domain\PuCalculator\DTOs\IpcaIndexResolution;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Services\DecimalRounder;
use App\Domain\PuCalculator\Services\IpcaIndexResolver;
use App\Domain\PuCalculator\Services\PuCurveEventSupport;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Engine de curva diária para operações IPCA + cupom real.
 *
 * Mecânica decodificada do gabarito real (CRI RIO BRANCO 15ª série), incluindo as amortizações
 * intermediárias. O ponto central é que a operação tem DOIS RELÓGIOS independentes:
 *
 *  1. RELÓGIO DE CORREÇÃO (correção monetária pelo IPCA)
 *     - Aniversário mensal no dia `base_index_date->day`; o período de correção vai do aniversário ao
 *       próximo (dut = dias corridos do período) e usa o número-índice do mês de referência (1º do mês
 *       do aniversário de abertura, defasado de `index_lag_months`).
 *     - corrigido = base_corrigido × max(NI_ref/NI_prev, 1)^(dupCorr/dut). O piso `max(..., 1)` aplica a
 *       regra de deflação do gabarito (meses de IPCA negativo não corrigem para baixo).
 *     - A BASE de correção e o `dupCorr` REINICIAM em DOIS gatilhos: (a) no aniversário mensal e (b) em
 *       cada AMORTIZAÇÃO (a base passa a ser o residual pós-evento). A âncora de correção é, portanto,
 *       max(emissão, abertura do aniversário, última amortização). Entre eventos, a referência NI e o
 *       dut permanecem os do período de aniversário.
 *
 *  2. RELÓGIO DE CUPOM (juros reais `annual_rate`, base 30/360)
 *     - O fator de cupom acumula DIARIAMENTE: a cada dia multiplica-se por (1 + taxa/100)^(1/(dut×12)),
 *       usando o `dut` do dia. Logo o fator atravessa o aniversário sem reiniciar (o `dut` muda, o fator
 *       não zera). juros = corrigido × (fator − 1); PU atualizado = corrigido + juros.
 *     - O fator de cupom REINICIA (volta a 1) apenas em EVENTOS de pagamento de juros OU de amortização
 *       — nunca num aniversário sem evento. Se um aniversário não tem evento, os juros acumulados
 *       CAPITALIZAM na base de correção do período seguinte (residual = atualizado, sem subtrair juros).
 *
 * Residual = atualizado − pagamentos do dia, onde pagamentos = (juros, se houver evento de juros) +
 * amortização. Assim, quando há pagamento de juros, residual = corrigido − amortização; quando a
 * amortização ocorre sem pagar juros, residual = atualizado − amortização (os juros capitalizam).
 *
 * POLÍTICA DE PROJEÇÃO: para meses sem IPCA publicado a engine delega ao `IpcaIndexResolver`, que aplica
 * o `index_projection_policy` do parâmetro. Com a política `market`, número-índice PROJETADO (cadastrado
 * em `index_rates` com `is_projected = true`) é aceito e fica MARCADO como projetado na memória de cálculo
 * (`index_rate_type`, `index_is_projected`, `index_source`, `index_projection_source/_reference_date`).
 * Com `published_only` (default), qualquer mês sem publicação — ou um NI projetado — BLOQUEIA com exceção
 * clara: a curva nunca projeta silenciosamente nem mascara projeção de publicado.
 *
 * IMPORTANTE: `PuIndexer::Ipca::isHomologated()` permanece false. A virada para true continua gated no
 * fluxo maker/checker (homologação da versão da curva) + validação até o vencimento com série projetada
 * APROVADA. A validação automatizada cobre toda a janela com IPCA publicado (incluindo as 9 amortizações)
 * e o trecho projetado até o vencimento (cenário de mercado determinístico).
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
        private readonly IpcaIndexResolver $indexResolver,
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
        $projectionPolicy = $parameter->index_projection_policy;
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
        $correctionAnchorKey = null;
        $lastAmortizationDate = null;
        $couponFactor = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
        $couponAccrualDays = 0;
        $rows = [];

        for ($currentDate = $startDate; $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            [$closingAnniversary, $openingAnniversary, $dut] = $this->periodBounds($currentDate, $anniversaryDay);

            $correctionAnchor = $this->correctionAnchor($startDate, $openingAnniversary, $lastAmortizationDate);
            $dupCorrection = (int) $correctionAnchor->diffInDays($currentDate);
            $anchorKey = $correctionAnchor->toDateString();

            if ($correctionAnchorKey !== null && $anchorKey !== $correctionAnchorKey) {
                $correctionBase = $lastResidualUnitValue;
            }

            $referenceMonth = $openingAnniversary->startOfMonth()->subMonthsNoOverflow($lagMonths);
            $indexResolution = $this->indexResolver->resolve($referenceMonth, $projectionPolicy, $currentDate);

            $isBusinessDay = ! $currentDate->isWeekend();
            $quantity = $this->eventSupport->quantityForDate($quantityTimeline, $currentDate);

            if ($currentDate->equalTo($startDate)) {
                $couponFactor = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $couponAccrualDays = 0;
            } else {
                $dailyCouponIncrement = $this->dailyFactorCalculator->powRatio($couponBase, 1, $dut * 12, DecimalRounder::CALCULATION_SCALE);
                $couponFactor = $this->rounder->round(
                    bcmul($couponFactor, $dailyCouponIncrement, DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE,
                );
                $couponAccrualDays++;
            }

            if ($dupCorrection === 0) {
                $correctionRatio = $this->rounder->normalize('1', self::RATIO_SCALE);
                $correctionFactor = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $correctedUnitValue = $correctionBase;
            } else {
                $correctionRatio = $this->correctionRatio($indexResolution, $referenceMonth, $projectionPolicy, $currentDate);
                $correctionFactor = $this->dailyFactorCalculator->powRatio($correctionRatio, $dupCorrection, $dut, DecimalRounder::CALCULATION_SCALE);
                $correctedUnitValue = $this->rounder->round(
                    bcmul($correctionBase, $correctionFactor, DecimalRounder::CALCULATION_SCALE + 4),
                    DecimalRounder::CALCULATION_SCALE,
                );
            }

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

            $interestPaymentUnitValue = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
            $amortizationUnitValue = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
            $amortizationRatio = $this->rounder->normalize('0', DecimalRounder::UNIT_SCALE);
            $hadInterestPaymentEvent = false;
            $hadAmortizationEvent = false;
            $eventOriginalDate = null;
            $eventEffectiveDate = null;
            $groupedEvents = $eventGroups[$currentDate->toDateString()] ?? collect();

            if ($groupedEvents->isNotEmpty()) {
                $hadInterestPaymentEvent = $groupedEvents
                    ->contains(fn (EmissionPuEvent $event): bool => $event->event_type_enum === PuEventType::InterestPayment);
                $interestPaymentUnitValue = $hadInterestPaymentEvent
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

                    $hadAmortizationEvent = true;
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
                'index_rate_value' => $indexResolution->value,
                'index_rate_type' => $indexResolution->type(),
                'index_is_projected' => $indexResolution->isProjected,
                'index_source' => $indexResolution->source,
                'index_projection_source' => $indexResolution->projectionSource,
                'index_projection_reference_date' => $indexResolution->projectionReferenceDate?->toDateString(),
                'index_projection_policy' => $indexResolution->policy->value,
                'dup_correction' => $dupCorrection,
                'dut_correction' => $dut,
                'dup_interest' => $couponAccrualDays,
                'dut_interest' => $dut,
                'correction_anchor' => $anchorKey,
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
                dupCorrection: $dupCorrection,
                dutCorrection: $dut,
                dupInterest: $couponAccrualDays,
                dutInterest: $dut,
                indexRateDate: $referenceMonth,
                indexRateValue: $indexResolution->value,
                eventOriginalDate: $eventOriginalDate,
                eventEffectiveDate: $eventEffectiveDate,
                calculationMemory: $calculationMemory,
            );

            $lastResidualUnitValue = $residualUnitValue;
            $correctionAnchorKey = $anchorKey;

            if ($hadAmortizationEvent) {
                $lastAmortizationDate = $currentDate;
            }

            if ($hadInterestPaymentEvent || $hadAmortizationEvent) {
                $couponFactor = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $couponAccrualDays = 0;
            }
        }

        return new PuCurveGenerationResult($rows);
    }

    /**
     * Resolve a razão de correção do período, com piso de deflação (fator >= 1).
     *
     * Tanto o número-índice de referência (numerador) quanto o do mês anterior (denominador) passam pela
     * política de projeção: meses sem IPCA publicado só são aceitos como PROJETADOS quando a política
     * permite; caso contrário o resolver lança exceção clara (a curva nunca projeta silenciosamente).
     */
    private function correctionRatio(
        IpcaIndexResolution $reference,
        CarbonImmutable $referenceMonth,
        ?string $projectionPolicy,
        CarbonImmutable $currentDate,
    ): string {
        $previous = $this->indexResolver->resolve(
            $referenceMonth->subMonthNoOverflow(),
            $projectionPolicy,
            $currentDate,
        );

        $ratio = bcdiv($reference->value, $previous->value, self::RATIO_SCALE);

        if (bccomp($ratio, '1', self::RATIO_SCALE) < 0) {
            return $this->rounder->normalize('1', self::RATIO_SCALE);
        }

        return $ratio;
    }

    /**
     * Bordas do período de correção mensal (aniversário no dia configurado, em dias corridos).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}
     */
    private function periodBounds(CarbonImmutable $date, int $anniversaryDay): array
    {
        $closing = $date->day <= $anniversaryDay
            ? $date->day($anniversaryDay)
            : $date->addMonthNoOverflow()->day($anniversaryDay);
        $opening = $closing->subMonthNoOverflow()->day($anniversaryDay);

        $dut = (int) $opening->diffInDays($closing);

        return [$closing, $opening, $dut];
    }

    /**
     * Âncora do relógio de correção: a base e o `dupCorr` reiniciam no maior entre a emissão, a
     * abertura do aniversário mensal e a última amortização. Assim a correção zera tanto no
     * aniversário (nova NI) quanto a cada amortização (nova base = residual pós-evento), mantendo
     * a referência NI/dut do período de aniversário entre os eventos.
     */
    private function correctionAnchor(
        CarbonImmutable $emissionDate,
        CarbonImmutable $openingAnniversary,
        ?CarbonImmutable $lastAmortizationDate,
    ): CarbonImmutable {
        $anchor = $emissionDate->greaterThan($openingAnniversary) ? $emissionDate : $openingAnniversary;

        if ($lastAmortizationDate !== null && $lastAmortizationDate->greaterThan($anchor)) {
            return $lastAmortizationDate;
        }

        return $anchor;
    }
}

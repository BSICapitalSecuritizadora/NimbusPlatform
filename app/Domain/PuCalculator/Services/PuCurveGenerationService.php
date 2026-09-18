<?php

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Domain\PuCalculator\DTOs\IndexRateData;
use App\Domain\PuCalculator\DTOs\PuCurveGenerationResult;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuCalculationProfile;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Domain\PuCalculator\Enums\PuIndexRateLookupMode;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use App\Models\IntegralizationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PuCurveGenerationService
{
    public function __construct(
        private readonly BusinessDayCalendar $businessDayCalendar,
        private readonly PuIndexRateRequirementResolver $indexRateRequirementResolver,
        private readonly CdiFactorCompositionService $factorComposition,
        private readonly FirstCouponPreIntegralizationPremiumCalculator $openingPremiumCalculator,
        private readonly DecimalRounder $rounder,
        private readonly PuPrecisionPolicy $precision,
    ) {}

    /**
     * `$indexRateCalendarCode` é o calendário de OBSERVAÇÃO do índice, separado
     * do calendário contratual da curva. Nulo -- todo o caminho de produção --
     * mantém os dois idênticos e o resultado inalterado.
     *
     * `$accrualCalendarCode` é o calendário de ACCRUAL: decide quais dias da curva contam Dia Útil, e
     * portanto a contagem de DU, o DUP/DUT e a incidência do fator diário. Nulo devolve o contratual.
     * Os eventos NÃO passam por aqui -- eles chegam já datados em `puEvents`, resolvidos pela convenção
     * de pagamento sobre o calendário contratual --, de modo que a hipótese de accrual não desloca
     * nenhum pagamento.
     *
     * `$profile` é o PERFIL DE CÁLCULO. O default -- e todo o caminho operacional, que nunca informa
     * outro -- é `Contractual`, a regra literal do Termo. `LegacyCompatibility` só é alcançado por
     * escolha explícita na simulação e altera exclusivamente os estágios comprovadamente
     * divergentes do sistema anterior: o Fator DI e o Fator Spread entram na combinação sem os
     * arredondamentos contratuais de 8 e 9 casas, e o prêmio de primeiro cupom não é aplicado.
     * Calendário, CDI, data do CDI, lag, base, eventos, Following, datas de pagamento, principal,
     * quantidade, o Fator de Juros em 9 casas e a quantização monetária em 8 casas sem
     * arredondamento permanecem idênticos nos dois perfis.
     */
    public function handle(
        Emission $emission,
        ?string $indexRateCalendarCode = null,
        ?string $accrualCalendarCode = null,
        PuCalculationProfile $profile = PuCalculationProfile::Contractual,
    ): PuCurveGenerationResult {
        $emission->loadMissing(['puParameter', 'puEvents', 'integralizationHistories']);

        if ($emission->puParameter === null) {
            throw new InvalidArgumentException('The emission does not have PU calculation parameters configured.');
        }

        $parameter = $emission->puParameter;
        $accrualCalendar = $this->accrualCalendarCode($parameter, $accrualCalendarCode);
        $startDate = CarbonImmutable::instance($parameter->curve_start_date);
        $endDate = CarbonImmutable::instance($parameter->curve_end_date);
        $eventGroups = $this->groupEventsByDate($emission->puEvents);
        $quantityTimeline = $this->buildQuantityTimeline($emission->integralizationHistories);
        // O prêmio dos Dias Úteis anteriores à integralização é exigência do Termo. O sistema legado
        // de referência não o contempla, então ele é parte da metodologia histórica -- e some apenas
        // quando o perfil legado é escolhido explicitamente.
        $openingPremium = $profile->appliesFirstCouponPreIntegralizationPremium()
            ? $this->openingPremiumCalculator->calculate($parameter, $indexRateCalendarCode)
            : null;

        // VNb do Termo: 8 casas, sem arredondamento. O VNU configurado entra quantizado, e a partir
        // daqui todo VNb é ou este valor ou um SDa -- que também já nasce quantizado.
        $baseUnitValue = $this->precision->unitValue((string) $parameter->initial_unit_value);
        // VNb ANTES da quantização contratual. Só difere do aplicado quando o VNU configurado
        // carrega mais de 8 casas; a partir do primeiro reset o VNb é um SDa, que já nasce em 8.
        $baseUnitValueUnquantized = $this->rounder->normalize(
            (string) $parameter->initial_unit_value,
            DecimalRounder::CALCULATION_SCALE,
        );
        $lastResidualUnitValue = $baseUnitValue;
        $factorDiAccumulated = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
        $factorSpread = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
        $businessDaysSinceReset = 0;
        $openingPremiumApplied = false;
        // Âncora do período de juros corrente: a Data de Integralização enquanto não houver
        // cupom pago, e a última Data de Pagamento a partir dela. É estado derivado do reset --
        // não participa de nenhuma fórmula, apenas torna o período auditável na memória.
        $couponPeriodStartDate = $startDate;
        $lastPaymentDate = null;
        // Descritivo das casas decimais efetivamente aplicadas em cada estágio. Não participa
        // de nenhuma conta: existe para que a auditoria de precisão contra uma referência
        // externa possa ser feita estágio a estágio sem ler o código-fonte.
        $precisionRules = $this->precisionRules($parameter, $profile);
        $rows = [];

        for ($currentDate = $startDate; $currentDate->lte($endDate); $currentDate = $currentDate->addDay()) {
            if ($currentDate->isAfter($startDate) && $this->shouldResetAfterPreviousRow($rows)) {
                $baseUnitValue = $lastResidualUnitValue;
                $baseUnitValueUnquantized = $lastResidualUnitValue;
                $factorDiAccumulated = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $factorSpread = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $businessDaysSinceReset = 0;
                $lastPaymentDate = $rows[array_key_last($rows)]->date;
                $couponPeriodStartDate = $lastPaymentDate;
            }

            $isBusinessDay = $this->businessDayCalendar->isBusinessDay($currentDate, $accrualCalendar);
            $quantity = $this->quantityForDate($quantityTimeline, $currentDate);
            $rateRequirement = $this->indexRateRequirementResolver->resolve(
                $parameter,
                $currentDate,
                $indexRateCalendarCode,
                $accrualCalendarCode,
            );
            $rateSnapshot = $rateRequirement->rate;

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
                // Estágios intermediários existem apenas para a memória; na linha da
                // integralização não há produtório, spread nem juros a auditar.
                $factorDiApplied = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                $factorSpreadUnrounded = null;
                $interestFactor = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
                $interestUnquantizedUnitValue = $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);
            } else {
                if ($isBusinessDay) {
                    $businessDaysSinceReset++;
                    $factorSpread = $this->factorComposition->spreadFactor(
                        $parameter,
                        $businessDaysSinceReset,
                        $profile,
                    );
                }

                $factorDi = $this->factorComposition->dailyIndexFactor($parameter, $rateRequirement);
                $factorDiAccumulated = $this->factorComposition->accumulateIndexFactor(
                    $parameter,
                    $factorDiAccumulated,
                    $factorDi,
                );

                if ($businessDaysSinceReset === 0) {
                    $factorSpread = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
                }

                // Fator DI efetivamente ENTREGUE à combinação: 8 casas no contratual, produtório
                // integral no legado. É o único estágio de fator que o perfil altera.
                $factorDiApplied = $this->factorComposition->indexFactorForCombination(
                    $parameter,
                    $factorDiAccumulated,
                    $profile,
                );
                $factorSpreadUnrounded = $this->factorComposition->unroundedSpreadFactor(
                    $parameter,
                    $businessDaysSinceReset,
                );
                $factorSpreadDi = $this->factorComposition->combinedFactor(
                    $parameter,
                    $factorDiAccumulated,
                    $factorSpread,
                    $profile,
                );
                $interestFactor = $this->factorComposition->factorForInterest($parameter, $factorSpreadDi);
                // J = VNb x (Fator de Juros - 1), e o Termo fixa J em 8 casas SEM arredondamento.
                // O valor bruto continua disponível na memória, mas quem paga, compõe o PU e forma
                // o residual é o J QUANTIZADO -- nunca uma versão escondida em escala de cálculo.
                $interestUnquantizedUnitValue = $this->rounder->round(
                    bcmul(
                        $baseUnitValue,
                        bcsub($interestFactor, '1', DecimalRounder::CALCULATION_SCALE + 4),
                        DecimalRounder::CALCULATION_SCALE + 4,
                    ),
                    DecimalRounder::CALCULATION_SCALE,
                );
                $interestRealUnitValue = $this->precision->unitValue($interestUnquantizedUnitValue);
                $updatedUnitValue = $this->precision->unitValue(
                    bcadd($baseUnitValue, $interestRealUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
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
            $openingPremiumAppliedOnCurrentRow = false;
            $factorSpreadDiBeforeOpeningPremium = null;

            if ($groupedEvents->isNotEmpty()) {
                $hasInterestPayment = $groupedEvents
                    ->contains(fn (EmissionPuEvent $event): bool => $event->event_type_enum === PuEventType::InterestPayment);

                if ($hasInterestPayment && $openingPremium !== null && ! $openingPremiumApplied) {
                    $factorSpreadDiBeforeOpeningPremium = $factorSpreadDi;
                    $factorSpreadDi = $this->factorComposition->factorForInterest(
                        $parameter,
                        $this->rounder->round(
                            bcmul(
                                $interestFactor,
                                $openingPremium->factor,
                                DecimalRounder::CALCULATION_SCALE + 4,
                            ),
                            DecimalRounder::CALCULATION_SCALE,
                        ),
                    );
                    $interestFactor = $factorSpreadDi;
                    $interestUnquantizedUnitValue = $this->rounder->round(
                        bcmul(
                            $baseUnitValue,
                            bcsub($interestFactor, '1', DecimalRounder::CALCULATION_SCALE + 4),
                            DecimalRounder::CALCULATION_SCALE + 4,
                        ),
                        DecimalRounder::CALCULATION_SCALE,
                    );
                    $interestRealUnitValue = $this->precision->unitValue($interestUnquantizedUnitValue);
                    $updatedUnitValue = $this->precision->unitValue(
                        bcadd($baseUnitValue, $interestRealUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                    );
                    $openingPremiumApplied = true;
                    $openingPremiumAppliedOnCurrentRow = true;
                }

                $interestPaymentUnitValue = $hasInterestPayment
                    ? $interestRealUnitValue
                    : $this->rounder->normalize('0', DecimalRounder::CALCULATION_SCALE);

                $remainingAfterInterest = $this->precision->unitValue(
                    bcsub($updatedUnitValue, $interestPaymentUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                );

                /** @var EmissionPuEvent $event */
                foreach ($groupedEvents as $event) {
                    if ($event->event_type_enum !== PuEventType::Amortization) {
                        continue;
                    }

                    $resolvedAmortization = $this->resolveAmortizationUnitValue(
                        event: $event,
                        baseUnitValue: $baseUnitValue,
                        remainingResidualUnitValue: $this->precision->unitValue(
                            bcsub($remainingAfterInterest, $amortizationUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
                        ),
                    );

                    // AMi: 8 casas, sem arredondamento. Cada parcela já vem quantizada de
                    // `resolveAmortizationUnitValue()`, e a soma de valores de 8 casas continua
                    // exata em 8 -- a quantização aqui é idempotente e explicita a regra.
                    $amortizationUnitValue = $this->precision->unitValue(
                        bcadd($amortizationUnitValue, $resolvedAmortization, DecimalRounder::CALCULATION_SCALE + 4),
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

            $paymentTotalUnitValue = $this->precision->unitValue(
                bcadd($interestPaymentUnitValue, $amortizationUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
            );
            // SDa: 8 casas, sem arredondamento. É este valor que vira o VNb do período seguinte,
            // então quantizá-lo aqui é o que impede uma cauda invisível de atravessar o reset.
            $residualUnitValue = $this->precision->unitValue(
                bcsub($updatedUnitValue, $paymentTotalUnitValue, DecimalRounder::CALCULATION_SCALE + 4),
            );

            if (bccomp($residualUnitValue, '0', DecimalRounder::UNIT_SCALE) < 0) {
                $residualUnitValue = $this->precision->unitValue('0');
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
                'calendar_code' => $accrualCalendar,
                'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode,
                // Perfil de cálculo desta linha. `contractual` é a autoridade do Nimbus; qualquer
                // outro valor só existe em simulação e nunca alimenta caminho operacional.
                'calculation_profile' => $profile->value,
                'calculation_profile_label' => $profile->label(),
                'calculation_profile_is_operational' => $profile->isOperational(),
                'base_unit_value_raw' => $baseUnitValue,
                'base_unit_value_unquantized_raw' => $baseUnitValueUnquantized,
                'factor_di_raw' => $factorDi,
                'factor_di_accumulated_raw' => $factorDiAccumulated,
                // Fator DI efetivamente entregue à combinação com o Spread. No contratual é o
                // acumulado arredondado em 8; no legado é o próprio acumulado.
                'factor_di_applied_raw' => $factorDiApplied,
                'factor_spread_unrounded_raw' => $factorSpreadUnrounded,
                'factor_spread_raw' => $factorSpread,
                'factor_spread_di_raw' => $factorSpreadDi,
                'interest_factor_applied_raw' => $interestFactor,
                'factor_spread_di_before_first_coupon_premium_raw' => $factorSpreadDiBeforeOpeningPremium,
                'interest_real_unit_value_unquantized_raw' => $interestUnquantizedUnitValue,
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
                'precision_rules' => $precisionRules,
                'coupon_period_start_date' => $couponPeriodStartDate->toDateString(),
                'coupon_period_end_date' => $currentDate->toDateString(),
                'last_payment_date' => $lastPaymentDate?->toDateString(),
                'reset_after_payment' => bccomp($paymentTotalUnitValue, '0', DecimalRounder::UNIT_SCALE) === 1,
                'first_coupon_pre_integralization_premium_applied' => $openingPremiumAppliedOnCurrentRow,
                'first_coupon_pre_integralization_premium' => $openingPremiumAppliedOnCurrentRow
                    ? $openingPremium?->toCalculationMemory()
                    : null,
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
     * @return array<string, Collection<int, EmissionPuEvent>>
     */
    /**
     * Calendário que decide o Dia Útil de accrual. Nulo devolve o contratual, e é por isso que a
     * produção inteira -- que nunca informa a hipótese -- continua idêntica.
     */
    private function accrualCalendarCode(
        EmissionPuParameter $parameter,
        ?string $accrualCalendarCode,
    ): string {
        $override = $accrualCalendarCode !== null ? trim($accrualCalendarCode) : '';

        return $override !== '' ? $override : (string) $parameter->calendar_code;
    }

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

    /**
     * Casas decimais aplicadas em cada estágio da composição do fator, conforme o modo de
     * busca do índice. Espelha exatamente o que `CdiFactorCompositionService` executa --
     * nenhuma regra nova é introduzida aqui.
     *
     * `null` significa que o estágio NÃO sofre arredondamento intermediário nesse modo, e
     * segue na escala de cálculo.
     *
     * O produtório do Fator DI é o único estágio que não arredonda: ele TRUNCA em 16 casas
     * após cada multiplicação. `accumulated_index_factor_mode` carrega essa distinção para
     * que a memória de cálculo não apresente um corte como se fosse arredondamento.
     *
     * @return array<string, int|string|null>
     */
    private function precisionRules(
        EmissionPuParameter $parameter,
        PuCalculationProfile $profile = PuCalculationProfile::Contractual,
    ): array {
        $mode = $parameter->index_rate_lookup_mode_enum;
        $roundsDailyAndIndexFactor = $mode === PuIndexRateLookupMode::BusinessDayLagExact;
        $truncatesAccumulatedIndexFactor = $mode === PuIndexRateLookupMode::BusinessDayLagExact;
        // O perfil legado leva o produtório integral para a combinação: o estágio existe, mas não
        // arredonda. A memória precisa dizer isso, e não repetir "8 casas" fora do contratual.
        $roundsIndexFactorForCombination = $roundsDailyAndIndexFactor
            && $profile->roundsIndexFactorForCombination();
        $roundsSpreadFactor = $roundsDailyAndIndexFactor && $profile->roundsSpreadFactor();
        $roundsCombinedFactor = in_array($mode, [
            PuIndexRateLookupMode::BusinessDayLagExact,
            PuIndexRateLookupMode::PreviousCalendarDayExact,
        ], true);

        return [
            'calculation_profile' => $profile->value,
            'rounding_mode' => 'half_up_away_from_zero',
            'daily_index_factor' => $roundsDailyAndIndexFactor ? 8 : null,
            // O produtório NÃO é arredondado: é truncado em 16 casas após cada
            // multiplicação. `accumulated_index_factor_mode` existe justamente
            // para que a memória não descreva um corte como arredondamento.
            'accumulated_index_factor' => $truncatesAccumulatedIndexFactor
                ? CdiFactorCompositionService::ACCUMULATED_INDEX_FACTOR_TRUNCATION_SCALE
                : DecimalRounder::CALCULATION_SCALE,
            'accumulated_index_factor_mode' => $truncatesAccumulatedIndexFactor
                ? 'truncate_after_each_multiplication'
                : 'half_up_away_from_zero',
            'index_factor_for_combination' => $roundsIndexFactorForCombination ? 8 : null,
            'spread_factor' => $roundsSpreadFactor ? 9 : null,
            'combined_interest_factor' => $roundsCombinedFactor ? 9 : null,
            // VNb, J, AMi e SDa: 8 casas SEM arredondamento. É corte, não meio-para-cima, e é a
            // engine que corta -- a apresentação apenas formata a string já quantizada.
            'unit_base_value' => PuPrecisionPolicy::UNIT_VALUE_SCALE,
            'interest_unit_value' => PuPrecisionPolicy::UNIT_VALUE_SCALE,
            'amortization_unit_value' => PuPrecisionPolicy::UNIT_VALUE_SCALE,
            'residual_unit_value' => PuPrecisionPolicy::UNIT_VALUE_SCALE,
            'unit_value_quantization' => 'truncate_toward_zero',
        ];
    }

    /**
     * O período de juros é encerrado pelo pagamento UNITÁRIO da linha anterior, nunca pelo
     * financeiro. Uma curva sem timeline de integralização -- simulação e homologação unitária --
     * tem quantidade zero em toda data, e o financeiro seria zero mesmo no dia do cupom: o
     * período nunca reiniciaria e a linha seguinte seguiria acumulando Fator DI, Fator Spread e
     * DUP desde a integralização.
     */
    private function shouldResetAfterPreviousRow(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        /** @var PuDailyCurveRowData $lastRow */
        $lastRow = $rows[array_key_last($rows)];

        return $lastRow->hasUnitPayment();
    }

    /**
     * AMi da linha. O Termo fixa a amortização unitária em 8 casas SEM arredondamento, então toda
     * origem -- percentual sobre o VNb, valor unitário informado ou residual -- sai daqui já
     * quantizada. O teto continua sendo o residual disponível, que também é um valor de 8 casas.
     */
    private function resolveAmortizationUnitValue(
        EmissionPuEvent $event,
        string $baseUnitValue,
        string $remainingResidualUnitValue,
    ): string {
        $resolvedValue = match ($event->amortization_type_enum) {
            PuAmortizationType::None => $this->precision->unitValue('0'),
            PuAmortizationType::Residual => $this->precision->unitValue($remainingResidualUnitValue),
            PuAmortizationType::Percentage => $this->precision->unitValue(
                bcmul(
                    $baseUnitValue,
                    (string) ($event->amortization_value ?? '0'),
                    DecimalRounder::CALCULATION_SCALE + 4,
                ),
            ),
            PuAmortizationType::UnitValue => $this->precision->unitValue(
                (string) ($event->amortization_value ?? '0'),
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
}

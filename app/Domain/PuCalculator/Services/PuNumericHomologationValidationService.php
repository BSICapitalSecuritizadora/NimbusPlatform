<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCandidateCurve;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationPlan;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationValidationResult;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Models\EmissionPuParameter;

final class PuNumericHomologationValidationService
{
    public function __construct(
        private readonly PuIndexRateRequirementResolver $rateRequirements,
    ) {}

    public function validate(
        PuCandidateCurve $candidate,
        PuNumericHomologationPlan $plan,
        EmissionPuParameter $parameter,
    ): PuNumericHomologationValidationResult {
        $failures = [];
        $warnings = [];
        $information = [];
        $rateSamples = [];
        $validationCount = 0;
        $rows = $candidate->rows;

        $validationCount++;

        if ($rows === []) {
            $this->failure($failures, 'candidate_curve_empty', 'A engine não produziu a primeira linha requerida.');

            return new PuNumericHomologationValidationResult(
                status: 'failed',
                validationCount: $validationCount,
                blockingFailures: $failures,
                warnings: $warnings,
                information: $information,
                rateSamples: $rateSamples,
            );
        }

        $dates = array_map(fn (PuDailyCurveRowData $row): string => $row->date->toDateString(), $rows);
        $validationCount++;

        if (count(array_unique($dates)) !== count($dates)) {
            $this->failure($failures, 'duplicate_curve_date', 'A curva contém datas duplicadas.');
        }

        $validationCount++;

        for ($index = 1; $index < count($rows); $index++) {
            if (! $rows[$index]->date->equalTo($rows[$index - 1]->date->addDay())) {
                $this->failure($failures, 'non_deterministic_daily_order', 'As linhas devem estar em ordem diária estritamente crescente.', [
                    'previous' => $rows[$index - 1]->date->toDateString(),
                    'current' => $rows[$index]->date->toDateString(),
                ]);

                break;
            }
        }

        $first = $rows[0];
        $last = $rows[array_key_last($rows)];
        $validationCount++;

        if ($first->date->toDateString() !== $plan->curveStartDate) {
            $this->failure($failures, 'required_first_line_missing', 'A primeira data financeira requerida não foi gerada.', [
                'expected' => $plan->curveStartDate,
                'actual' => $first->date->toDateString(),
            ]);
        }

        $validationCount++;

        if ($last->date->toDateString() !== $plan->homologationEndDate) {
            $this->failure($failures, 'required_last_line_missing', 'A curva terminou antes da data de corte requerida.', [
                'expected' => $plan->homologationEndDate,
                'actual' => $last->date->toDateString(),
            ]);
        }

        // Um decimal corrompido torna todo o BCMath a jusante inseguro: `bccomp`
        // lança `ValueError` diante de string não numérica. O bloqueio de forma
        // decimal, portanto, encerra a validação.
        if (! $this->validateDecimals($rows, $failures, $validationCount)) {
            return new PuNumericHomologationValidationResult(
                status: 'failed',
                validationCount: $validationCount,
                blockingFailures: $failures,
                warnings: $warnings,
                information: $information,
                rateSamples: $rateSamples,
            );
        }

        $this->validatePrincipalAndUnitValues($rows, $failures, $validationCount);
        $this->validateFirstLine($first, $parameter, $failures, $information, $validationCount);
        $this->validateRates($rows, $plan, $parameter, $failures, $information, $rateSamples, $validationCount);
        $this->validateEvents($rows, $plan, $failures, $information, $validationCount);
        $this->validatePremium($rows, $plan, $parameter, $failures, $information, $rateSamples, $validationCount);

        return new PuNumericHomologationValidationResult(
            status: $failures === [] ? 'passed' : 'failed',
            validationCount: $validationCount,
            blockingFailures: $failures,
            warnings: $warnings,
            information: $information,
            rateSamples: $rateSamples,
        );
    }

    /**
     * @param  list<PuDailyCurveRowData>  $rows
     * @param  list<array<string, mixed>>  $failures
     */
    private function validateDecimals(array $rows, array &$failures, int &$validationCount): bool
    {
        $validationCount++;
        $fields = [
            'unit_base_value' => 'unitBaseValue',
            'unit_corrected_value' => 'unitCorrectedValue',
            'factor_di' => 'factorDi',
            'factor_di_accumulated' => 'factorDiAccumulated',
            'factor_spread' => 'factorSpread',
            'factor_spread_di' => 'factorSpreadDi',
            'interest_real_unit_value' => 'interestRealUnitValue',
            'updated_unit_value' => 'updatedUnitValue',
            'amortization_ratio' => 'amortizationRatio',
            'amortization_unit_value' => 'amortizationUnitValue',
            'residual_unit_value' => 'residualUnitValue',
            'interest_payment_unit_value' => 'interestPaymentUnitValue',
            'payment_total_unit_value' => 'paymentTotalUnitValue',
        ];

        foreach ($rows as $row) {
            foreach ($fields as $field => $property) {
                $value = $row->{$property};

                if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value) === 1) {
                    continue;
                }

                $this->failure($failures, 'invalid_decimal', 'A curva contém decimal inválido, NaN ou notação não canônica.', [
                    'curve_date' => $row->date->toDateString(),
                    'field' => $field,
                    'value' => $value,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<PuDailyCurveRowData>  $rows
     * @param  list<array<string, mixed>>  $failures
     */
    private function validatePrincipalAndUnitValues(array $rows, array &$failures, int &$validationCount): void
    {
        $validationCount++;

        foreach ($rows as $row) {
            foreach ([
                'unit_base_value' => $row->unitBaseValue,
                'updated_unit_value' => $row->updatedUnitValue,
                'amortization_unit_value' => $row->amortizationUnitValue,
                'residual_unit_value' => $row->residualUnitValue,
            ] as $field => $value) {
                if (bccomp($value, '0', DecimalRounder::UNIT_SCALE) >= 0) {
                    continue;
                }

                $this->failure($failures, 'invalid_principal_sign', 'Principal, amortização e PU não podem assumir sinal negativo.', [
                    'curve_date' => $row->date->toDateString(),
                    'field' => $field,
                    'value' => $value,
                ]);

                return;
            }

            if (bccomp($row->factorDiAccumulated, '0', DecimalRounder::FACTOR_SCALE) <= 0) {
                $this->failure($failures, 'impossible_factor', 'O fator DI acumulado deve permanecer estritamente positivo.', [
                    'curve_date' => $row->date->toDateString(),
                    'value' => $row->factorDiAccumulated,
                ]);

                return;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     * @param  list<array<string, mixed>>  $information
     */
    private function validateFirstLine(
        PuDailyCurveRowData $first,
        EmissionPuParameter $parameter,
        array &$failures,
        array &$information,
        int &$validationCount,
    ): void {
        $validationCount++;
        $expectedInitial = (string) $parameter->initial_unit_value;

        if (bccomp($first->factorDi, '1', DecimalRounder::FACTOR_SCALE) !== 0
            || bccomp($first->factorDiAccumulated, '1', DecimalRounder::FACTOR_SCALE) !== 0) {
            $this->failure($failures, 'invalid_first_line_factor', 'Na representação da engine, o fator DI diário e acumulado da primeira linha devem ser 1.', [
                'factor_di' => $first->factorDi,
                'factor_di_accumulated' => $first->factorDiAccumulated,
            ]);
        }

        $validationCount++;

        if (bccomp($first->unitBaseValue, $expectedInitial, DecimalRounder::UNIT_SCALE) !== 0
            || bccomp($first->updatedUnitValue, $expectedInitial, DecimalRounder::UNIT_SCALE) !== 0) {
            $this->failure($failures, 'initial_unit_value_mismatch', 'A primeira posição não reconcilia com o VNU inicial persistido.', [
                'expected' => $expectedInitial,
                'unit_base_value' => $first->unitBaseValue,
                'updated_unit_value' => $first->updatedUnitValue,
            ]);
        }

        $information[] = [
            'code' => 'first_line_semantics',
            'message' => 'A engine representa factor_di=1 e factor_di_accumulated=1; factor_spread/factor_spread_di permanecem 0 na linha inicial.',
            'context' => [
                'curve_date' => $first->date->toDateString(),
                'factor_spread' => $first->factorSpread,
                'factor_spread_di' => $first->factorSpreadDi,
            ],
        ];
    }

    /**
     * @param  list<PuDailyCurveRowData>  $rows
     * @param  list<array<string, mixed>>  $failures
     * @param  list<array<string, mixed>>  $information
     * @param  list<array<string, mixed>>  $rateSamples
     */
    private function validateRates(
        array $rows,
        PuNumericHomologationPlan $plan,
        EmissionPuParameter $parameter,
        array &$failures,
        array &$information,
        array &$rateSamples,
        int &$validationCount,
    ): void {
        $validationCount++;
        $availableRates = collect($plan->rates)->keyBy('date');
        $sampleAdded = false;

        foreach ($rows as $row) {
            $requirement = $this->rateRequirements->resolve($parameter, $row->date);

            if (! $requirement->isRequiredForCalculation()) {
                continue;
            }

            $requiredDate = $requirement->requiredRateDate()?->toDateString();
            $actualDate = $row->indexRateDate?->toDateString();

            if ($requiredDate === null
                || $actualDate !== $requiredDate
                || ! $availableRates->has($requiredDate)) {
                $this->failure($failures, 'exact_rate_lookup_unresolved', 'A linha não consumiu o snapshot exato exigido pelo resolver oficial.', [
                    'curve_date' => $row->date->toDateString(),
                    'lookup_mode' => $requirement->lookupMode->value,
                    'lag_business_days' => $requirement->businessDayLag,
                    'required_rate_date' => $requiredDate,
                    'actual_rate_date' => $actualDate,
                ]);

                continue;
            }

            if (! $sampleAdded && ! $row->date->equalTo($rows[0]->date)) {
                $rateSamples[] = [
                    'origin' => 'regular_curve_accrual',
                    'curve_date' => $row->date->toDateString(),
                    'lookup_mode' => $requirement->lookupMode->value,
                    'lag_business_days' => $requirement->businessDayLag,
                    'rate_date' => $actualDate,
                    'rate_value' => $row->indexRateValue,
                ];
                $sampleAdded = true;
            }
        }

        $information[] = [
            'code' => 'exact_rate_coverage',
            'message' => 'Todas as linhas que exigem índice foram confrontadas com BusinessDayLagExact sem fallback.',
            'context' => ['required_rate_count' => count($plan->requiredRateDates)],
        ];
    }

    /**
     * @param  list<PuDailyCurveRowData>  $rows
     * @param  list<array<string, mixed>>  $failures
     * @param  list<array<string, mixed>>  $information
     */
    private function validateEvents(
        array $rows,
        PuNumericHomologationPlan $plan,
        array &$failures,
        array &$information,
        int &$validationCount,
    ): void {
        $validationCount++;
        $rowsByDate = collect($rows)->keyBy(fn (PuDailyCurveRowData $row): string => $row->date->toDateString());
        $lastCurveDate = $rows[array_key_last($rows)]->date->toDateString();
        $interestCount = 0;
        $principalCount = 0;
        $adjustedCount = 0;
        $deferredCount = 0;

        foreach ($plan->events as $event) {
            $effectiveDate = (string) ($event['effective_date'] ?? '');
            $eventType = (string) ($event['event_type'] ?? '');

            // A exigência oficial recorta os eventos por `original_date`, mas a
            // convenção `following_business_day` pode empurrar a data efetiva
            // para além do corte da homologação. Nesse caso o evento ainda não
            // é liquidável na janela avaliada — é diagnóstico, não anomalia.
            if ($effectiveDate > $lastCurveDate) {
                $deferredCount++;
                $information[] = [
                    'code' => 'event_effective_after_candidate_window',
                    'message' => 'Evento requerido pela data original, mas com data efetiva ajustada além do corte da candidate.',
                    'context' => [
                        'event_type' => $eventType,
                        'original_date' => $event['original_date'] ?? null,
                        'effective_date' => $effectiveDate,
                        'last_curve_date' => $lastCurveDate,
                    ],
                ];

                continue;
            }

            /** @var PuDailyCurveRowData|null $row */
            $row = $rowsByDate->get($effectiveDate);

            if (! $row instanceof PuDailyCurveRowData
                || ! in_array($eventType, $row->calculationMemory['event_types'] ?? [], true)) {
                $this->failure($failures, 'event_reference_unresolved', 'Um evento persistido requerido não foi aplicado na linha efetiva.', [
                    'event_type' => $eventType,
                    'original_date' => $event['original_date'] ?? null,
                    'effective_date' => $effectiveDate,
                    'sequence' => $event['sequence'] ?? null,
                ]);

                continue;
            }

            if ($eventType === PuEventType::InterestPayment->value) {
                $interestCount++;
            }

            if ($eventType === PuEventType::Amortization->value) {
                $principalCount++;

                if (($event['amortization_type'] ?? null) === PuAmortizationType::Residual->value
                    && bccomp($row->unitBaseValue, '0', DecimalRounder::UNIT_SCALE) === 1
                    && bccomp($row->amortizationUnitValue, '0', DecimalRounder::UNIT_SCALE) !== 1) {
                    $this->failure($failures, 'bullet_principal_not_applied', 'O evento bullet residual não amortizou o principal no vencimento.', [
                        'curve_date' => $effectiveDate,
                        'amortization_unit_value' => $row->amortizationUnitValue,
                    ]);
                }
            }

            if (($event['original_date'] ?? null) !== $effectiveDate) {
                $adjustedCount++;

                if ($row->eventEffectiveDate?->toDateString() !== $effectiveDate) {
                    $this->failure($failures, 'following_business_day_not_applied', 'A data efetiva ajustada não foi preservada na linha do evento.', [
                        'original_date' => $event['original_date'] ?? null,
                        'effective_date' => $effectiveDate,
                    ]);
                }
            }
        }

        $information[] = [
            'code' => 'event_coverage',
            'message' => 'Eventos mensais, principal bullet e ajustes following foram validados a partir do conjunto persistido.',
            'context' => [
                'interest_events' => $interestCount,
                'principal_events' => $principalCount,
                'following_adjustments' => $adjustedCount,
                'deferred_beyond_window' => $deferredCount,
            ],
        ];
    }

    /**
     * @param  list<PuDailyCurveRowData>  $rows
     * @param  list<array<string, mixed>>  $failures
     * @param  list<array<string, mixed>>  $information
     * @param  list<array<string, mixed>>  $rateSamples
     */
    private function validatePremium(
        array $rows,
        PuNumericHomologationPlan $plan,
        EmissionPuParameter $parameter,
        array &$failures,
        array &$information,
        array &$rateSamples,
        int &$validationCount,
    ): void {
        $validationCount++;
        $premiumRows = array_values(array_filter(
            $rows,
            fn (PuDailyCurveRowData $row): bool => (bool) ($row->calculationMemory['first_coupon_pre_integralization_premium_applied'] ?? false),
        ));
        $lastCurveDate = $rows[array_key_last($rows)]->date->toDateString();
        $hasInterestEvent = collect($plan->events)->contains(
            fn (array $event): bool => ($event['event_type'] ?? null) === PuEventType::InterestPayment->value
                && (string) ($event['effective_date'] ?? '') <= $lastCurveDate,
        );
        $expectedApplications = $parameter->hasFirstCouponPreIntegralizationPremium() && $hasInterestEvent ? 1 : 0;

        if (count($premiumRows) !== $expectedApplications) {
            $this->failure($failures, 'premium_application_count_mismatch', 'O prêmio pré-integralização deve entrar exatamente uma vez no primeiro cupom alcançado.', [
                'expected_applications' => $expectedApplications,
                'actual_applications' => count($premiumRows),
            ]);

            return;
        }

        if ($premiumRows === []) {
            $information[] = [
                'code' => 'premium_not_yet_due',
                'message' => 'A janela não alcança o primeiro cupom; nenhum prêmio foi aplicado.',
                'context' => [],
            ];

            return;
        }

        $premiumRow = $premiumRows[0];
        $memory = $premiumRow->calculationMemory['first_coupon_pre_integralization_premium'] ?? null;

        if (! is_array($memory)
            || ($memory['application'] ?? null) !== 'first_interest_payment_only'
            || (int) ($memory['business_days_before_start'] ?? 0) !== (int) $parameter->first_coupon_pre_integralization_business_days
            || (bool) ($memory['apply_index_factor'] ?? false) !== (bool) $parameter->first_coupon_pre_integralization_apply_index_factor
            || (bool) ($memory['apply_spread_factor'] ?? false) !== (bool) $parameter->first_coupon_pre_integralization_apply_spread_factor) {
            $this->failure($failures, 'premium_memory_mismatch', 'A memória do prêmio não reconcilia com o parâmetro persistido.', [
                'curve_date' => $premiumRow->date->toDateString(),
            ]);

            return;
        }

        $validationCount++;
        $expectedResidual = bcsub(
            $premiumRow->unitBaseValue,
            $premiumRow->amortizationUnitValue,
            DecimalRounder::UNIT_SCALE,
        );

        if (bccomp($premiumRow->residualUnitValue, $expectedResidual, DecimalRounder::UNIT_SCALE) !== 0) {
            $this->failure($failures, 'premium_capitalized_in_principal', 'Após pagar a remuneração, o prêmio não pode permanecer capitalizado no principal.', [
                'curve_date' => $premiumRow->date->toDateString(),
                'expected_residual' => $expectedResidual,
                'actual_residual' => $premiumRow->residualUnitValue,
            ]);
        }

        foreach ($memory['accrual_days'] ?? [] as $day) {
            if (! is_array($day)) {
                continue;
            }

            $rateSamples[] = [
                'origin' => 'first_coupon_pre_integralization_premium',
                'curve_date' => $day['accrual_date'] ?? null,
                'lookup_mode' => $day['index_rate_lookup_mode'] ?? null,
                'lag_business_days' => $day['index_rate_lag_business_days'] ?? null,
                'rate_date' => $day['required_index_rate_date'] ?? null,
                'rate_value' => $day['index_rate_value'] ?? null,
            ];
        }

        $information[] = [
            'code' => 'premium_one_time_first_coupon_remuneration',
            'message' => 'O prêmio DI + spread foi aplicado uma única vez como remuneração do primeiro cupom, sem aumento permanente do principal.',
            'context' => [
                'curve_date' => $premiumRow->date->toDateString(),
                'business_days' => $memory['business_days_before_start'] ?? null,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     * @param  array<string, mixed>  $context
     */
    private function failure(array &$failures, string $code, string $message, array $context = []): void
    {
        $failures[] = [
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\FirstCouponPreIntegralizationPremiumData;
use App\Domain\PuCalculator\DTOs\PuIndexRateRequirement;
use App\Models\BusinessCalendarSelectionEvidence;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class FirstCouponPreIntegralizationPremiumCalculator
{
    public const EVIDENCE_CONTEXT = 'pu_first_coupon_pre_integralization_premium';

    public function __construct(
        private readonly PuIndexRateRequirementResolver $requirementResolver,
        private readonly CdiFactorCompositionService $factorComposition,
        private readonly DecimalRounder $rounder,
    ) {}

    /**
     * `$indexRateCalendarCode` desloca apenas a data da taxa observada em cada
     * Dia Útil de acúmulo; nenhuma conta do prêmio muda por causa dele.
     */
    public function calculate(
        EmissionPuParameter $parameter,
        ?string $indexRateCalendarCode = null,
    ): ?FirstCouponPreIntegralizationPremiumData {
        if (! $parameter->hasFirstCouponPreIntegralizationPremium()) {
            return null;
        }

        $accrualDates = $this->requirementResolver->firstCouponPreIntegralizationAccrualDates($parameter);
        $requirements = collect(
            $this->requirementResolver->firstCouponPreIntegralizationRateRequirements(
                $parameter,
                $indexRateCalendarCode,
            ),
        )->keyBy(fn (PuIndexRateRequirement $requirement): string => $requirement->curveDate->toDateString());
        $appliesIndex = (bool) $parameter->first_coupon_pre_integralization_apply_index_factor;
        $appliesSpread = (bool) $parameter->first_coupon_pre_integralization_apply_spread_factor;
        $factorDiAccumulated = $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
        $dailySpreadFactor = $appliesSpread
            ? $this->factorComposition->spreadFactor($parameter, 1)
            : $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
        $days = [];

        foreach ($accrualDates as $accrualDate) {
            $requirement = $requirements->get($accrualDate->toDateString());

            if ($appliesIndex && ! $requirement instanceof PuIndexRateRequirement) {
                throw new InvalidArgumentException(sprintf(
                    'Não foi possível resolver a Taxa DI do prêmio para o Dia Útil %s.',
                    $accrualDate->toDateString(),
                ));
            }

            if ($appliesIndex && $requirement->rate === null) {
                throw new InvalidArgumentException(
                    "Snapshot obrigatório do prêmio pré-integralização ausente.\n\n{$requirement->missingRateMessage()}",
                );
            }

            $dailyIndexFactor = $appliesIndex
                ? $this->factorComposition->dailyIndexFactor($parameter, $requirement)
                : $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);

            if ($appliesIndex) {
                $factorDiAccumulated = $this->factorComposition->accumulateIndexFactor(
                    $factorDiAccumulated,
                    $dailyIndexFactor,
                );
            }

            $dailyCombinedFactor = $this->factorComposition->factorForInterest(
                $parameter,
                $this->factorComposition->combinedFactor(
                    $parameter,
                    $dailyIndexFactor,
                    $dailySpreadFactor,
                ),
            );
            $days[] = $this->dayMemory(
                $parameter,
                $accrualDate,
                $requirement,
                $dailyIndexFactor,
                $dailySpreadFactor,
                $dailyCombinedFactor,
            );
        }

        $factorDi = $appliesIndex
            ? $this->factorComposition->indexFactorForCombination($parameter, $factorDiAccumulated)
            : $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
        $factorSpread = $appliesSpread
            ? $this->factorComposition->spreadFactor($parameter, count($accrualDates))
            : $this->rounder->normalize('1', DecimalRounder::CALCULATION_SCALE);
        $factor = $this->factorComposition->factorForInterest(
            $parameter,
            $this->factorComposition->combinedFactor($parameter, $factorDi, $factorSpread),
        );

        return new FirstCouponPreIntegralizationPremiumData(
            businessDays: count($accrualDates),
            calendarCode: (string) $parameter->calendar_code,
            appliesIndexFactor: $appliesIndex,
            appliesSpreadFactor: $appliesSpread,
            accrualDays: $days,
            factorDi: $this->rounder->round($factorDi, DecimalRounder::FACTOR_SCALE),
            factorSpread: $this->rounder->round($factorSpread, DecimalRounder::FACTOR_SCALE),
            factor: $this->rounder->round($factor, DecimalRounder::FACTOR_SCALE),
            evidence: $this->evidence($parameter),
        );
    }

    /** @return array<string, mixed> */
    private function dayMemory(
        EmissionPuParameter $parameter,
        CarbonImmutable $accrualDate,
        ?PuIndexRateRequirement $requirement,
        string $factorDi,
        string $factorSpread,
        string $factor,
    ): array {
        return [
            'accrual_date' => $accrualDate->toDateString(),
            'calendar_code' => (string) $parameter->calendar_code,
            'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode_enum->value,
            'index_rate_lag_business_days' => (int) $parameter->index_rate_lag_business_days,
            'required_index_rate_date' => $requirement?->requiredRateDate()?->toDateString(),
            'index_rate_value' => $requirement?->rate?->reportedValue(),
            'factor_di' => $this->rounder->round($factorDi, DecimalRounder::FACTOR_SCALE),
            'factor_spread' => $this->rounder->round($factorSpread, DecimalRounder::FACTOR_SCALE),
            'factor_total_daily_reference' => $this->rounder->round($factor, DecimalRounder::FACTOR_SCALE),
        ];
    }

    /** @return array<string, mixed>|null */
    private function evidence(EmissionPuParameter $parameter): ?array
    {
        if (! $parameter->exists) {
            return null;
        }

        $evidence = $parameter->selectionEvidence()
            ->where('context', self::EVIDENCE_CONTEXT)
            ->latest('id')
            ->first();

        if (! $evidence instanceof BusinessCalendarSelectionEvidence) {
            return null;
        }

        return [
            'document' => $evidence->source_document,
            'clause' => $evidence->clause_reference,
            'page' => $evidence->page_reference,
            'excerpt' => $evidence->excerpt,
            'notes' => $evidence->notes,
            'responsible_user_id' => $evidence->created_by,
            'confirmed_by' => $evidence->confirmed_by,
            'confirmed_at' => $evidence->confirmed_at?->toIso8601String(),
        ];
    }
}

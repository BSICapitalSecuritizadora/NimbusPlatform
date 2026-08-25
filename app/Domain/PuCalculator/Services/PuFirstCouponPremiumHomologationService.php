<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Enums\PuIndexer;
use App\Models\Emission;
use App\Models\EmissionPuParameter;
use InvalidArgumentException;

final class PuFirstCouponPremiumHomologationService
{
    public function __construct(private readonly PuCurveGenerationService $curveGenerator) {}

    /** @return array<string, mixed> */
    public function compare(
        Emission $emission,
        int $businessDaysBeforeStart,
        bool $applyIndexFactor = true,
        bool $applySpreadFactor = true,
    ): array {
        $emission->loadMissing(['puParameter', 'puEvents', 'integralizationHistories']);
        $activeParameter = $emission->puParameter;

        if (! $activeParameter instanceof EmissionPuParameter) {
            throw new InvalidArgumentException('A homologação exige parâmetros de PU configurados.');
        }

        if ($activeParameter->indexer_enum !== PuIndexer::Cdi) {
            throw new InvalidArgumentException('A homologação do prêmio pré-integralização está disponível somente para CDI.');
        }

        if ($businessDaysBeforeStart <= 0) {
            throw new InvalidArgumentException('A quantidade de Dias Úteis pré-integralização deve ser maior que zero.');
        }

        if (! $applyIndexFactor && ! $applySpreadFactor) {
            throw new InvalidArgumentException('Ao menos o Fator DI ou o Fator Spread deve ser aplicado.');
        }

        $withoutPremium = $this->scenarioParameter($activeParameter, false, null, true, true);
        $withPremium = $this->scenarioParameter(
            $activeParameter,
            true,
            $businessDaysBeforeStart,
            $applyIndexFactor,
            $applySpreadFactor,
        );
        $withoutRows = $this->generate($emission, $withoutPremium);
        $withRows = $this->generate($emission, $withPremium);
        $interestDates = $emission->puEvents
            ->filter(fn ($event): bool => $event->event_type_enum === PuEventType::InterestPayment)
            ->sortBy('effective_date')
            ->pluck('effective_date')
            ->map(fn ($date): string => $date->toDateString())
            ->unique()
            ->values();
        $withoutByDate = collect($withoutRows)->keyBy(fn (PuDailyCurveRowData $row): string => $row->date->toDateString());
        $withByDate = collect($withRows)->keyBy(fn (PuDailyCurveRowData $row): string => $row->date->toDateString());
        $interestPayments = $interestDates
            ->map(function (string $date) use ($withoutByDate, $withByDate): array {
                /** @var PuDailyCurveRowData|null $without */
                $without = $withoutByDate->get($date);
                /** @var PuDailyCurveRowData|null $with */
                $with = $withByDate->get($date);

                return [
                    'date' => $date,
                    'without_premium' => $this->rowSnapshot($without),
                    'with_premium' => $this->rowSnapshot($with),
                    'interest_payment_unit_value_difference' => $this->subtract(
                        $with?->interestPaymentUnitValue,
                        $without?->interestPaymentUnitValue,
                    ),
                ];
            })
            ->all();
        $payload = [
            'emission_id' => $emission->getKey(),
            'without_premium_parameter' => $this->parameterSnapshot($withoutPremium),
            'with_premium_parameter' => $this->parameterSnapshot($withPremium),
            'interest_payments' => $interestPayments,
        ];

        return [
            ...$payload,
            'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function scenarioParameter(
        EmissionPuParameter $activeParameter,
        bool $enabled,
        ?int $businessDays,
        bool $applyIndexFactor,
        bool $applySpreadFactor,
    ): EmissionPuParameter {
        $parameter = $activeParameter->replicate();
        $parameter->forceFill([
            'first_coupon_pre_integralization_premium_enabled' => $enabled,
            'first_coupon_pre_integralization_business_days' => $businessDays,
            'first_coupon_pre_integralization_apply_index_factor' => $applyIndexFactor,
            'first_coupon_pre_integralization_apply_spread_factor' => $applySpreadFactor,
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

    /** @return array<string, mixed>|null */
    private function rowSnapshot(?PuDailyCurveRowData $row): ?array
    {
        if (! $row instanceof PuDailyCurveRowData) {
            return null;
        }

        return [
            'factor_total' => $row->factorSpreadDi,
            'interest_unit_value' => $row->interestRealUnitValue,
            'interest_payment_unit_value' => $row->interestPaymentUnitValue,
            'residual_unit_value' => $row->residualUnitValue,
            'premium_applied' => $row->calculationMemory['first_coupon_pre_integralization_premium_applied'] ?? false,
            'premium_memory' => $row->calculationMemory['first_coupon_pre_integralization_premium'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function parameterSnapshot(EmissionPuParameter $parameter): array
    {
        return [
            'curve_start_date' => $parameter->curve_start_date?->toDateString(),
            'curve_end_date' => $parameter->curve_end_date?->toDateString(),
            'calendar_code' => $parameter->calendar_code,
            'index_rate_lookup_mode' => $parameter->index_rate_lookup_mode_enum->value,
            'index_rate_lag_business_days' => (int) $parameter->index_rate_lag_business_days,
            'first_coupon_pre_integralization_premium_enabled' => $parameter->hasFirstCouponPreIntegralizationPremium(),
            'first_coupon_pre_integralization_business_days' => $parameter->first_coupon_pre_integralization_business_days,
            'first_coupon_pre_integralization_apply_index_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_index_factor,
            'first_coupon_pre_integralization_apply_spread_factor' => (bool) $parameter->first_coupon_pre_integralization_apply_spread_factor,
        ];
    }

    private function subtract(?string $left, ?string $right): ?string
    {
        if ($left === null || $right === null) {
            return null;
        }

        return bcsub($left, $right, DecimalRounder::UNIT_SCALE);
    }
}

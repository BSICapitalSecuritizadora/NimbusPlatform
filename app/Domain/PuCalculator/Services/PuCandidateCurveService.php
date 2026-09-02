<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuCandidateCurve;
use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\DTOs\PuNumericHomologationPlan;
use App\Models\Emission;
use App\Models\EmissionPuEvent;
use App\Models\EmissionPuParameter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;

/**
 * Executa a engine oficial de PU em memória, sobre um cenário clonado, sem
 * gravar nada. A classe é aberta para extensão exclusivamente para permitir
 * cenários de teste que simulam desaparecimento de pré-requisito entre o plano
 * e o cálculo (TOCTOU).
 */
class PuCandidateCurveService
{
    public function __construct(
        private readonly PuCurveGeneratorService $curveGenerator,
        private readonly PuNumericHomologationFingerprintService $fingerprints,
        private readonly IndexRateLookupService $indexRateLookup,
    ) {}

    public function generate(Emission $emission, PuNumericHomologationPlan $plan): PuCandidateCurve
    {
        if (! $plan->canEvaluate || $plan->parameterId === null || $plan->homologationEndDate === null) {
            throw new InvalidArgumentException('O plano não permite gerar uma curva candidata.');
        }

        $parameter = EmissionPuParameter::query()
            ->whereBelongsTo($emission)
            ->whereKey($plan->parameterId)
            ->first();

        if (! $parameter instanceof EmissionPuParameter) {
            throw new InvalidArgumentException('O parâmetro persistido da homologação não está mais disponível.');
        }

        $effectiveEndDate = CarbonImmutable::parse($plan->homologationEndDate)->startOfDay();
        $scenarioParameter = clone $parameter;
        $scenarioParameter->setAttribute('curve_end_date', $effectiveEndDate);
        $events = EmissionPuEvent::query()
            ->whereBelongsTo($emission)
            ->whereDate('effective_date', '>=', $parameter->curve_start_date)
            ->whereDate('effective_date', '<=', $effectiveEndDate)
            ->orderBy('effective_date')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        $scenario = clone $emission;
        $scenario->setRelation('puParameter', $scenarioParameter);
        $scenario->setRelation('puEvents', $events);
        $scenario->setRelation('integralizationHistories', new EloquentCollection);
        $this->indexRateLookup->flushCache();

        $rows = $this->curveGenerator->handle($scenario)->rows;
        $first = $rows[0] ?? null;
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new PuCandidateCurve(
            rows: $rows,
            checksum: $this->fingerprints->curveChecksum($rows),
            rowCount: count($rows),
            from: $first?->date->toDateString(),
            to: $last?->date->toDateString(),
            initialUnitValue: $first?->updatedUnitValue,
            lastUnitValue: $last?->residualUnitValue,
            checkpoints: $this->checkpoints($rows),
        );
    }

    /**
     * @param  list<PuDailyCurveRowData>  $rows
     * @return list<array<string, mixed>>
     */
    private function checkpoints(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $selected = [
            'first_curve_line' => $rows[0],
            'middle_curve_line' => $rows[intdiv(count($rows) - 1, 2)],
            'last_curve_line' => $rows[array_key_last($rows)],
        ];

        foreach ($rows as $row) {
            if (! isset($selected['first_regular_cdi_accrual'])
                && $row->isBusinessDay
                && $row->date->toDateString() !== $rows[0]->date->toDateString()) {
                $selected['first_regular_cdi_accrual'] = $row;
            }

            if (! isset($selected['first_coupon'])
                && in_array('interest_payment', $row->calculationMemory['event_types'] ?? [], true)) {
                $selected['first_coupon'] = $row;
            }

            if (! isset($selected['event_adjusted_line'])
                && $row->eventOriginalDate !== null
                && $row->eventEffectiveDate !== null
                && ! $row->eventOriginalDate->equalTo($row->eventEffectiveDate)) {
                $selected['event_adjusted_line'] = $row;
            }
        }

        $checkpoints = [];
        $seenDates = [];

        foreach ($selected as $label => $row) {
            $date = $row->date->toDateString();

            if (isset($seenDates[$date])) {
                continue;
            }

            $seenDates[$date] = true;
            $checkpoints[] = [
                'label' => $label,
                'curve_date' => $date,
                'unit_base_value' => $row->unitBaseValue,
                'updated_unit_value' => $row->updatedUnitValue,
                'residual_unit_value' => $row->residualUnitValue,
                'interest_payment_unit_value' => $row->interestPaymentUnitValue,
                'amortization_unit_value' => $row->amortizationUnitValue,
                'index_rate_date' => $row->indexRateDate?->toDateString(),
                'index_rate_value' => $row->indexRateValue,
                'event_types' => $row->calculationMemory['event_types'] ?? [],
                'premium_applied' => $row->calculationMemory['first_coupon_pre_integralization_premium_applied'] ?? false,
            ];
        }

        return $checkpoints;
    }
}

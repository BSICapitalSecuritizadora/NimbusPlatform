<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Models\EmissionPuEvent;
use App\Models\IntegralizationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Helpers puros (eventos, quantidade vigente, amortização, reset) compartilhados pelas
 * engines que NÃO o CDI. A engine CDI (PuCurveGenerationService) permanece intocada com
 * suas próprias cópias privadas — esta classe espelha o mesmo comportamento já calibrado.
 */
class PuCurveEventSupport
{
    public function __construct(
        private readonly DecimalRounder $rounder,
    ) {}

    /**
     * @param  EloquentCollection<int, EmissionPuEvent>  $events
     * @return array<string, \Illuminate\Support\Collection<int, EmissionPuEvent>>
     */
    public function groupEventsByDate(EloquentCollection $events): array
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
    public function buildQuantityTimeline(EloquentCollection $integralizations): array
    {
        $cumulativeQuantity = '0.0000';
        $timeline = [];

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
    public function quantityForDate(array $quantityTimeline, CarbonImmutable $date): string
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
     * @param  list<PuDailyCurveRowData>  $rows
     */
    public function shouldResetAfterPreviousRow(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        $lastRow = $rows[array_key_last($rows)];

        return $lastRow->hasPayment();
    }

    public function resolveAmortizationUnitValue(
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
}

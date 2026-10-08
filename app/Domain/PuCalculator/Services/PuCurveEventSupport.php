<?php

declare(strict_types=1);

namespace App\Domain\PuCalculator\Services;

use App\Domain\PuCalculator\DTOs\PuDailyCurveRowData;
use App\Domain\PuCalculator\Enums\PuAmortizationType;
use App\Domain\PuCalculator\Enums\PuEventType;
use App\Domain\PuCalculator\Exceptions\PuCurveInputsException;
use App\Models\EmissionPuEvent;
use App\Models\IntegralizationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Helpers puros (eventos, quantidade vigente, amortização, reset) compartilhados pelas
 * engines que NÃO o CDI. A engine CDI (PuCurveGenerationService) permanece intocada com
 * suas próprias cópias privadas — esta classe espelha o mesmo comportamento já calibrado.
 */
class PuCurveEventSupport
{
    public function __construct(
        private readonly DecimalRounder $rounder,
        private readonly PuFinancialEffectSupport $effectSupport,
    ) {}

    /**
     * Pagamentos do cronograma ATIVOS, agrupados pela data efetiva e na ordem
     * canônica ({@see PuEventType::orderingKey()}). Evento cancelado não entra, nem o
     * waiver declarado sem efeito no PU (Fase 5); evento ativo de um tipo que estas
     * engines não calculam (alteração de spread, amortização extraordinária,
     * regimes, vencimento antecipado...) recusa o cálculo em vez de sumir.
     *
     * @param  EloquentCollection<int, EmissionPuEvent>  $events
     * @return array<string, Collection<int, EmissionPuEvent>>
     */
    public function groupEventsByDate(EloquentCollection $events): array
    {
        $active = $events
            ->filter(fn (EmissionPuEvent $event): bool => $event->isActive())
            ->reject(fn (EmissionPuEvent $event): bool => $this->effectSupport->isInert(PuFinancialEffectSupport::fromModel($event)));

        foreach ($active as $event) {
            $type = PuEventType::tryFrom((string) $event->event_type);

            if (! ($type?->isScheduledPayment() ?? false)) {
                throw new PuCurveInputsException(sprintf(
                    'O evento %s de %s tem efeito financeiro que esta engine não calcula; a curva não foi calculada.',
                    $type?->label() ?? (string) $event->event_type,
                    CarbonImmutable::instance($event->effective_date)->format('d/m/Y'),
                ));
            }
        }

        return $active
            ->sortBy(fn (EmissionPuEvent $event): string => PuEventType::orderingKey(
                CarbonImmutable::instance($event->effective_date)->toDateString(),
                (string) $event->event_type,
                (int) $event->sequence,
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
     * Encerramento do período de juros pela linha anterior. O critério é o pagamento UNITÁRIO,
     * e não o financeiro: a quantidade em carteira não governa o ciclo do cupom, e uma curva sem
     * timeline de integralização tem quantidade zero em toda data.
     *
     * @param  list<PuDailyCurveRowData>  $rows
     */
    public function shouldResetAfterPreviousRow(array $rows): bool
    {
        if ($rows === []) {
            return false;
        }

        $lastRow = $rows[array_key_last($rows)];

        return $lastRow->hasUnitPayment();
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

<?php

namespace App\Services\Obligations;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Enums\ObligationDueDateCalculationStatus;
use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationInitialDateInclusion;
use App\Enums\ObligationInvalidDayPolicy;
use App\Enums\ObligationOffsetDirection;
use App\Exceptions\ObligationCalendarCoverageException;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ObligationScheduleCalculator
{
    private const MAX_OCCURRENCES_PER_RULE = 2400;

    public function __construct(
        private readonly BusinessDayCalendar $businessDayCalendar,
        private readonly ObligationCalendarGuard $calendarGuard,
    ) {}

    /**
     * @return list<array{competence_date: CarbonImmutable, due_date: ?CarbonImmutable, due_date_resolution: array<string, mixed>, rule: ObligationSeriesRule}>
     */
    public function occurrencesForRule(
        ObligationSeries $series,
        ObligationSeriesRule $rule,
        CarbonInterface $horizon,
        ?CarbonInterface $nextRuleEffectiveFrom = null,
    ): array {
        $intervalInMonths = $rule->frequency->intervalInMonths();

        if ($intervalInMonths === null || $series->ends_on === null || $rule->due_rule_type?->dependsOnAnchorEvent()) {
            return [];
        }

        $anchor = CarbonImmutable::instance($rule->effective_from)->startOfMonth();
        $seriesEnd = CarbonImmutable::instance($series->ends_on)->endOfDay();
        $ruleEnd = $nextRuleEffectiveFrom !== null
            ? CarbonImmutable::instance($nextRuleEffectiveFrom)->startOfMonth()->subDay()->endOfDay()
            : $seriesEnd;
        $effectiveEnd = $ruleEnd->min($seriesEnd);
        $competenceLimit = $rule->due_rule_type === ObligationDueRuleType::CalendarDaysAfterCompetenceEnd
            ? CarbonImmutable::instance($horizon)->subDays($rule->due_offset_days ?? 0)->startOfMonth()
            : CarbonImmutable::instance($horizon)->startOfMonth()->subMonths($rule->due_offset_months);
        $effectiveCompetenceLimit = $competenceLimit->min($effectiveEnd);
        $occurrences = [];

        for ($sequence = 0; $sequence < self::MAX_OCCURRENCES_PER_RULE; $sequence++) {
            $competenceDate = $anchor->addMonthsNoOverflow($sequence * $intervalInMonths)->startOfMonth();

            if ($competenceDate->gt($effectiveCompetenceLimit)) {
                break;
            }

            $resolution = $this->resolveDueDateWithExplanation($rule, $competenceDate);
            $dueDate = $resolution->dueDate;

            if (
                $dueDate === null
                && $resolution->calculationStatus !== ObligationDueDateCalculationStatus::AwaitingCalendar
            ) {
                continue;
            }

            if ($dueDate?->gt($horizon)) {
                continue;
            }

            $occurrences[] = [
                'competence_date' => $competenceDate,
                'due_date' => $dueDate,
                'due_date_resolution' => $resolution->toArray(),
                'rule' => $rule,
            ];
        }

        return $occurrences;
    }

    public function resolveDueDate(
        ObligationSeriesRule $rule,
        CarbonInterface $competenceDate,
    ): ?CarbonImmutable {
        return $this->resolveDueDateWithExplanation($rule, $competenceDate)->dueDate;
    }

    public function resolveDueDateWithExplanation(
        ObligationSeriesRule $rule,
        CarbonInterface $referenceDate,
    ): ObligationDueDateResolution {
        $referenceDate = CarbonImmutable::instance($referenceDate)->startOfDay();
        $targetMonth = $referenceDate
            ->startOfMonth()
            ->addMonthsNoOverflow($rule->due_offset_months);

        return match ($rule->due_rule_type) {
            ObligationDueRuleType::FixedDay => new ObligationDueDateResolution(
                $this->resolveFixedDay($rule, $targetMonth),
                'Dia fixo do mês.',
            ),
            ObligationDueRuleType::LastDay => new ObligationDueDateResolution(
                $targetMonth->endOfMonth()->startOfDay(),
                'Último dia calendário do mês.',
            ),
            ObligationDueRuleType::NthBusinessDay => $this->resolveNthBusinessDay($rule, $targetMonth),
            ObligationDueRuleType::CalendarDaysAfterCompetenceEnd => new ObligationDueDateResolution(
                $this->resolveCalendarDaysAfterCompetenceEnd($rule, $referenceDate),
                sprintf('%d dia(s) corrido(s) após o fim da competência.', $rule->due_offset_days),
            ),
            ObligationDueRuleType::BusinessDaysRelativeToEvent => $this->resolveBusinessDaysRelativeToEvent($rule, $referenceDate),
            default => new ObligationDueDateResolution(null, 'Regra sem resolução automática.'),
        };
    }

    /**
     * @return array{competence_date: CarbonImmutable, due_date: CarbonImmutable, due_date_resolution: array<string, mixed>, rule: ObligationSeriesRule}|null
     */
    public function nextOccurrence(
        ObligationSeries $series,
        ?CarbonInterface $referenceDate = null,
    ): ?array {
        if ($series->ends_on === null) {
            return null;
        }

        $referenceDate = CarbonImmutable::instance($referenceDate ?? now())->startOfDay();
        $rules = $series->relationLoaded('rules') ? $series->rules : $series->rules()->get();
        $maximumCalendarDayOffset = (int) ($rules->max('due_offset_days') ?? 0);
        $horizon = CarbonImmutable::instance($series->ends_on)
            ->addMonths(12)
            ->addDays($maximumCalendarDayOffset)
            ->endOfMonth();
        $candidates = collect();

        foreach ($rules->values() as $index => $rule) {
            $candidates->push(...$this->occurrencesForRule(
                $series,
                $rule,
                $horizon,
                $rules->get($index + 1)?->effective_from,
            ));
        }

        return $candidates
            ->filter(fn (array $candidate): bool => $candidate['due_date'] instanceof CarbonImmutable
                && $candidate['due_date']->gte($referenceDate))
            ->sortBy(fn (array $candidate): string => $candidate['due_date']->toDateString())
            ->first();
    }

    private function resolveFixedDay(
        ObligationSeriesRule $rule,
        CarbonImmutable $targetMonth,
    ): ?CarbonImmutable {
        if ($rule->due_day === null || $rule->due_day < 1) {
            return null;
        }

        if ($rule->due_day <= $targetMonth->daysInMonth) {
            return $targetMonth->setDay($rule->due_day);
        }

        return $rule->invalid_day_policy === ObligationInvalidDayPolicy::LastValidDay
            ? $targetMonth->endOfMonth()->startOfDay()
            : null;
    }

    private function resolveNthBusinessDay(
        ObligationSeriesRule $rule,
        CarbonImmutable $targetMonth,
    ): ObligationDueDateResolution {
        if ($rule->due_day === null || $rule->due_day < 1) {
            return new ObligationDueDateResolution(null, 'Número do dia útil inválido.');
        }

        $businessDayNumber = 0;
        $skippedDates = [];

        for ($day = 1; $day <= $targetMonth->daysInMonth; $day++) {
            $candidate = $targetMonth->setDay($day);

            try {
                $this->calendarGuard->assertDateCovered($rule, $candidate);
            } catch (ObligationCalendarCoverageException $exception) {
                return new ObligationDueDateResolution(
                    dueDate: null,
                    rule: sprintf('%dº dia útil do mês.', $rule->due_day),
                    calendarCode: $rule->calendar_code,
                    quantity: $rule->due_day,
                    skippedDates: $skippedDates,
                    calculationStatus: ObligationDueDateCalculationStatus::AwaitingCalendar,
                    calculationPeriodFrom: $targetMonth->startOfMonth(),
                    calculationPeriodTo: $targetMonth->endOfMonth(),
                    calendarYears: $this->calendarGuard->calendarSnapshotForRange(
                        $rule,
                        $targetMonth->startOfMonth(),
                        $targetMonth->endOfMonth(),
                    ),
                    blockingReason: $exception->getMessage(),
                    requiredCalendarDate: $exception->requiredDate,
                );
            }

            $decision = $this->businessDayCalendar->explain($candidate, $rule->calendar_code);

            if (! $decision->isBusinessDay) {
                $skippedDates[] = [
                    'date' => $candidate->toDateString(),
                    'reason' => $decision->reason,
                ];

                continue;
            }

            $businessDayNumber++;

            if ($businessDayNumber === $rule->due_day) {
                return new ObligationDueDateResolution(
                    dueDate: $candidate,
                    rule: sprintf('%dº dia útil do mês.', $rule->due_day),
                    calendarCode: $rule->calendar_code,
                    quantity: $rule->due_day,
                    skippedDates: $skippedDates,
                    calculationPeriodFrom: $targetMonth->startOfMonth(),
                    calculationPeriodTo: $candidate,
                    calendarYears: $this->calendarGuard->calendarSnapshotForRange(
                        $rule,
                        $targetMonth->startOfMonth(),
                        $candidate,
                    ),
                );
            }
        }

        return new ObligationDueDateResolution(
            dueDate: null,
            rule: sprintf('%dº dia útil não encontrado no mês.', $rule->due_day),
            calendarCode: $rule->calendar_code,
            quantity: $rule->due_day,
            skippedDates: $skippedDates,
        );
    }

    private function resolveBusinessDaysRelativeToEvent(
        ObligationSeriesRule $rule,
        CarbonImmutable $anchorDate,
    ): ObligationDueDateResolution {
        $quantity = (int) $rule->relative_offset_quantity;
        $direction = $rule->relative_offset_direction;
        $initialDateInclusion = $rule->initial_date_inclusion;

        if (
            $quantity < 1
            || ! $direction instanceof ObligationOffsetDirection
            || ! $initialDateInclusion instanceof ObligationInitialDateInclusion
            || blank($rule->calendar_code)
        ) {
            return new ObligationDueDateResolution(null, 'Parâmetros do deslocamento em dias úteis incompletos.');
        }

        $candidate = $initialDateInclusion === ObligationInitialDateInclusion::Included
            ? $anchorDate
            : $anchorDate->addDays($direction->step());
        $remaining = $quantity;
        $skippedDates = [];

        for ($attempt = 0; $attempt < 3700; $attempt++) {
            try {
                $this->calendarGuard->assertDateCovered($rule, $candidate);
            } catch (ObligationCalendarCoverageException $exception) {
                return new ObligationDueDateResolution(
                    dueDate: null,
                    rule: sprintf(
                        '%d dia(s) útil(eis) %s evento.',
                        $quantity,
                        $direction === ObligationOffsetDirection::After ? 'após o' : 'antes do',
                    ),
                    anchorDate: $anchorDate,
                    calendarCode: $rule->calendar_code,
                    quantity: $quantity,
                    direction: $direction->value,
                    initialDateInclusion: $initialDateInclusion->value,
                    skippedDates: $skippedDates,
                    calculationStatus: ObligationDueDateCalculationStatus::AwaitingCalendar,
                    calculationPeriodFrom: $anchorDate->min($candidate),
                    calculationPeriodTo: $anchorDate->max($candidate),
                    calendarYears: $this->calendarGuard->calendarSnapshotForRange(
                        $rule,
                        $anchorDate->min($candidate),
                        $anchorDate->max($candidate),
                    ),
                    blockingReason: $exception->getMessage(),
                    requiredCalendarDate: $exception->requiredDate,
                );
            }

            $decision = $this->businessDayCalendar->explain($candidate, $rule->calendar_code);

            if ($decision->isBusinessDay) {
                $remaining--;

                if ($remaining === 0) {
                    return new ObligationDueDateResolution(
                        dueDate: $candidate,
                        rule: sprintf(
                            '%d dia(s) útil(eis) %s evento.',
                            $quantity,
                            $direction === ObligationOffsetDirection::After ? 'após o' : 'antes do',
                        ),
                        anchorDate: $anchorDate,
                        calendarCode: $rule->calendar_code,
                        quantity: $quantity,
                        direction: $direction->value,
                        initialDateInclusion: $initialDateInclusion->value,
                        skippedDates: $skippedDates,
                        calculationPeriodFrom: $anchorDate->min($candidate),
                        calculationPeriodTo: $anchorDate->max($candidate),
                        calendarYears: $this->calendarGuard->calendarSnapshotForRange(
                            $rule,
                            $anchorDate->min($candidate),
                            $anchorDate->max($candidate),
                        ),
                    );
                }
            } else {
                $skippedDates[] = [
                    'date' => $candidate->toDateString(),
                    'reason' => $decision->reason,
                ];
            }

            $candidate = $candidate->addDays($direction->step());
        }

        return new ObligationDueDateResolution(
            dueDate: null,
            rule: 'Não foi possível resolver o deslocamento dentro do limite operacional.',
            anchorDate: $anchorDate,
            calendarCode: $rule->calendar_code,
            quantity: $quantity,
            direction: $direction->value,
            initialDateInclusion: $initialDateInclusion->value,
            skippedDates: $skippedDates,
        );
    }

    private function resolveCalendarDaysAfterCompetenceEnd(
        ObligationSeriesRule $rule,
        CarbonInterface $competenceDate,
    ): ?CarbonImmutable {
        if ($rule->due_offset_days === null || $rule->due_offset_days < 1) {
            return null;
        }

        return CarbonImmutable::instance($competenceDate)
            ->endOfMonth()
            ->startOfDay()
            ->addDays($rule->due_offset_days);
    }
}

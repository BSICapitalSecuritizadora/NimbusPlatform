<?php

namespace App\Services\Obligations;

use App\Domain\PuCalculator\Contracts\BusinessDayCalendar;
use App\Enums\ObligationDueRuleType;
use App\Enums\ObligationInvalidDayPolicy;
use App\Models\ObligationSeries;
use App\Models\ObligationSeriesRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ObligationScheduleCalculator
{
    private const MAX_OCCURRENCES_PER_RULE = 2400;

    public function __construct(
        private readonly BusinessDayCalendar $businessDayCalendar,
    ) {}

    /**
     * @return list<array{competence_date: CarbonImmutable, due_date: CarbonImmutable, rule: ObligationSeriesRule}>
     */
    public function occurrencesForRule(
        ObligationSeries $series,
        ObligationSeriesRule $rule,
        CarbonInterface $horizon,
        ?CarbonInterface $nextRuleEffectiveFrom = null,
    ): array {
        $intervalInMonths = $rule->frequency->intervalInMonths();

        if ($intervalInMonths === null || $series->ends_on === null) {
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

            $dueDate = $this->resolveDueDate($rule, $competenceDate);

            if ($dueDate === null || $dueDate->gt($horizon)) {
                continue;
            }

            $occurrences[] = [
                'competence_date' => $competenceDate,
                'due_date' => $dueDate,
                'rule' => $rule,
            ];
        }

        return $occurrences;
    }

    public function resolveDueDate(
        ObligationSeriesRule $rule,
        CarbonInterface $competenceDate,
    ): ?CarbonImmutable {
        $targetMonth = CarbonImmutable::instance($competenceDate)
            ->startOfMonth()
            ->addMonthsNoOverflow($rule->due_offset_months);

        return match ($rule->due_rule_type) {
            ObligationDueRuleType::FixedDay => $this->resolveFixedDay($rule, $targetMonth),
            ObligationDueRuleType::LastDay => $targetMonth->endOfMonth()->startOfDay(),
            ObligationDueRuleType::NthBusinessDay => $this->resolveNthBusinessDay($rule, $targetMonth),
            ObligationDueRuleType::CalendarDaysAfterCompetenceEnd => $this->resolveCalendarDaysAfterCompetenceEnd($rule, $competenceDate),
            default => null,
        };
    }

    /**
     * @return array{competence_date: CarbonImmutable, due_date: CarbonImmutable, rule: ObligationSeriesRule}|null
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
            ->filter(fn (array $candidate): bool => $candidate['due_date']->gte($referenceDate))
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
    ): ?CarbonImmutable {
        if ($rule->due_day === null || $rule->due_day < 1) {
            return null;
        }

        $businessDayNumber = 0;

        for ($day = 1; $day <= $targetMonth->daysInMonth; $day++) {
            $candidate = $targetMonth->setDay($day);

            if (! $this->businessDayCalendar->isBusinessDay($candidate, $rule->calendar_code)) {
                continue;
            }

            $businessDayNumber++;

            if ($businessDayNumber === $rule->due_day) {
                return $candidate;
            }
        }

        return null;
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

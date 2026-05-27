<?php

namespace App\Actions\Expenses;

use App\Models\Expense;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuildExpenseCalendar
{
    /**
     * @param  array{emission_id?: int|string|null, category?: string|null}  $filters
     * @return array{
     *     month_label: string,
     *     visible_month: string,
     *     summary: array{event_count: int, total_amount: string, operation_count: int},
     *     weeks: array<int, array<int, array{
     *         date: string,
     *         day_number: string,
     *         is_current_month: bool,
     *         is_today: bool,
     *         events: array<int, array{
     *             id: string,
     *             date: string,
     *             amount: float,
     *             operation: string,
     *             category: string,
     *             service_provider: string,
     *             amount_label: string,
     *             period_label: string
     *         }>
     *     }>>
     * }
     */
    public function handle(CarbonInterface|string|null $month = null, array $filters = []): array
    {
        $monthStart = $this->resolveMonthStart($month);
        $monthEnd = $monthStart->endOfMonth();
        $gridStart = $monthStart->startOfWeek(CarbonInterface::MONDAY);
        $gridEnd = $monthEnd->endOfWeek(CarbonInterface::SUNDAY);
        $events = $this->buildEvents($monthStart, $monthEnd, $filters);
        $eventsByDate = $events->groupBy('date');
        $today = now()->toDateString();
        $weeks = [];
        $cursor = $gridStart;

        while ($cursor->lte($gridEnd)) {
            $week = [];

            foreach (range(1, 7) as $ignored) {
                $dateKey = $cursor->toDateString();

                $week[] = [
                    'date' => $dateKey,
                    'day_number' => $cursor->format('d'),
                    'is_current_month' => $cursor->isSameMonth($monthStart),
                    'is_today' => $dateKey === $today,
                    'events' => $eventsByDate->get($dateKey, collect())->values()->all(),
                ];

                $cursor = $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return [
            'month_label' => mb_convert_case($monthStart->locale('pt_BR')->translatedFormat('F \d\e Y'), MB_CASE_TITLE, 'UTF-8'),
            'visible_month' => $monthStart->format('Y-m'),
            'summary' => [
                'event_count' => $events->count(),
                'total_amount' => $this->formatCurrency($events->sum('amount')),
                'operation_count' => $events->pluck('operation')->filter()->unique()->count(),
            ],
            'weeks' => $weeks,
        ];
    }

    protected function resolveMonthStart(CarbonInterface|string|null $month): CarbonImmutable
    {
        if ($month instanceof CarbonInterface) {
            return CarbonImmutable::instance($month)->startOfMonth();
        }

        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        }

        return now()->toImmutable()->startOfMonth();
    }

    protected function buildEvents(CarbonImmutable $monthStart, CarbonImmutable $monthEnd, array $filters = []): Collection
    {
        return Expense::query()
            ->with(['emission', 'serviceProvider'])
            ->when(
                filled($filters['emission_id'] ?? null),
                fn (Builder $query): Builder => $query->where('emission_id', $filters['emission_id']),
            )
            ->when(
                filled($filters['category'] ?? null),
                fn (Builder $query): Builder => $query->where('category', $filters['category']),
            )
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->where(function (Builder $query) use ($monthStart): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $monthStart->toDateString());
            })
            ->get()
            ->flatMap(fn (Expense $expense): array => $this->buildExpenseEvents($expense, $monthStart, $monthEnd))
            ->sortBy([
                ['date', 'asc'],
                ['operation', 'asc'],
                ['category', 'asc'],
            ])
            ->values();
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     date: string,
     *     amount: float,
     *     operation: string,
     *     category: string,
     *     service_provider: string,
     *     amount_label: string,
     *     period_label: string
     * }>
     */
    protected function buildExpenseEvents(Expense $expense, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $occurrenceDate = $this->resolveOccurrenceDate($expense, $monthStart, $monthEnd);

        if ($occurrenceDate === null) {
            return [];
        }

        return [[
            'id' => "expense-{$expense->getKey()}-{$occurrenceDate->format('Ymd')}",
            'date' => $occurrenceDate->toDateString(),
            'amount' => round((float) $expense->amount, 2),
            'operation' => (string) ($expense->emission?->name ?? 'Operação sem nome'),
            'category' => $expense->category,
            'service_provider' => (string) ($expense->serviceProvider?->name ?? 'Prestador não informado'),
            'amount_label' => $this->formatCurrency($expense->amount),
            'period_label' => Expense::PERIOD_OPTIONS[$expense->period] ?? $expense->period,
        ]];
    }

    protected function resolveOccurrenceDate(Expense $expense, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): ?CarbonImmutable
    {
        $startDate = CarbonImmutable::instance($expense->start_date);
        $endDate = $expense->end_date !== null
            ? CarbonImmutable::instance($expense->end_date)
            : null;

        if ($expense->period === Expense::PERIOD_SINGLE) {
            return $this->isWithinMonth($startDate, $monthStart, $monthEnd)
                ? $startDate
                : null;
        }

        $intervalInMonths = Expense::periodIntervalInMonths($expense->period);

        if ($intervalInMonths === null || $startDate->gt($monthEnd)) {
            return null;
        }

        if ($endDate !== null && $endDate->lt($monthStart)) {
            return null;
        }

        $monthDifference = (($monthStart->year - $startDate->year) * 12) + ($monthStart->month - $startDate->month);

        if ($monthDifference < 0 || ($monthDifference % $intervalInMonths) !== 0) {
            return null;
        }

        $occurrenceDate = $startDate->addMonthsNoOverflow($monthDifference);

        if (! $this->isWithinMonth($occurrenceDate, $monthStart, $monthEnd)) {
            return null;
        }

        if ($endDate !== null && $occurrenceDate->gt($endDate)) {
            return null;
        }

        return $occurrenceDate;
    }

    protected function isWithinMonth(CarbonImmutable $date, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): bool
    {
        return $date->gte($monthStart) && $date->lte($monthEnd);
    }

    protected function formatCurrency(float|string|null $amount): string
    {
        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }
}

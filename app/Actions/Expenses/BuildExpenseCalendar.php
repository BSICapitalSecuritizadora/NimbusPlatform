<?php

namespace App\Actions\Expenses;

use App\Models\Expense;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    /**
     * @return Collection<int, array{
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
    protected function buildEvents(CarbonImmutable $monthStart, CarbonImmutable $monthEnd, array $filters = []): Collection
    {
        return Expense::query()
            ->with(['emission', 'serviceProvider'])
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->where(function ($query) use ($monthStart): void {
                $query
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $monthStart->toDateString());
            })
            ->when(
                filled($filters['emission_id'] ?? null),
                fn ($query): mixed => $query->where('emission_id', $filters['emission_id']),
            )
            ->when(
                filled($filters['category'] ?? null),
                fn ($query): mixed => $query->where('category', $filters['category']),
            )
            ->get()
            ->flatMap(function (Expense $expense) use ($monthStart, $monthEnd): Collection {
                return collect($this->resolveExpenseOccurrences($expense, $monthStart, $monthEnd))
                    ->map(fn (CarbonImmutable $occurrence): array => [
                        'id' => "expense-{$expense->getKey()}-{$occurrence->toDateString()}",
                        'date' => $occurrence->toDateString(),
                        'amount' => round((float) $expense->amount, 2),
                        'operation' => (string) ($expense->emission?->name ?? 'Operação sem nome'),
                        'category' => $expense->category,
                        'service_provider' => (string) ($expense->serviceProvider?->name ?? 'Prestador não informado'),
                        'amount_label' => $this->formatCurrency($expense->amount),
                        'period_label' => Expense::PERIOD_OPTIONS[$expense->period] ?? $expense->period,
                    ]);
            })
            ->sortBy([
                ['date', 'asc'],
                ['operation', 'asc'],
                ['category', 'asc'],
            ])
            ->values();
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    protected function resolveExpenseOccurrences(Expense $expense, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $seriesStart = CarbonImmutable::instance($expense->start_date)->startOfDay();
        $seriesEnd = $expense->end_date
            ? CarbonImmutable::instance($expense->end_date)->endOfDay()
            : $seriesStart->endOfDay();

        if ($expense->period === Expense::PERIOD_SINGLE) {
            return $seriesStart->betweenIncluded($monthStart, $monthEnd) ? [$seriesStart] : [];
        }

        $intervalInMonths = Expense::periodIntervalInMonths($expense->period);

        if (($intervalInMonths === null) || $seriesStart->gt($monthEnd) || $seriesEnd->lt($monthStart)) {
            return [];
        }

        $monthsDiff = max(0, $seriesStart->diffInMonths($monthStart));
        $occurrenceIndex = max(0, intdiv($monthsDiff, $intervalInMonths) - 1);
        $occurrences = [];

        while (true) {
            $occurrence = $seriesStart->addMonthsNoOverflow($occurrenceIndex * $intervalInMonths);

            if ($occurrence->gt($seriesEnd) || $occurrence->gt($monthEnd)) {
                break;
            }

            if ($occurrence->gte($monthStart)) {
                $occurrences[] = $occurrence;
            }

            $occurrenceIndex++;
        }

        return $occurrences;
    }

    protected function formatCurrency(float|string|null $amount): string
    {
        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }
}

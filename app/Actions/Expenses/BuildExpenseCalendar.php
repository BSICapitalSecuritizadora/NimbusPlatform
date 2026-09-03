<?php

namespace App\Actions\Expenses;

use App\Models\Expense;
use App\Services\Expenses\ExpenseOccurrenceResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuildExpenseCalendar
{
    public function __construct(
        protected ExpenseOccurrenceResolver $occurrenceResolver,
    ) {}

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
     *         events: array<int, array<string, mixed>>
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
            'month_label' => ucfirst($monthStart->locale('pt_BR')->translatedFormat('F \d\e Y')),
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
        $expenses = Expense::query()
            ->with(['emission', 'serviceProvider', 'histories'])
            ->when(
                filled($filters['emission_id'] ?? null),
                fn (Builder $query): Builder => $query->where('emission_id', $filters['emission_id']),
            )
            ->when(
                filled($filters['category'] ?? null),
                fn (Builder $query): Builder => $query->where('category', $filters['category']),
            )
            ->where(function (Builder $query) use ($monthStart, $monthEnd): void {
                $query->where(function (Builder $sub) use ($monthStart, $monthEnd): void {
                    $sub->whereDate('start_date', '<=', $monthEnd->toDateString())
                        ->where(function (Builder $sub2) use ($monthStart): void {
                            $sub2->whereNull('end_date')
                                ->orWhereDate('end_date', '>=', $monthStart->toDateString());
                        });
                })->orWhereHas('histories', function (Builder $sub) use ($monthStart, $monthEnd): void {
                    $sub->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
                });
            })
            ->get();

        return $this->occurrenceResolver->resolveForMonth(
            $expenses,
            $monthStart,
            $monthEnd,
            now()->toImmutable(),
        );
    }

    protected function formatCurrency(float|string|null $amount): string
    {
        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }
}

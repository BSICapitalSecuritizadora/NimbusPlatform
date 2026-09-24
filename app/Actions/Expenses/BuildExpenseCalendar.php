<?php

namespace App\Actions\Expenses;

use App\Models\Expense;
use App\Services\Expenses\ExpenseOccurrenceResolver;
use App\Support\Money\IntegerMoney;
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
     *     summary: array{
     *         event_count: int,
     *         total_amount: string,
     *         paid_amount: string,
     *         paid_percentage: ?string,
     *         outstanding_amount: string,
     *         settlement_label: ?string,
     *         operation_count: int
     *     },
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
        $expectedCents = $this->sumCents($events, 'amount');
        $paidCents = $this->sumCents($events, 'paid_amount');
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
                'total_amount' => $this->formatCents($expectedCents),
                'paid_amount' => $this->formatCents($paidCents),
                'paid_percentage' => $this->formatPaidPercentage($paidCents, $expectedCents),
                'outstanding_amount' => $this->formatCents($expectedCents - $paidCents),
                'settlement_label' => $this->settlementLabel($expectedCents, $paidCents),
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

    /**
     * Soma exata, em centavos, de um campo monetário das ocorrências.
     *
     * Previsto e pago somam o mesmo conjunto de ocorrências (mesmo mês de
     * vencimento, mesmos filtros), para serem diretamente comparáveis. Um
     * `paid_amount` nulo -- ocorrência em aberto -- soma zero: o pago vem do
     * valor apurado pelo resolvedor, nunca do previsto nem do status.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     */
    protected function sumCents(Collection $events, string $field): int
    {
        return $events->sum(fn (array $event): int => IntegerMoney::cents($event[$field] ?? null) ?? 0);
    }

    protected function formatCents(int $cents): string
    {
        return 'R$ '.IntegerMoney::format($cents);
    }

    protected function formatPaidPercentage(int $paidCents, int $expectedCents): ?string
    {
        $basisPoints = IntegerMoney::shareInBasisPoints($paidCents, $expectedCents);

        return $basisPoints !== null
            ? IntegerMoney::formatBasisPoints($basisPoints).'%'
            : null;
    }

    /**
     * Saldo entre previsto e pago, sem limitar o pago ao previsto: pagar
     * acima do previsto (juros, multa) é informado como tal, não escondido.
     */
    protected function settlementLabel(int $expectedCents, int $paidCents): ?string
    {
        if ($expectedCents <= 0) {
            return null;
        }

        $outstandingCents = $expectedCents - $paidCents;

        return match (true) {
            $outstandingCents > 0 => $this->formatCents($outstandingCents).' em aberto',
            $outstandingCents === 0 => 'Integralmente pago',
            default => $this->formatCents(-$outstandingCents).' acima do previsto',
        };
    }
}

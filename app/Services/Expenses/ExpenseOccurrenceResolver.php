<?php

namespace App\Services\Expenses;

use App\Enums\ExpensePaymentStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ExpenseOccurrenceResolver
{
    /**
     * Resolve todas as ocorrências de uma coleção de despesas para um determinado mês.
     *
     * @param  Collection<int, Expense>  $expenses
     * @return Collection<int, array<string, mixed>>
     */
    public function resolveForMonth(
        Collection $expenses,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        ?CarbonImmutable $referenceDate = null,
    ): Collection {
        $reference = $referenceDate ?? now()->toImmutable();

        return $expenses
            ->flatMap(fn (Expense $expense): Collection => $this->resolveExpenseOccurrences($expense, $monthStart, $monthEnd, $reference))
            ->sortBy([
                ['date', 'asc'],
                ['operation', 'asc'],
                ['category', 'asc'],
            ])
            ->values();
    }

    /**
     * Resolve as ocorrências de uma despesa individual em um mês específico.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function resolveExpenseOccurrences(
        Expense $expense,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        CarbonImmutable $referenceDate,
    ): Collection {
        $scheduledDate = $this->resolveScheduledDate($expense, $monthStart, $monthEnd);
        $occurrences = collect();
        $matchedHistoryIds = collect();

        if ($scheduledDate !== null) {
            $matchingHistories = $this->findMatchingHistories($expense, $scheduledDate);
            $matchedHistoryIds = $matchedHistoryIds->merge($matchingHistories->pluck('id'));

            $occurrences->push($this->buildOccurrencePayload(
                expense: $expense,
                occurrenceDate: $scheduledDate,
                matchingHistories: $matchingHistories,
                referenceDate: $referenceDate,
            ));
        }

        // Lançamentos no histórico pertencentes a este mês que não coincidiram com a data agendada
        $unmatchedHistories = $expense->histories
            ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->reject(fn (ExpenseHistory $history): bool => $matchedHistoryIds->contains($history->id));

        $unmatchedGrouped = $unmatchedHistories->groupBy(fn (ExpenseHistory $history): string => $history->due_date->toDateString());

        foreach ($unmatchedGrouped as $dateString => $historiesForDate) {
            $occurrenceDate = CarbonImmutable::parse($dateString);

            $occurrences->push($this->buildOccurrencePayload(
                expense: $expense,
                occurrenceDate: $occurrenceDate,
                matchingHistories: $historiesForDate,
                referenceDate: $referenceDate,
            ));
        }

        return $occurrences;
    }

    /**
     * Localiza os registros no Histórico de Pagamentos correspondentes a uma ocorrência.
     *
     * @return Collection<int, ExpenseHistory>
     */
    public function findMatchingHistories(Expense $expense, CarbonImmutable $occurrenceDate): Collection
    {
        $histories = $expense->histories;

        if ($histories->isEmpty()) {
            return collect();
        }

        // Prioridade 1: correspondência exata de vencimento
        $exactMatches = $histories->filter(
            fn (ExpenseHistory $history): bool => $history->due_date?->isSameDay($occurrenceDate) ?? false
        );

        if ($exactMatches->isNotEmpty()) {
            return $exactMatches->values();
        }

        // Prioridade 2: vencimento bancário ajustado para fim de mês (ex.: ocorrência 31/01 no fim de semana deslocada para 02/02)
        if ($occurrenceDate->day >= 28) {
            $shiftedMatches = $histories->filter(function (ExpenseHistory $history) use ($occurrenceDate): bool {
                if ($history->due_date === null) {
                    return false;
                }

                $diffDays = $occurrenceDate->diffInDays($history->due_date, false);

                // Deslocamento para frente entre 1 e 4 dias caindo no início do mês seguinte
                return $diffDays >= 1
                    && $diffDays <= 4
                    && $history->due_date->month !== $occurrenceDate->month
                    && $history->due_date->day <= 5;
            });

            if ($shiftedMatches->isNotEmpty()) {
                return $shiftedMatches->values();
            }
        }

        // Prioridade 3: correspondência por competência (mesmo ano e mês),
        // ignorando registros do início do mês (dias 1-5) caso a ocorrência seja no final do mês (dia >= 28)
        // para não capturar pagamentos deslocados do mês anterior.
        $competenceMatches = $histories->filter(function (ExpenseHistory $history) use ($occurrenceDate): bool {
            if ($history->due_date?->format('Y-m') !== $occurrenceDate->format('Y-m')) {
                return false;
            }

            if ($occurrenceDate->day >= 28 && $history->due_date->day <= 5) {
                return false;
            }

            return true;
        });

        if ($competenceMatches->isNotEmpty()) {
            // Se houver múltiplos registros no mesmo mês, seleciona o mais próximo da data da ocorrência
            $closest = $competenceMatches->sortBy(
                fn (ExpenseHistory $h): int => abs($occurrenceDate->diffInDays($h->due_date))
            );

            return collect([$closest->first()]);
        }

        // Prioridade 4: para despesa de período único, se houver histórico registrado para a mesma competência da data de início
        if ($expense->period === Expense::PERIOD_SINGLE) {
            $singleMatches = $histories->filter(
                fn (ExpenseHistory $history): bool => $history->due_date?->format('Y-m') === $expense->start_date?->format('Y-m')
            );

            if ($singleMatches->isNotEmpty()) {
                return $singleMatches->values();
            }
        }

        return collect();
    }

    /**
     * @param  Collection<int, ExpenseHistory>  $matchingHistories
     * @return array<string, mixed>
     */
    protected function buildOccurrencePayload(
        Expense $expense,
        CarbonImmutable $occurrenceDate,
        Collection $matchingHistories,
        CarbonImmutable $referenceDate,
    ): array {
        $fullyPaidHistories = $matchingHistories->filter(fn (ExpenseHistory $history): bool => $history->isFullyPaid());
        $partiallyPaidHistories = $matchingHistories->filter(fn (ExpenseHistory $history): bool => $history->isPartiallyPaid());
        $today = $referenceDate->startOfDay();

        if ($fullyPaidHistories->isNotEmpty()) {
            $status = ExpensePaymentStatus::Paid;
            $paidAmount = (float) $fullyPaidHistories->sum(
                fn (ExpenseHistory $h): float => (float) ($h->paid_amount ?? $h->amount)
            );
            /** @var ?ExpenseHistory $latestPaidWithDate */
            $latestPaidWithDate = $fullyPaidHistories->whereNotNull('payment_date')->sortByDesc(
                fn (ExpenseHistory $history): string => $history->payment_date->toDateString()
            )->first();
            $paymentDate = $latestPaidWithDate?->payment_date !== null
                ? CarbonImmutable::instance($latestPaidWithDate->payment_date)
                : null;
            $hasPayment = true;
        } elseif ($partiallyPaidHistories->isNotEmpty()) {
            $status = ExpensePaymentStatus::PartiallyPaid;
            $paidAmount = (float) $partiallyPaidHistories->sum(
                fn (ExpenseHistory $h): float => (float) ($h->paid_amount ?? 0.0)
            );
            /** @var ?ExpenseHistory $latestPaidWithDate */
            $latestPaidWithDate = $partiallyPaidHistories->whereNotNull('payment_date')->sortByDesc(
                fn (ExpenseHistory $history): string => $history->payment_date->toDateString()
            )->first();
            $paymentDate = $latestPaidWithDate?->payment_date !== null
                ? CarbonImmutable::instance($latestPaidWithDate->payment_date)
                : null;
            $hasPayment = false;
        } else {
            $paidAmount = null;
            $paymentDate = null;
            $hasPayment = false;
            $status = $occurrenceDate->lt($today)
                ? ExpensePaymentStatus::Overdue
                : ExpensePaymentStatus::Pending;
        }

        $expectedAmount = (float) ($expense->amount ?? $matchingHistories->first()?->amount ?? $paidAmount ?? 0.0);
        $remainingAmount = match ($status) {
            ExpensePaymentStatus::Paid => 0.0,
            ExpensePaymentStatus::PartiallyPaid => max(0.0, round($expectedAmount - ($paidAmount ?? 0.0), 2)),
            default => $expectedAmount,
        };

        return [
            'id' => "expense-{$expense->getKey()}-{$occurrenceDate->format('Ymd')}",
            'expense_id' => $expense->getKey(),
            'date' => $occurrenceDate->toDateString(),
            'due_date' => $occurrenceDate,
            'due_date_label' => $occurrenceDate->format('d/m/Y'),
            'amount' => round($expectedAmount, 2),
            'amount_label' => $this->formatCurrency($expectedAmount),
            'expected_amount' => round($expectedAmount, 2),
            'expected_amount_label' => $this->formatCurrency($expectedAmount),
            'has_payment' => $hasPayment,
            'status' => $status,
            'status_value' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'badge_classes' => $status->badgeClasses(),
            'dot_classes' => $status->dotClasses(),
            'payment_date' => $paymentDate?->toDateString(),
            'payment_date_label' => $paymentDate !== null ? $paymentDate->format('d/m/Y') : '—',
            'paid_amount' => $paidAmount !== null ? round($paidAmount, 2) : null,
            'paid_amount_label' => $paidAmount !== null ? $this->formatCurrency($paidAmount) : '—',
            'remaining_amount' => round($remainingAmount, 2),
            'remaining_amount_label' => $this->formatCurrency($remainingAmount),
            'is_partially_paid' => $status === ExpensePaymentStatus::PartiallyPaid,
            'operation' => (string) ($expense->emission?->name ?? 'Operação sem nome'),
            'category' => $expense->category,
            'service_provider' => (string) ($expense->serviceProvider?->name ?? 'Prestador não informado'),
            'period_label' => Expense::PERIOD_OPTIONS[$expense->period] ?? $expense->period,
            'url' => ExpenseResource::canEdit($expense)
                ? ExpenseResource::getUrl('edit', ['record' => $expense])
                : null,
            'is_overdue' => $status === ExpensePaymentStatus::Overdue,
            'is_due_soon' => ! $hasPayment
                && $occurrenceDate->gte($today)
                && $occurrenceDate->lte($today->addDays(7)->endOfDay()),
        ];
    }

    public function resolveScheduledDate(
        Expense $expense,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): ?CarbonImmutable {
        if ($expense->start_date === null) {
            return null;
        }

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

    public function formatCurrency(float|string|null $amount): string
    {
        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }
}

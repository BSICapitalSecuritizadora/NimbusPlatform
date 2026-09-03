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

        // Prioridade 2: correspondência por competência (mesmo ano e mês)
        $competenceMatches = $histories->filter(
            fn (ExpenseHistory $history): bool => $history->due_date?->format('Y-m') === $occurrenceDate->format('Y-m')
        );

        if ($competenceMatches->isNotEmpty()) {
            return $competenceMatches->values();
        }

        // Prioridade 3: para despesa de período único, se houver histórico registrado para a mesma competência da data de início
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
        $hasPayment = $matchingHistories->isNotEmpty();
        $today = $referenceDate->startOfDay();

        if ($hasPayment) {
            $status = ExpensePaymentStatus::Paid;
            $paidAmount = (float) $matchingHistories->sum('amount');
            /** @var ?ExpenseHistory $latestPaidHistory */
            $latestPaidHistory = $matchingHistories->sortByDesc(
                fn (ExpenseHistory $history): string => $history->effectivePaymentDate()?->format('Y-m-d') ?? ''
            )->first();
            $paymentDate = $latestPaidHistory?->effectivePaymentDate() !== null
                ? CarbonImmutable::instance($latestPaidHistory->effectivePaymentDate())
                : null;
        } else {
            $paidAmount = null;
            $paymentDate = null;
            $status = $occurrenceDate->lt($today)
                ? ExpensePaymentStatus::Overdue
                : ExpensePaymentStatus::Pending;
        }

        $expectedAmount = (float) ($expense->amount ?? $paidAmount ?? 0.0);

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

<?php

namespace App\Jobs;

use App\Models\Expense;
use App\Models\ExpenseHistory;
use App\Models\Fund;
use App\Services\ContaAzulClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncContaAzulExpensesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(ContaAzulClient $client): void
    {
        $from = config('conta-azul.sync_start_date', '2025-01-01');
        $to = now()->addYear()->toDateString();

        $funds = Fund::query()
            ->whereNotNull('conta_azul_account_id')
            ->get();

        foreach ($funds as $fund) {
            $this->syncFund($client, $fund, $from, $to);
        }
    }

    private function syncFund(ContaAzulClient $client, Fund $fund, string $from, string $to): void
    {
        try {
            $bills = $client->getBillsByAccount($fund->conta_azul_account_id, $from, $to);
        } catch (\Throwable $e) {
            Log::error('SyncContaAzulExpensesJob: falha ao buscar despesas', [
                'fund_id' => $fund->id,
                'emission_id' => $fund->emission_id,
                'conta_azul_account_id' => $fund->conta_azul_account_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($bills as $bill) {
            $this->upsertExpense($fund, $bill);
        }
    }

    /** @param array<string, mixed> $bill */
    private function upsertExpense(Fund $fund, array $bill): void
    {
        $category = $this->resolveCategory($bill['categorias'] ?? []);

        if ($category === null) {
            $rawNames = collect($bill['categorias'] ?? [])->pluck('nome')->implode(', ');
            Log::warning('SyncContaAzulExpensesJob: categoria sem mapeamento, despesa ignorada', [
                'conta_azul_bill_id' => $bill['id'],
                'fund_id' => $fund->id,
                'categorias' => $rawNames ?: '(sem categoria)',
            ]);

            return;
        }

        if (ExpenseHistory::query()->where('conta_azul_bill_id', $bill['id'])->exists()) {
            return;
        }

        $expense = Expense::query()
            ->where('emission_id', $fund->emission_id)
            ->where('category', $category)
            ->first();

        if ($expense instanceof Expense) {
            $expense->update([
                'amount' => $bill['total'],
            ]);
        } else {
            $expense = Expense::query()->create([
                'emission_id' => $fund->emission_id,
                'category' => $category,
                'amount' => $bill['total'],
                'period' => Expense::PERIOD_SINGLE,
                'start_date' => $bill['data_vencimento'],
                'end_date' => null,
                'expense_service_provider_id' => null,
            ]);
        }

        $expense->histories()->create([
            'amount' => $bill['total'],
            'due_date' => $bill['data_vencimento'],
            'conta_azul_bill_id' => $bill['id'],
        ]);
    }

    /** @param array<int, array<string, mixed>> $categorias */
    private function resolveCategory(array $categorias): ?string
    {
        $categoryMap = config('conta-azul.category_map', []);

        foreach ($categorias as $categoria) {
            $nome = trim($categoria['nome'] ?? '');

            if (isset($categoryMap[$nome])) {
                return $categoryMap[$nome];
            }
        }

        return null;
    }
}

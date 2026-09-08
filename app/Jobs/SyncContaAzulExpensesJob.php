<?php

namespace App\Jobs;

use App\Enums\ExpensePaymentStatus;
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
            $this->upsertExpense($fund, $bill, $client);
        }
    }

    /** @param array<string, mixed> $bill */
    public function upsertExpense(Fund $fund, array $bill, ?ContaAzulClient $client = null): void
    {
        $client ??= app(ContaAzulClient::class);

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

        $classification = $this->classifyBill($bill);
        $status = $classification['status'];
        $paidAmount = $classification['paid_amount'];
        $nominalAmount = (float) ($bill['total'] ?? 0.0);
        $dueDate = (string) ($bill['data_vencimento'] ?? now()->toDateString());

        $existingHistory = ExpenseHistory::query()->where('conta_azul_bill_id', $bill['id'])->first();

        // 1. Tentar resolver a data de pagamento diretamente pelo payload da listagem
        $paymentDate = ($classification['is_paid'] || $classification['is_partially_paid'])
            ? $this->resolveBillPaymentDate($bill)
            : null;

        // 2. Se for conta paga ou parcial e o endpoint de listagem não incluir a data:
        if ($paymentDate === null && ($classification['is_paid'] || $classification['is_partially_paid'])) {
            // Se o histórico já possui payment_date gravada e o status/valor pago não mudou, reutiliza para evitar chamadas HTTP extras
            $paymentUnchanged = $existingHistory?->payment_date !== null
                && $existingHistory->status === $status
                && (float) $existingHistory->paid_amount === (float) $paidAmount;

            if ($paymentUnchanged) {
                $paymentDate = $existingHistory->payment_date->toDateString();
            } elseif ($client instanceof ContaAzulClient) {
                // Se ainda não temos payment_date ou a liquidação mudou, consulta o endpoint oficial de parcelas (/parcelas/{id})
                $installment = $client->getInstallment($bill['id']);
                if (is_array($installment)) {
                    $paymentDate = $this->extractPaymentDateFromInstallment($installment);
                }
            }
        }

        if ($existingHistory instanceof ExpenseHistory) {
            $existingHistory->update([
                'amount' => $nominalAmount,
                'paid_amount' => $paidAmount,
                'due_date' => $dueDate,
                'payment_date' => $paymentDate,
                'status' => $status,
            ]);

            return;
        }

        $expense = Expense::query()
            ->where('emission_id', $fund->emission_id)
            ->where('category', $category)
            ->first();

        if ($expense instanceof Expense) {
            $expense->update([
                'amount' => $nominalAmount,
            ]);
        } else {
            $expense = Expense::query()->create([
                'emission_id' => $fund->emission_id,
                'category' => $category,
                'amount' => $nominalAmount,
                'period' => Expense::PERIOD_SINGLE,
                'start_date' => $dueDate,
                'end_date' => null,
                'expense_service_provider_id' => null,
            ]);
        }

        $expense->histories()->create([
            'amount' => $nominalAmount,
            'paid_amount' => $paidAmount,
            'due_date' => $dueDate,
            'payment_date' => $paymentDate,
            'status' => $status,
            'conta_azul_bill_id' => $bill['id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $bill
     * @return array{status: string, paid_amount: ?float, is_paid: bool, is_partially_paid: bool}
     */
    public function classifyBill(array $bill): array
    {
        $status = strtoupper(trim((string) ($bill['status'] ?? '')));
        $statusTraduzido = strtoupper(trim((string) ($bill['status_traduzido'] ?? '')));
        $pago = isset($bill['pago']) ? (float) $bill['pago'] : 0.0;
        $total = isset($bill['total']) ? (float) $bill['total'] : 0.0;
        $naoPago = isset($bill['nao_pago']) ? (float) $bill['nao_pago'] : null;
        $dueDate = (string) ($bill['data_vencimento'] ?? now()->toDateString());

        // 1. Quitação integral comprovada
        $isQuitadoStatus = in_array($status, ['PAID', 'ACQUITTED', 'SETTLED', 'QUITADO', 'RECEBIDO'], true)
            || in_array($statusTraduzido, ['QUITADO', 'RECEBIDO', 'PAGO'], true);

        $isFullyPaid = false;
        if ($isQuitadoStatus && ($naoPago === null || $naoPago <= 0.0)) {
            $isFullyPaid = true;
        } elseif ($total > 0 && $pago >= $total && ($naoPago === null || $naoPago <= 0.0)) {
            $isFullyPaid = true;
        }

        if ($isFullyPaid) {
            return [
                'status' => ExpensePaymentStatus::Paid->value,
                'paid_amount' => $pago > 0 ? $pago : $total,
                'is_paid' => true,
                'is_partially_paid' => false,
            ];
        }

        // 2. Pagamento parcial comprovado
        $isPartialStatus = in_array($status, ['PARTIALLY_PAID', 'RECEBIDO_PARCIAL'], true)
            || in_array($statusTraduzido, ['RECEBIDO_PARCIAL', 'PARCIALMENTE_PAGO'], true);

        $isPartiallyPaid = false;
        if ($isPartialStatus) {
            $isPartiallyPaid = true;
        } elseif ($pago > 0 && ($naoPago === null || $naoPago > 0) && $pago < $total) {
            $isPartiallyPaid = true;
        }

        if ($isPartiallyPaid) {
            return [
                'status' => ExpensePaymentStatus::PartiallyPaid->value,
                'paid_amount' => $pago,
                'is_paid' => false,
                'is_partially_paid' => true,
            ];
        }

        // 3. Em aberto / não pago
        $statusValue = $dueDate < now()->toDateString()
            ? ExpensePaymentStatus::Overdue->value
            : ExpensePaymentStatus::Pending->value;

        return [
            'status' => $statusValue,
            'paid_amount' => null,
            'is_paid' => false,
            'is_partially_paid' => false,
        ];
    }

    /** @param array<string, mixed> $bill */
    public function isBillPaid(array $bill): bool
    {
        return $this->classifyBill($bill)['is_paid'];
    }

    /** @param array<string, mixed> $bill */
    public function resolveBillPaymentDate(array $bill): ?string
    {
        $date = $bill['data_pagamento'] ?? $bill['payment_date'] ?? $bill['data_baixa'] ?? null;

        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1) {
            return substr($date, 0, 10);
        }

        if (isset($bill['baixas']) && is_array($bill['baixas'])) {
            return $this->extractPaymentDateFromInstallment($bill);
        }

        return null;
    }

    /** @param array<string, mixed> $installment */
    public function extractPaymentDateFromInstallment(array $installment): ?string
    {
        $baixas = $installment['baixas'] ?? [];
        if (! is_array($baixas) || empty($baixas)) {
            return null;
        }

        $validDates = collect($baixas)
            ->map(fn ($baixa) => is_array($baixa) ? ($baixa['data_pagamento'] ?? $baixa['data_baixa'] ?? $baixa['payment_date'] ?? null) : null)
            ->filter(fn ($date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1)
            ->map(fn (string $date) => substr($date, 0, 10))
            ->sortDesc();

        return $validDates->first();
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

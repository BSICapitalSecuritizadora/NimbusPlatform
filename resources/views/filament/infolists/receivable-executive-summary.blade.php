@php
    use App\Concerns\MoneyFormatter;

    /** @var \App\Models\Receivable $record */
    $record = $getRecord();

    $outstandingBalance = (float) ($record->total_outstanding_balance_amount ?? 0);
    $expectedTotal = (float) ($record->expected_interest_amount ?? 0) + (float) ($record->expected_amortization_amount ?? 0);
    $receivedTotal = (float) ($record->received_installment_interest_amount ?? 0)
        + (float) ($record->received_installment_amortization_amount ?? 0)
        + (float) ($record->received_prepayment_interest_amount ?? 0)
        + (float) ($record->received_prepayment_amortization_amount ?? 0)
        + (float) ($record->received_default_interest_amount ?? 0)
        + (float) ($record->received_default_amortization_amount ?? 0)
        + (float) ($record->received_interest_and_penalty_amount ?? 0);

    $defaultTotal = (float) ($record->total_default_balance_amount ?? 0);
    $defaultMonth = (float) ($record->monthly_default_balance_amount ?? 0);

    $ltvRatio = $record->portfolio_ltv_ratio !== null ? ((float) $record->portfolio_ltv_ratio * 100) : null;
    $saleLtvRatio = $record->sale_ltv_ratio !== null ? ((float) $record->sale_ltv_ratio * 100) : null;

    $activeContracts = (int) ($record->active_contracts_count ?? 0);
    $performingBalance = (float) ($record->performing_balance_post_event_amount ?? 0);
@endphp

<div class="rounded-xl border border-white/10 bg-[#091b23] text-white shadow-lg overflow-hidden">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-px bg-white/10">
        {{-- KPI 1: Saldo Devedor Total --}}
        <div class="flex flex-col justify-between bg-[#091b23] p-4 sm:p-5">
            <div>
                <div class="flex items-center gap-2 min-h-5">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Saldo Devedor Total
                    </span>
                </div>
                <span class="mt-1.5 block font-mono text-xl sm:text-2xl font-bold tracking-tight text-[#fbfaf8] tabular-nums">
                    R$ {{ MoneyFormatter::formatCurrencyForDisplay($outstandingBalance) }}
                </span>
            </div>
            <div class="mt-2 text-[11px] text-slate-400">
                Posição consolidada da carteira
            </div>
        </div>

        {{-- KPI 2: Total Recebido no Mês --}}
        <div class="flex flex-col justify-between bg-[#091b23] p-4 sm:p-5">
            <div>
                <div class="flex items-center gap-2 min-h-5">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Total Recebido
                    </span>
                    @if ($expectedTotal > 0 && $receivedTotal >= $expectedTotal)
                        <span class="inline-flex items-center text-[10px] font-medium text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded shrink-0">
                            Adimplente
                        </span>
                    @endif
                </div>
                <span class="mt-1.5 block font-mono text-xl sm:text-2xl font-bold tracking-tight text-emerald-400 tabular-nums">
                    R$ {{ MoneyFormatter::formatCurrencyForDisplay($receivedTotal) }}
                </span>
            </div>
            <div class="mt-2 text-[11px] text-slate-400 font-mono tabular-nums">
                Previsto: R$ {{ MoneyFormatter::formatCurrencyForDisplay($expectedTotal) }}
            </div>
        </div>

        {{-- KPI 3: Inadimplência Geral --}}
        <div class="flex flex-col justify-between bg-[#091b23] p-4 sm:p-5">
            <div>
                <div class="flex items-center gap-2 min-h-5">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Inadimplência Geral
                    </span>
                    @if ($defaultTotal > 0)
                        <span class="inline-flex items-center text-[10px] font-medium text-amber-400 bg-amber-500/10 px-1.5 py-0.5 rounded shrink-0">
                            Atenção
                        </span>
                    @endif
                </div>
                <span class="mt-1.5 block font-mono text-xl sm:text-2xl font-bold tracking-tight text-[#f2ddb0] tabular-nums">
                    R$ {{ MoneyFormatter::formatCurrencyForDisplay($defaultTotal) }}
                </span>
            </div>
            <div class="mt-2 text-[11px] text-slate-400 font-mono tabular-nums">
                No mês: R$ {{ MoneyFormatter::formatCurrencyForDisplay($defaultMonth) }}
            </div>
        </div>

        {{-- KPI 4: LTV da Carteira --}}
        <div class="flex flex-col justify-between bg-[#091b23] p-4 sm:p-5">
            <div>
                <div class="flex items-center gap-2 min-h-5">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        LTV Carteira
                    </span>
                </div>
                <span class="mt-1.5 block font-mono text-xl sm:text-2xl font-bold tracking-tight text-[#fbfaf8] tabular-nums">
                    {{ $ltvRatio !== null ? number_format($ltvRatio, 2, ',', '.') . '%' : '—' }}
                </span>
            </div>
            <div class="mt-2 text-[11px] text-slate-400 font-mono tabular-nums">
                {{ $saleLtvRatio !== null ? 'LTV Venda: ' . number_format($saleLtvRatio, 2, ',', '.') . '%' : 'Garantia primária' }}
            </div>
        </div>

        {{-- KPI 5: Contratos Ativos --}}
        <div class="flex flex-col justify-between bg-[#091b23] p-4 sm:p-5">
            <div>
                <div class="flex items-center gap-2 min-h-5">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Contratos Ativos
                    </span>
                </div>
                <span class="mt-1.5 block font-mono text-xl sm:text-2xl font-bold tracking-tight text-[#fbfaf8] tabular-nums">
                    {{ number_format($activeContracts, 0, ',', '.') }}
                </span>
            </div>
            <div class="mt-2 text-[11px] text-slate-400">
                Mutuários na carteira
            </div>
        </div>

        {{-- KPI 6: Carteira Adimplente --}}
        <div class="flex flex-col justify-between bg-[#091b23] p-4 sm:p-5">
            <div>
                <div class="flex items-center gap-2 min-h-5">
                    <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Adimplente Pós-Evento
                    </span>
                </div>
                <span class="mt-1.5 block font-mono text-xl sm:text-2xl font-bold tracking-tight text-[#fbfaf8] tabular-nums">
                    R$ {{ MoneyFormatter::formatCurrencyForDisplay($performingBalance) }}
                </span>
            </div>
            <div class="mt-2 text-[11px] text-slate-400">
                Fluxo adimplente regular
            </div>
        </div>
    </div>
</div>

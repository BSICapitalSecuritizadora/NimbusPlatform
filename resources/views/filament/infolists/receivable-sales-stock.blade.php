@php
    use App\Concerns\MoneyFormatter;

    /** @var \App\Models\Receivable $record */
    $record = $getRecord();

    /** @var \App\Models\SalesBoard|null $salesBoard */
    $salesBoard = $record->emission?->salesBoards()
        ->whereDate('reference_month', $record->reference_month)
        ->first();

    if (! $salesBoard) {
        return;
    }

    $totalUnits = (int) ($salesBoard->total_units ?? 0);
    $stockUnits = (int) ($salesBoard->stock_units ?? 0);
    $financedUnits = (int) ($salesBoard->financed_units ?? 0);
    $paidUnits = (int) ($salesBoard->paid_units ?? 0);
    $exchangedUnits = (int) ($salesBoard->exchanged_units ?? 0);
    $soldUnits = $financedUnits + $paidUnits;

    $stockVal = (float) ($salesBoard->stock_value ?? 0);
    $financedVal = (float) ($salesBoard->financed_value ?? 0);
    $paidVal = (float) ($salesBoard->paid_value ?? 0);
    $exchangedVal = (float) ($salesBoard->exchanged_value ?? 0);
    $totalVal = $stockVal + $financedVal + $paidVal + $exchangedVal;

    $pctSold = $totalUnits > 0 ? ($soldUnits / $totalUnits) * 100 : 0;
    $pctStock = $totalUnits > 0 ? ($stockUnits / $totalUnits) * 100 : 0;
@endphp

<div class="bsi-financial-table-wrapper overflow-x-auto bg-white dark:bg-[#091b23]">
    <table class="bsi-financial-table min-w-full">
        <thead>
            <tr>
                <th class="text-left">Status da Unidade</th>
                <th class="text-right">Unidades</th>
                <th class="text-right">% Unidades</th>
                <th class="text-right">Valor Financeiro (R$)</th>
                <th class="text-right">% VGV</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200/70 dark:divide-white/5">
            <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                <td class="font-medium text-slate-800 dark:text-slate-200">
                    <div class="flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                        <span>Financiadas (Carteira Ativa)</span>
                    </div>
                </td>
                <td class="bsi-num font-mono text-slate-900 dark:text-white font-medium">
                    {{ number_format($financedUnits, 0, ',', '.') }}
                </td>
                <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                    {{ $totalUnits > 0 ? number_format(($financedUnits / $totalUnits) * 100, 1, ',', '.') . '%' : '—' }}
                </td>
                <td class="bsi-num font-mono text-slate-900 dark:text-white font-medium">
                    {{ MoneyFormatter::formatCurrencyForDisplay($financedVal) }}
                </td>
                <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                    {{ $totalVal > 0 ? number_format(($financedVal / $totalVal) * 100, 1, ',', '.') . '%' : '—' }}
                </td>
            </tr>

            <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                <td class="font-medium text-slate-800 dark:text-slate-200">
                    <div class="flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-blue-500"></span>
                        <span>Quitadas</span>
                    </div>
                </td>
                <td class="bsi-num font-mono text-slate-900 dark:text-white font-medium">
                    {{ number_format($paidUnits, 0, ',', '.') }}
                </td>
                <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                    {{ $totalUnits > 0 ? number_format(($paidUnits / $totalUnits) * 100, 1, ',', '.') . '%' : '—' }}
                </td>
                <td class="bsi-num font-mono text-slate-900 dark:text-white font-medium">
                    {{ MoneyFormatter::formatCurrencyForDisplay($paidVal) }}
                </td>
                <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                    {{ $totalVal > 0 ? number_format(($paidVal / $totalVal) * 100, 1, ',', '.') . '%' : '—' }}
                </td>
            </tr>

            <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                <td class="font-medium text-slate-800 dark:text-slate-200">
                    <div class="flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-amber-500"></span>
                        <span>Estoque Disponível</span>
                    </div>
                </td>
                <td class="bsi-num font-mono text-slate-900 dark:text-white font-medium">
                    {{ number_format($stockUnits, 0, ',', '.') }}
                </td>
                <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                    {{ $totalUnits > 0 ? number_format(($stockUnits / $totalUnits) * 100, 1, ',', '.') . '%' : '—' }}
                </td>
                <td class="bsi-num font-mono text-slate-900 dark:text-white font-medium">
                    {{ MoneyFormatter::formatCurrencyForDisplay($stockVal) }}
                </td>
                <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                    {{ $totalVal > 0 ? number_format(($stockVal / $totalVal) * 100, 1, ',', '.') . '%' : '—' }}
                </td>
            </tr>

            @if ($exchangedUnits > 0 || $exchangedVal > 0)
                <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                    <td class="font-medium text-slate-800 dark:text-slate-200">
                        <div class="flex items-center gap-1.5">
                            <span class="inline-block w-2 h-2 rounded-full bg-purple-500"></span>
                            <span>Permutadas</span>
                        </div>
                    </td>
                    <td class="bsi-num font-mono text-slate-900 dark:text-white">
                        {{ number_format($exchangedUnits, 0, ',', '.') }}
                    </td>
                    <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                        {{ $totalUnits > 0 ? number_format(($exchangedUnits / $totalUnits) * 100, 1, ',', '.') . '%' : '—' }}
                    </td>
                    <td class="bsi-num font-mono text-slate-900 dark:text-white">
                        {{ MoneyFormatter::formatCurrencyForDisplay($exchangedVal) }}
                    </td>
                    <td class="bsi-num font-mono text-slate-600 dark:text-slate-400">
                        {{ $totalVal > 0 ? number_format(($exchangedVal / $totalVal) * 100, 1, ',', '.') . '%' : '—' }}
                    </td>
                </tr>
            @endif

            <tr class="bsi-total-row bg-[#091b23]/5 dark:bg-white/[0.04]">
                <td class="font-bold text-slate-900 dark:text-[#fbfaf8]">
                    TOTAL DO EMPREENDIMENTO (VGV)
                </td>
                <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                    {{ number_format($totalUnits, 0, ',', '.') }}
                </td>
                <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                    100,0%
                </td>
                <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                    R$ {{ MoneyFormatter::formatCurrencyForDisplay($totalVal) }}
                </td>
                <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                    100,0%
                </td>
            </tr>
        </tbody>
    </table>
</div>

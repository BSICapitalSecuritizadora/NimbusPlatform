@php
    use App\Concerns\MoneyFormatter;

    /** @var \App\Models\Receivable $record */
    $record = $getRecord();

    // 1. Esperado
    $expInterest = (float) ($record->expected_interest_amount ?? 0);
    $expAmort = (float) ($record->expected_amortization_amount ?? 0);
    $expTotal = $expInterest + $expAmort;

    // 2. Recebido de Parcelas
    $recInstInterest = (float) ($record->received_installment_interest_amount ?? 0);
    $recInstAmort = (float) ($record->received_installment_amortization_amount ?? 0);
    $recInstTotal = $recInstInterest + $recInstAmort;

    // 3. Antecipação
    $recPrepInterest = (float) ($record->received_prepayment_interest_amount ?? 0);
    $recPrepAmort = (float) ($record->received_prepayment_amortization_amount ?? 0);
    $recPrepTotal = $recPrepInterest + $recPrepAmort;

    // 4. Inadimplência
    $recDefInterest = (float) ($record->received_default_interest_amount ?? 0);
    $recDefAmort = (float) ($record->received_default_amortization_amount ?? 0);
    $recDefTotal = $recDefInterest + $recDefAmort;

    // 5. Juros e Mora
    $penalty = (float) ($record->received_interest_and_penalty_amount ?? 0);

    // 6. Totais Realizados
    $totalRecInterest = $recInstInterest + $recPrepInterest + $recDefInterest;
    $totalRecAmort = $recInstAmort + $recPrepAmort + $recDefAmort;
    $totalRealized = $recInstTotal + $recPrepTotal + $recDefTotal + $penalty;

    $realizationRatio = $expTotal > 0 ? ($totalRealized / $expTotal) * 100 : null;
@endphp

<div class="bsi-financial-table-wrapper overflow-x-auto bg-white dark:bg-[#091b23]">
    <table class="bsi-financial-table min-w-full">
        <thead>
            <tr>
                <th class="text-left">Natureza do Fluxo</th>
                <th class="text-right">Juros (R$)</th>
                <th class="text-right">Amortização (R$)</th>
                <th class="text-right">Total (R$)</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200/70 dark:divide-white/5">
            {{-- Linha 1: Esperado no Mês --}}
            <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                <td class="font-medium text-slate-700 dark:text-slate-300">
                    <div class="flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-slate-400"></span>
                        <span>Esperado no mês</span>
                    </div>
                </td>
                <td class="bsi-num font-mono text-slate-700 dark:text-slate-300">
                    {{ MoneyFormatter::formatCurrencyForDisplay($expInterest) }}
                </td>
                <td class="bsi-num font-mono text-slate-700 dark:text-slate-300">
                    {{ MoneyFormatter::formatCurrencyForDisplay($expAmort) }}
                </td>
                <td class="bsi-num font-mono font-semibold text-slate-900 dark:text-white">
                    {{ MoneyFormatter::formatCurrencyForDisplay($expTotal) }}
                </td>
            </tr>

            {{-- Linha 2: Recebido de Parcelas --}}
            <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                <td class="font-medium text-slate-700 dark:text-slate-300 pl-6">
                    Recebido de parcelas do mês
                </td>
                <td class="bsi-num font-mono {{ $recInstInterest == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-300' }}">
                    {{ MoneyFormatter::formatCurrencyForDisplay($recInstInterest) }}
                </td>
                <td class="bsi-num font-mono {{ $recInstAmort == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-300' }}">
                    {{ MoneyFormatter::formatCurrencyForDisplay($recInstAmort) }}
                </td>
                <td class="bsi-num font-mono font-medium text-slate-900 dark:text-white">
                    {{ MoneyFormatter::formatCurrencyForDisplay($recInstTotal) }}
                </td>
            </tr>

            {{-- Linha 3: Antecipações --}}
            <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                <td class="font-medium text-slate-700 dark:text-slate-300 pl-6">
                    Antecipação e quitações
                </td>
                <td class="bsi-num font-mono {{ $recPrepInterest == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-300' }}">
                    {{ MoneyFormatter::formatCurrencyForDisplay($recPrepInterest) }}
                </td>
                <td class="bsi-num font-mono {{ $recPrepAmort == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-300' }}">
                    {{ MoneyFormatter::formatCurrencyForDisplay($recPrepAmort) }}
                </td>
                <td class="bsi-num font-mono font-medium text-slate-900 dark:text-white">
                    {{ MoneyFormatter::formatCurrencyForDisplay($recPrepTotal) }}
                </td>
            </tr>

            {{-- Linha 4: Inadimplência Recuperada --}}
            <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                <td class="font-medium text-slate-700 dark:text-slate-300 pl-6">
                    Recuperação de inadimplência
                </td>
                <td class="bsi-num font-mono {{ $recDefInterest == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-300' }}">
                    {{ MoneyFormatter::formatCurrencyForDisplay($recDefInterest) }}
                </td>
                <td class="bsi-num font-mono {{ $recDefAmort == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-300' }}">
                    {{ MoneyFormatter::formatCurrencyForDisplay($recDefAmort) }}
                </td>
                <td class="bsi-num font-mono font-medium text-slate-900 dark:text-white">
                    {{ MoneyFormatter::formatCurrencyForDisplay($recDefTotal) }}
                </td>
            </tr>

            {{-- Linha 5: Juros e Mora --}}
            <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                <td class="font-medium text-slate-700 dark:text-slate-300 pl-6">
                    Juros de mora e encargos contratuais
                </td>
                <td class="bsi-num font-mono text-slate-400 dark:text-slate-500">
                    —
                </td>
                <td class="bsi-num font-mono text-slate-400 dark:text-slate-500">
                    —
                </td>
                <td class="bsi-num font-mono font-medium {{ $penalty == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-900 dark:text-white' }}">
                    {{ MoneyFormatter::formatCurrencyForDisplay($penalty) }}
                </td>
            </tr>

            {{-- Linha 6: TOTAL REALIZADO NO MÊS --}}
            <tr class="bsi-total-row bg-[#091b23]/5 dark:bg-white/[0.04]">
                <td class="font-bold text-slate-900 dark:text-[#fbfaf8]">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5">
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            <span>TOTAL REALIZADO NO MÊS</span>
                        </div>
                        @if ($realizationRatio !== null)
                            <span class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $realizationRatio >= 100 ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400' }}">
                                {{ number_format($realizationRatio, 1, ',', '.') }}% do previsto
                            </span>
                        @endif
                    </div>
                </td>
                <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                    {{ MoneyFormatter::formatCurrencyForDisplay($totalRecInterest) }}
                </td>
                <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                    {{ MoneyFormatter::formatCurrencyForDisplay($totalRecAmort) }}
                </td>
                <td class="bsi-num font-mono font-bold text-emerald-600 dark:text-emerald-400 text-[0.9375rem]">
                    R$ {{ MoneyFormatter::formatCurrencyForDisplay($totalRealized) }}
                </td>
            </tr>
        </tbody>
    </table>
</div>

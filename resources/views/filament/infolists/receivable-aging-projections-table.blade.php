@php
    use App\Concerns\MoneyFormatter;
    use Carbon\Carbon;

    /** @var \App\Models\Receivable $record */
    $record = $getRecord();

    $refDate = null;
    if ($record->reference_month) {
        try {
            $refDate = $record->reference_month instanceof \Carbon\CarbonInterface
                ? $record->reference_month->copy()
                : Carbon::parse($record->reference_month);
        } catch (\Throwable) {
            $refDate = null;
        }
    }

    $horizons = [
        [
            'label' => 'Até 30 dias',
            'competence' => $refDate ? $refDate->copy()->addMonths(1)->format('m/Y') : 'M+1',
            'linked' => (float) ($record->linked_credits_up_to_30_days_amount ?? 0),
            'overdue' => (float) ($record->overdue_up_to_30_days_amount ?? 0),
            'prepaid' => (float) ($record->prepaid_up_to_30_days_amount ?? 0),
        ],
        [
            'label' => '31 a 60 dias',
            'competence' => $refDate ? $refDate->copy()->addMonths(2)->format('m/Y') : 'M+2',
            'linked' => (float) ($record->linked_credits_31_to_60_days_amount ?? 0),
            'overdue' => (float) ($record->overdue_31_to_60_days_amount ?? 0),
            'prepaid' => (float) ($record->prepaid_31_to_60_days_amount ?? 0),
        ],
        [
            'label' => '61 a 90 dias',
            'competence' => $refDate ? $refDate->copy()->addMonths(3)->format('m/Y') : 'M+3',
            'linked' => (float) ($record->linked_credits_61_to_90_days_amount ?? 0),
            'overdue' => (float) ($record->overdue_61_to_90_days_amount ?? 0),
            'prepaid' => (float) ($record->prepaid_61_to_90_days_amount ?? 0),
        ],
        [
            'label' => '91 a 120 dias',
            'competence' => $refDate ? $refDate->copy()->addMonths(4)->format('m/Y') : 'M+4',
            'linked' => (float) ($record->linked_credits_91_to_120_days_amount ?? 0),
            'overdue' => (float) ($record->overdue_91_to_120_days_amount ?? 0),
            'prepaid' => (float) ($record->prepaid_91_to_120_days_amount ?? 0),
        ],
        [
            'label' => '121 a 150 dias',
            'competence' => $refDate ? $refDate->copy()->addMonths(5)->format('m/Y') : 'M+5',
            'linked' => (float) ($record->linked_credits_121_to_150_days_amount ?? 0),
            'overdue' => (float) ($record->overdue_121_to_150_days_amount ?? 0),
            'prepaid' => (float) ($record->prepaid_121_to_150_days_amount ?? 0),
        ],
        [
            'label' => '151 a 180 dias',
            'competence' => $refDate ? $refDate->copy()->addMonths(6)->format('m/Y') : 'M+6',
            'linked' => (float) ($record->linked_credits_151_to_180_days_amount ?? 0),
            'overdue' => (float) ($record->overdue_151_to_180_days_amount ?? 0),
            'prepaid' => (float) ($record->prepaid_151_to_180_days_amount ?? 0),
        ],
        [
            'label' => '181 a 360 dias',
            'competence' => $refDate ? ($refDate->copy()->addMonths(7)->format('m/Y') . ' a ' . $refDate->copy()->addMonths(12)->format('m/Y')) : 'M+7 a M+12',
            'linked' => (float) ($record->linked_credits_181_to_360_days_amount ?? 0),
            'overdue' => (float) ($record->overdue_181_to_360_days_amount ?? 0),
            'prepaid' => (float) ($record->prepaid_181_to_360_days_amount ?? 0),
        ],
        [
            'label' => 'Acima de 360 dias',
            'competence' => $refDate ? ('> ' . $refDate->copy()->addMonths(12)->format('m/Y')) : '> M+12',
            'linked' => (float) ($record->linked_credits_over_360_days_amount ?? 0),
            'overdue' => (float) ($record->overdue_over_360_days_amount ?? 0),
            'prepaid' => (float) ($record->prepaid_over_360_days_amount ?? 0),
        ],
    ];

    $totalLinked = array_sum(array_column($horizons, 'linked'));
    $totalOverdue = array_sum(array_column($horizons, 'overdue'));
    $totalPrepaid = array_sum(array_column($horizons, 'prepaid'));
@endphp

<div class="bsi-financial-table-wrapper overflow-x-auto bg-white dark:bg-[#091b23]">
    <table class="bsi-financial-table min-w-full">
        <thead>
            <tr>
                <th class="text-left">Faixa Cronológica</th>
                <th class="text-center">Competência Estimada</th>
                <th class="text-right">Créditos Vinculados (Fluxo Futuro)</th>
                <th class="text-right">Vencidos e Não Pagos (Inadimplência)</th>
                <th class="text-right">Pagos Antecipadamente</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200/70 dark:divide-white/5">
            @foreach ($horizons as $row)
                <tr class="hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                    <td class="font-medium text-slate-800 dark:text-slate-200">
                        {{ $row['label'] }}
                    </td>
                    <td class="text-center font-mono text-xs text-slate-500 dark:text-slate-400 tabular-nums">
                        {{ $row['competence'] }}
                    </td>
                    <td class="bsi-num font-mono {{ $row['linked'] == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-900 dark:text-white font-medium' }}">
                        {{ MoneyFormatter::formatCurrencyForDisplay($row['linked']) }}
                    </td>
                    <td class="bsi-num font-mono {{ $row['overdue'] == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-amber-600 dark:text-amber-400 font-medium' }}">
                        {{ MoneyFormatter::formatCurrencyForDisplay($row['overdue']) }}
                    </td>
                    <td class="bsi-num font-mono {{ $row['prepaid'] == 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-300' }}">
                        {{ MoneyFormatter::formatCurrencyForDisplay($row['prepaid']) }}
                    </td>
                </tr>
            @endforeach

            {{-- Linha de Totais da Projeção --}}
            <tr class="bsi-total-row bg-[#091b23]/5 dark:bg-white/[0.04]">
                <td colspan="2" class="font-bold text-slate-900 dark:text-[#fbfaf8]">
                    TOTAL DAS FAIXAS DE VENCIMENTO
                </td>
                <td class="bsi-num font-mono font-bold text-slate-900 dark:text-white">
                    R$ {{ MoneyFormatter::formatCurrencyForDisplay($totalLinked) }}
                </td>
                <td class="bsi-num font-mono font-bold text-amber-600 dark:text-amber-400">
                    R$ {{ MoneyFormatter::formatCurrencyForDisplay($totalOverdue) }}
                </td>
                <td class="bsi-num font-mono font-bold text-slate-900 dark:text-[#fbfaf8]">
                    R$ {{ MoneyFormatter::formatCurrencyForDisplay($totalPrepaid) }}
                </td>
            </tr>
        </tbody>
    </table>
</div>

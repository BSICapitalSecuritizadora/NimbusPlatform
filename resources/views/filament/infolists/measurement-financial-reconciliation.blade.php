@php
    use App\Services\MeasurementFinancialReconciliationService;
    use App\Enums\MeasurementReconciliationStatus;

    $reconciliation = app(MeasurementFinancialReconciliationService::class)->forMeasurement($getRecord());

    $statusStyles = [
        'success' => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
        'warning' => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
        'gray' => 'text-gray-600 bg-gray-50 border-gray-200 dark:text-gray-400 dark:bg-white/5 dark:border-white/10',
    ];

    $expected = (float) $reconciliation->expectedAmount;
    $registered = (float) $reconciliation->registeredAmount;
    $balance = (float) $reconciliation->expectedBalance;
    $financialPercent = $expected > 0 ? min(100, max(0, round(($registered / $expected) * 100, 1))) : 0;
@endphp

<div class="bsi-financial-condition space-y-4">
    @if ($reconciliation->isEmpty())
        <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50/50 p-3 text-xs text-gray-500 dark:border-white/10 dark:bg-white/[0.02] dark:text-gray-400">
            <x-heroicon-o-information-circle class="size-4 shrink-0 text-gray-400" />
            <span>A referência financeira fica disponível após a aprovação da Engenharia.</span>
        </div>
    @else
        {{-- KPIs Financeiros Executivos --}}
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
            <div class="rounded-lg border border-gray-200/80 bg-white p-3 shadow-xs dark:border-white/10 dark:bg-[#071820]/60">
                <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Valor Esperado
                </span>
                <span class="mt-1 block font-mono text-base font-bold tabular-nums text-gray-900 dark:text-white">
                    {{ MeasurementFinancialReconciliationService::formatCurrency($expected) }}
                </span>
            </div>

            <div class="rounded-lg border border-gray-200/80 bg-white p-3 shadow-xs dark:border-white/10 dark:bg-[#071820]/60">
                <div class="flex items-center justify-between">
                    <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Registrado
                    </span>
                    <span class="text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                        {{ number_format($financialPercent, 0, ',', '.') }}%
                    </span>
                </div>
                <span class="mt-1 block font-mono text-base font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                    {{ MeasurementFinancialReconciliationService::formatCurrency($registered) }}
                </span>
            </div>

            <div class="col-span-2 rounded-lg border border-gray-200/80 bg-white p-3 shadow-xs sm:col-span-1 dark:border-white/10 dark:bg-[#071820]/60">
                <div class="flex items-center justify-between">
                    <span class="block text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Saldo Esperado
                    </span>
                    <span class="inline-flex items-center rounded-full border px-1.5 py-0.2 text-[10px] font-medium {{ $statusStyles[$reconciliation->status->color()] ?? $statusStyles['gray'] }}">
                        {{ $reconciliation->status->label() }}
                    </span>
                </div>
                <span class="mt-1 block font-mono text-base font-bold tabular-nums text-gray-900 dark:text-white">
                    {{ MeasurementFinancialReconciliationService::formatCurrency($balance) }}
                </span>
            </div>
        </div>

        {{-- Barra de Progresso Financeiro + Tags de Avanço Físico --}}
        <div class="rounded-lg border border-gray-200/80 bg-gray-50/50 p-3 dark:border-white/10 dark:bg-[#071820]/30">
            <div class="flex items-center justify-between text-xs">
                <span class="font-medium text-gray-600 dark:text-gray-300">
                    Execução financeira da competência
                </span>
                <span class="font-mono font-semibold tabular-nums text-[#A06E28] dark:text-bsi-gold-500">
                    {{ number_format($financialPercent, 1, ',', '.') }}%
                </span>
            </div>

            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                <div
                    class="h-full rounded-full bg-[#A06E28] transition-all duration-500 dark:bg-bsi-gold-500"
                    style="width: {{ $financialPercent }}%"
                ></div>
            </div>

            {{-- Tags de avanço físico aprovado pela Engenharia --}}
            <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                <span class="text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">
                    Avanço de obra:
                </span>
                @foreach ($reconciliation->lines as $line)
                    <span class="inline-flex items-center gap-1 rounded-md bg-white px-2 py-0.5 text-[11px] font-medium text-gray-700 shadow-2xs dark:bg-white/5 dark:text-gray-300">
                        <span>{{ $line->label }}:</span>
                        <strong class="font-mono text-gray-950 dark:text-white">
                            {{ MeasurementFinancialReconciliationService::formatPercent($line->realizedMonthlyPercent) }}
                        </strong>
                    </span>
                @endforeach
            </div>
        </div>

        {{-- Tabela Operacional de Conciliação por Empreendimento --}}
        <div class="overflow-x-auto rounded-lg border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#071820]/40">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-gray-200/80 bg-gray-50/75 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">
                        <th class="px-3 py-2">Empreendimento</th>
                        <th class="px-3 py-2 text-right">Realizado</th>
                        <th class="px-3 py-2 text-right">Esperado</th>
                        <th class="px-3 py-2 text-right">Registrado</th>
                        <th class="px-3 py-2 text-right">Saldo</th>
                        <th class="px-3 py-2 text-center">Situação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($reconciliation->lines as $line)
                        <tr class="transition-colors hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
                            <td class="px-3 py-2 font-medium text-gray-900 dark:text-white">
                                {{ $line->label }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-600 dark:text-gray-300">
                                {{ MeasurementFinancialReconciliationService::formatPercent($line->realizedMonthlyPercent) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-gray-900 dark:text-white">
                                {{ MeasurementFinancialReconciliationService::formatCurrency($line->expectedAmount) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-emerald-600 dark:text-emerald-400">
                                {{ MeasurementFinancialReconciliationService::formatCurrency($line->registeredAmount) }}
                            </td>
                            <td class="px-3 py-2 text-right font-mono tabular-nums text-gray-900 dark:text-white">
                                {{ MeasurementFinancialReconciliationService::formatCurrency($line->expectedBalance) }}
                            </td>
                            <td class="px-3 py-2 text-center">
                                <span class="inline-flex items-center rounded-full border px-1.5 py-0.2 text-[10px] font-medium {{ $statusStyles[$line->status->color()] ?? $statusStyles['gray'] }}">
                                    {{ $line->status->label() }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 bg-gray-50/50 font-semibold dark:border-white/10 dark:bg-white/[0.04]">
                        <td class="px-3 py-2 text-gray-950 dark:text-white">Total da Medição</td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2 text-right font-mono font-bold tabular-nums text-gray-950 dark:text-white">
                            {{ MeasurementFinancialReconciliationService::formatCurrency($reconciliation->expectedAmount) }}
                        </td>
                        <td class="px-3 py-2 text-right font-mono font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                            {{ MeasurementFinancialReconciliationService::formatCurrency($reconciliation->registeredAmount) }}
                        </td>
                        <td class="px-3 py-2 text-right font-mono font-bold tabular-nums text-gray-950 dark:text-white">
                            {{ MeasurementFinancialReconciliationService::formatCurrency($reconciliation->expectedBalance) }}
                        </td>
                        <td class="px-3 py-2 text-center">
                            <span class="inline-flex items-center rounded-full border px-1.5 py-0.2 text-[10px] font-medium {{ $statusStyles[$reconciliation->status->color()] ?? $statusStyles['gray'] }}">
                                {{ $reconciliation->status->label() }}
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p class="max-w-4xl text-[11px] leading-relaxed text-gray-400 dark:text-gray-500">
            * Valores esperados baseados no snapshot congelado da Engenharia. A divergência é estritamente informativa e não bloqueia o fluxo.
        </p>
    @endif
</div>

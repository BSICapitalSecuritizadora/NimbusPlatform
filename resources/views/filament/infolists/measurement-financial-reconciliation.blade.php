@php
    use App\Services\MeasurementFinancialReconciliationService;

    $reconciliation = app(MeasurementFinancialReconciliationService::class)->forMeasurement($getRecord());

    $statusColors = [
        'success' => 'text-green-600 dark:text-green-400',
        'warning' => 'text-amber-600 dark:text-amber-400',
        'gray' => 'text-gray-500 dark:text-gray-400',
    ];
@endphp

<div class="fi-in-financial-reconciliation">
    @if ($reconciliation->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            A referência financeira só existe depois da aprovação da Engenharia.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-4 font-medium">Empreendimento</th>
                        <th class="py-2 pr-4 text-right font-medium">Realizado</th>
                        <th class="py-2 pr-4 text-right font-medium">Valor esperado</th>
                        <th class="py-2 pr-4 text-right font-medium">Registrado</th>
                        <th class="py-2 pr-4 text-right font-medium">Saldo</th>
                        <th class="py-2 font-medium">Situação</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reconciliation->lines as $line)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $line->label }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                {{ MeasurementFinancialReconciliationService::formatPercent($line->realizedMonthlyPercent) }}
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums text-gray-950 dark:text-white">
                                {{ MeasurementFinancialReconciliationService::formatCurrency($line->expectedAmount) }}
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums text-gray-950 dark:text-white">
                                {{ MeasurementFinancialReconciliationService::formatCurrency($line->registeredAmount) }}
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums text-gray-950 dark:text-white">
                                {{ MeasurementFinancialReconciliationService::formatCurrency($line->expectedBalance) }}
                            </td>
                            <td class="py-2 {{ $statusColors[$line->status->color()] ?? $statusColors['gray'] }}">
                                {{ $line->status->label() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-200 font-medium dark:border-white/10">
                        <td class="py-2 pr-4 text-gray-950 dark:text-white">Total da medição</td>
                        <td class="py-2 pr-4"></td>
                        <td class="py-2 pr-4 text-right tabular-nums text-gray-950 dark:text-white">
                            {{ MeasurementFinancialReconciliationService::formatCurrency($reconciliation->expectedAmount) }}
                        </td>
                        <td class="py-2 pr-4 text-right tabular-nums text-gray-950 dark:text-white">
                            {{ MeasurementFinancialReconciliationService::formatCurrency($reconciliation->registeredAmount) }}
                        </td>
                        <td class="py-2 pr-4 text-right tabular-nums text-gray-950 dark:text-white">
                            {{ MeasurementFinancialReconciliationService::formatCurrency($reconciliation->expectedBalance) }}
                        </td>
                        <td class="py-2 {{ $statusColors[$reconciliation->status->color()] ?? $statusColors['gray'] }}">
                            {{ $reconciliation->status->label() }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @unless ($reconciliation->referenceComplete)
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Algum empreendimento não tem fundo de obra no snapshot da Engenharia, então o total cobre apenas os empreendimentos com referência financeira.
            </p>
        @endunless

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Valor esperado = fundo de obra × percentual realizado da competência, ambos congelados no snapshot da Engenharia. A divergência é informativa e não bloqueia o fluxo.
        </p>
    @endif
</div>

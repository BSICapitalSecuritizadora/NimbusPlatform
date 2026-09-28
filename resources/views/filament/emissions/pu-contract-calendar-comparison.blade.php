@php
    /** @var array<string, mixed> $comparison */
    $format = fn (?string $value): string => $value === null ? '—' : number_format((float) $value, 8, ',', '.');
    $label = fn (?string $code): string => $code === null ? '—' : \App\Domain\PuCalculator\Support\BusinessCalendarRegistry::label($code);
    $divergent = collect($comparison['rows'])->filter(fn (array $row): bool => bccomp($row['difference'], '0', 8) !== 0
        || $row['official_business_day'] !== $row['contract_business_day']
        || bccomp($row['official_payment'], $row['contract_payment'], 8) !== 0);
@endphp

<div class="space-y-6">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        A curva oficial usa o calendário de mercado. Esta comparação recalcula a mesma curva contando os dias úteis
        pelo calendário do Termo, sem gravar nada. Datas de pagamento e observação do CDI continuam as da curva oficial.
    </p>

    @if (! $comparison['available'])
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950/40 dark:text-warning-200">
            {{ $comparison['reason'] }}
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Curva oficial</p>
                <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $label($comparison['official_calendar']) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Versão {{ $comparison['calculation_version'] }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Calendário do Termo</p>
                <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $label($comparison['contract_calendar']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Dias com diferença</p>
                <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ $comparison['divergent_days'] }} de {{ $comparison['days_compared'] }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Primeira: {{ $comparison['first_divergent_date'] ? \Carbon\CarbonImmutable::parse($comparison['first_divergent_date'])->format('d/m/Y') : '—' }}
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Maior diferença de PU</p>
                <p class="mt-2 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">R$ {{ $format($comparison['largest_difference']) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">No último dia: R$ {{ $format($comparison['last_difference']) }}</p>
            </div>
        </div>

        @if ($divergent->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nenhuma diferença entre {{ \Carbon\CarbonImmutable::parse($comparison['from'])->format('d/m/Y') }} e {{ \Carbon\CarbonImmutable::parse($comparison['to'])->format('d/m/Y') }}.
            </p>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Data</th>
                            <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">Dia útil (oficial / Termo)</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">PU oficial</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">PU pelo Termo</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Diferença</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Pagamento oficial</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">Pagamento pelo Termo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($divergent as $row)
                            <tr>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-200">
                                    {{ $row['official_business_day'] ? 'Sim' : 'Não' }} / {{ $row['contract_business_day'] ? 'Sim' : 'Não' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ $format($row['official_unit_value']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ $format($row['contract_unit_value']) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $format($row['difference']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ $format($row['official_payment']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-200">{{ $format($row['contract_payment']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">Só aparecem os dias com diferença de PU, de dia útil ou de pagamento.</p>
        @endif
    @endif
</div>

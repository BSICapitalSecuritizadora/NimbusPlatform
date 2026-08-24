<x-filament-panels::page>
    @if ($comparison === null)
        <x-filament::section
            heading="Comparação ainda não executada"
            description="Selecione dois calendários e um período. Nenhuma data será alterada."
            icon="heroicon-o-information-circle"
        >
            <p class="text-sm text-gray-600 dark:text-gray-300">
                O resultado separa diferenças de dia útil das diferenças de metadados e mostra o estado de cobertura de cada ano.
            </p>
        </x-filament::section>
    @else
        @php
            $stateLabels = [
                'missing' => 'Inexistente',
                'partial' => 'Parcial',
                'provisional' => 'Provisório',
                'confirmed' => 'Confirmado',
                'stale' => 'Desatualizado',
            ];
        @endphp

        <div class="flex flex-col gap-6">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([
                    ['label' => 'Datas comparadas', 'value' => $comparison['total_dates'], 'tone' => 'gray'],
                    ['label' => 'Decisão igual', 'value' => $comparison['identical_dates'], 'tone' => 'success'],
                    ['label' => 'Útil somente em A', 'value' => $comparison['business_day_only_a'], 'tone' => 'warning'],
                    ['label' => 'Útil somente em B', 'value' => $comparison['business_day_only_b'], 'tone' => 'warning'],
                    ['label' => 'Metadados divergentes', 'value' => $comparison['metadata_differences'], 'tone' => 'info'],
                ] as $metric)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-white/10 dark:bg-gray-900">
                        <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $metric['label'] }}</p>
                        <p class="pt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($metric['value'], 0, ',', '.') }}</p>
                    </div>
                @endforeach
            </div>

            <x-filament::section
                :heading="$comparison['calendar_a'].' × '.$comparison['calendar_b']"
                :description="sprintf('%s a %s', \Carbon\CarbonImmutable::parse($comparison['from'])->format('d/m/Y'), \Carbon\CarbonImmutable::parse($comparison['to'])->format('d/m/Y'))"
            >
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach (['a' => $comparison['calendar_a'], 'b' => $comparison['calendar_b']] as $side => $code)
                        <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
                            <div class="bg-gray-50 px-4 py-3 font-mono text-sm font-semibold text-gray-900 dark:bg-gray-800 dark:text-white">{{ $code }}</div>
                            <div class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($comparison['coverage'][$side] as $coverage)
                                    <div class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $coverage['year'] }}</span>
                                        <span class="text-right text-gray-600 dark:text-gray-300">
                                            {{ $stateLabels[$coverage['state']] ?? $coverage['state'] }}
                                            · {{ $coverage['covered_days'] }}/{{ $coverage['expected_days'] }} dias
                                            · rev. {{ $coverage['revision'] }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section
                heading="Divergências"
                :description="count($comparison['divergences']) === 0
                    ? 'Nenhuma divergência encontrada no período.'
                    : sprintf('%d divergência(s). Datas totalmente iguais foram omitidas.', count($comparison['divergences']))"
            >
                @if ($comparison['divergences'] === [])
                    <div class="rounded-lg border border-success-200 bg-success-50 p-4 text-sm font-medium text-success-800 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-200">
                        Os calendários são idênticos no período, inclusive em fonte, descrição, revisão e status anual.
                    </div>
                @else
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                            <thead class="bg-gray-50 text-left text-xs font-semibold tracking-wide text-gray-600 uppercase dark:bg-gray-800 dark:text-gray-300">
                                <tr>
                                    <th class="px-3 py-3">Data / tipo</th>
                                    <th class="px-3 py-3">{{ $comparison['calendar_a'] }}</th>
                                    <th class="px-3 py-3">{{ $comparison['calendar_b'] }}</th>
                                    <th class="px-3 py-3">Diferenças</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white dark:divide-white/5 dark:bg-gray-900">
                                @foreach ($comparison['divergences'] as $row)
                                    <tr class="align-top">
                                        <td class="whitespace-nowrap px-3 py-3">
                                            <div class="font-semibold text-gray-950 dark:text-white">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</div>
                                            <div class="pt-1 text-xs text-gray-500 dark:text-gray-400">
                                                {{ $row['category'] === 'metadata_difference' ? 'Metadados' : 'Decisão de dia útil' }}
                                            </div>
                                        </td>
                                        @foreach (['a', 'b'] as $side)
                                            <td class="min-w-64 px-3 py-3 text-gray-700 dark:text-gray-300">
                                                <span @class([
                                                    'inline-flex rounded-md px-2 py-0.5 text-xs font-semibold',
                                                    'bg-success-100 text-success-800 dark:bg-success-500/15 dark:text-success-200' => $row[$side]['is_business_day'],
                                                    'bg-danger-100 text-danger-800 dark:bg-danger-500/15 dark:text-danger-200' => ! $row[$side]['is_business_day'],
                                                ])>{{ $row[$side]['is_business_day'] ? 'Útil' : 'Não útil' }}</span>
                                                <div class="pt-2 font-medium text-gray-900 dark:text-white">{{ $row[$side]['description'] }}</div>
                                                <div class="pt-1 text-xs">Fonte: {{ $row[$side]['source'] ?? '—' }}</div>
                                                <div class="pt-1 text-xs">Revisão: {{ $row[$side]['revision'] }} · {{ $stateLabels[$row[$side]['coverage_state']] ?? $row[$side]['coverage_state'] }}</div>
                                                @if ($row[$side]['source_document'])
                                                    <div class="pt-1 text-xs break-words">Documento: {{ $row[$side]['source_document'] }}</div>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">{{ implode(', ', $row['differences']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>

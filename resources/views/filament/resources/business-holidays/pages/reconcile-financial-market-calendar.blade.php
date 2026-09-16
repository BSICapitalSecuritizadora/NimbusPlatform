<x-filament-panels::page>
    @if ($reconciliation === null)
        <x-filament::section
            heading="Reconciliação ainda não executada"
            description="Selecione um período. Nenhuma data será alterada."
            icon="heroicon-o-information-circle"
        >
            <p class="text-sm text-gray-600 dark:text-gray-300">
                O resultado confronta a evidência da ANBIMA com a da FEBRABAN data a data. Só o estado
                <strong>Confirmado pelas duas fontes</strong> é elegível a virar decisão do calendário consolidado;
                conflitos e coberturas parciais exigem decisão humana.
            </p>
        </x-filament::section>
    @else
        @php
            $tones = [
                'confirmed' => 'success',
                'source_only_anbima' => 'warning',
                'source_only_febraban' => 'warning',
                'conflict' => 'danger',
                'unknown' => 'gray',
            ];
            $statusLabels = [
                'confirmed' => 'Confirmado',
                'source_only_anbima' => 'Somente ANBIMA',
                'source_only_febraban' => 'Somente FEBRABAN',
                'conflict' => 'Conflito',
                'unknown' => 'Sem cobertura',
            ];
            $coverage = $reconciliation['coverage'];
        @endphp

        <div class="flex flex-col gap-6">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ($statusLabels as $key => $label)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-xs dark:border-white/10 dark:bg-gray-900">
                        <p class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $label }}</p>
                        <p class="pt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ number_format($reconciliation['totals'][$key] ?? 0, 0, ',', '.') }}
                        </p>
                    </div>
                @endforeach
            </div>

            @if ($reconciliation['has_conflicts'])
                <x-filament::section heading="Conflito entre fontes" icon="heroicon-o-exclamation-triangle">
                    <p class="text-sm text-danger-600 dark:text-danger-400">
                        Há datas em que ANBIMA e FEBRABAN divergem. Nenhuma foi resolvida automaticamente: não existe
                        voto de maioria nem precedência silenciosa de uma fonte sobre a outra.
                    </p>
                </x-filament::section>
            @endif

            <x-filament::section
                heading="Cobertura das fontes"
                :description="$coverage['coverage_status'] === 'complete'
                    ? 'Todos os anos do período têm cobertura nas duas fontes.'
                    : 'Cobertura incompleta: a ausência de dados NÃO é tratada como confirmação.'"
            >
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <span class="font-medium text-gray-900 dark:text-white">Anos com ANBIMA:</span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $coverage['anbima_years'] === [] ? '—' : implode(', ', $coverage['anbima_years']) }}</span>
                    </div>
                    <div>
                        <span class="font-medium text-gray-900 dark:text-white">Anos com FEBRABAN:</span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $coverage['febraban_years'] === [] ? '—' : implode(', ', $coverage['febraban_years']) }}</span>
                    </div>
                    <div>
                        <span class="font-medium text-gray-900 dark:text-white">Reconciliável de/até:</span>
                        <span class="text-gray-600 dark:text-gray-300">
                            {{ $coverage['covered_from'] ?? '—' }} … {{ $coverage['covered_to'] ?? '—' }}
                        </span>
                    </div>
                    <div>
                        <span class="font-medium text-gray-900 dark:text-white">Anos sem cobertura plena:</span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $coverage['years_without_full_coverage'] === [] ? '—' : implode(', ', $coverage['years_without_full_coverage']) }}</span>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section
                heading="Datas reconciliadas"
                :description="sprintf('%d data(s) entre %s e %s. Base normativa: %s.',
                    $reconciliation['total_dates'],
                    \Carbon\CarbonImmutable::parse($reconciliation['from'])->format('d/m/Y'),
                    \Carbon\CarbonImmutable::parse($reconciliation['to'])->format('d/m/Y'),
                    $reconciliation['norm_reference'])"
            >
                @if ($reconciliation['rows'] === [])
                    <p class="text-sm text-gray-600 dark:text-gray-300">Nenhuma evidência encontrada no período.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-gray-200 text-left dark:border-white/10">
                                <tr class="text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                                    <th class="px-3 py-2">Data</th>
                                    <th class="px-3 py-2">ANBIMA</th>
                                    <th class="px-3 py-2">FEBRABAN</th>
                                    <th class="px-3 py-2">Consolidado</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2">Evidência</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($reconciliation['rows'] as $row)
                                    <tr>
                                        <td class="px-3 py-2 font-mono whitespace-nowrap text-gray-900 dark:text-white">
                                            {{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}
                                        </td>
                                        <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                            {{ $row['anbima'] === null ? '—' : ($row['anbima']['decision'] === 'non_business' ? 'Não útil' : 'Retratado') }}
                                            @if ($row['anbima'] !== null)
                                                <span class="block text-xs text-gray-400">{{ $row['anbima']['name'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                            {{ $row['febraban'] === null ? '—' : ($row['febraban']['decision'] === 'non_business' ? 'Não útil' : 'Retratado') }}
                                            @if ($row['febraban'] !== null)
                                                <span class="block text-xs text-gray-400">{{ $row['febraban']['name'] }}</span>
                                            @endif
                                            @if ($row['special_hours'] !== null)
                                                <span class="block text-xs text-warning-600 dark:text-warning-400">
                                                    Expediente especial (dia ÚTIL): {{ $row['special_hours']['name'] }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                            {{ $row['consolidated'] === 'non_business' ? 'Não útil' : 'Sem decisão' }}
                                        </td>
                                        <td class="px-3 py-2">
                                            <x-filament::badge :color="$tones[$row['status']] ?? 'gray'">
                                                {{ $row['status_label'] }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
                                            {{ $row['evidence'] === [] ? '—' : implode(' + ', $row['evidence']) }}
                                        </td>
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

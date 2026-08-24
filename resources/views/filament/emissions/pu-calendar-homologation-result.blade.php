@php
    $summary = $homologation->result_summary ?? [];
    $first = $homologation->first_divergence;
    $impacts = $summary['accumulated_impacts'] ?? [];
    $warnings = $summary['governance_warnings'] ?? [];
    $rows = $homologation->daily_diff ?? [];
@endphp

<div class="flex flex-col gap-6">
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
        <p class="font-semibold">Resultado experimental e não vinculante</p>
        <p class="mt-1">Esta execução não alterou a configuração ativa, o PU oficial, pagamentos, eventos ou histórico.</p>
    </div>

    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Legacy</dt>
            <dd class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $summary['legacy_calendar_code'] ?? '—' }}</dd>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Candidate</dt>
            <dd class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $summary['candidate_calendar_code'] ?? $homologation->candidate_calendar_code }}</dd>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Linhas divergentes</dt>
            <dd class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $summary['divergent_rows'] ?? 0 }} de {{ $summary['rows_compared'] ?? 0 }}</dd>
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Checksum</dt>
            <dd class="mt-1 break-all font-mono text-xs text-gray-700 dark:text-gray-300">{{ $homologation->comparison_checksum ?? '—' }}</dd>
        </div>
    </dl>

    @if ($warnings !== [])
        <div class="rounded-xl border border-orange-200 bg-orange-50 p-4 dark:border-orange-800 dark:bg-orange-950/40">
            <h3 class="font-semibold text-orange-950 dark:text-orange-100">Governança ainda impede aprovação</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-orange-900 dark:text-orange-200">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <h3 class="font-semibold text-gray-950 dark:text-white">Primeira divergência</h3>
        @if (is_array($first))
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ \Carbon\CarbonImmutable::parse($first['date'])->format('d/m/Y') }}</p>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-gray-800 dark:text-gray-200">
                @foreach (($first['causal_chain'] ?? []) as $cause)
                    <li>{{ $cause }}</li>
                @endforeach
            </ol>
        @else
            <p class="mt-2 text-sm text-emerald-700 dark:text-emerald-300">Nenhuma divergência foi encontrada no período. Coincidência numérica não substitui a evidência contratual.</p>
        @endif
    </section>

    @if ($impacts !== [])
        <section class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <h3 class="font-semibold text-gray-950 dark:text-white">Impacto acumulado</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3">Marco</th>
                            <th class="px-4 py-3 text-right">PU</th>
                            <th class="px-4 py-3 text-right">PU %</th>
                            <th class="px-4 py-3 text-right">Juros pagos</th>
                            <th class="px-4 py-3 text-right">Pagamento total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($impacts as $label => $impact)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ str($label)->replace('_', ' ')->title() }} · {{ $impact['cutoff'] }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ $impact['pu_difference'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ $impact['pu_difference_percentage'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ $impact['interest_payment_difference'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ $impact['total_payment_difference'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
        <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
            <h3 class="font-semibold text-gray-950 dark:text-white">Diff diário</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Exibindo datas divergentes; o JSON persistido conserva todas as linhas comparadas.</p>
        </div>
        <div class="max-h-[32rem] overflow-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="sticky top-0 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Data</th>
                        <th class="px-4 py-3">Dia útil L / C</th>
                        <th class="px-4 py-3">DUP L / C</th>
                        <th class="px-4 py-3">Taxa DI L / C</th>
                        <th class="px-4 py-3 text-right">Fator total L / C</th>
                        <th class="px-4 py-3 text-right">PU L / C</th>
                        <th class="px-4 py-3">Campos alterados</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse (collect($rows)->where('diverges', true)->take(250) as $row)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-medium">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ ($row['legacy']['is_business_day'] ?? false) ? 'Sim' : 'Não' }} / {{ ($row['candidate']['is_business_day'] ?? false) ? 'Sim' : 'Não' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $row['legacy']['dup'] ?? '—' }} / {{ $row['candidate']['dup'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $row['legacy']['index_rate_date'] ?? '—' }} / {{ $row['candidate']['index_rate_date'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono">{{ $row['legacy']['factor_total'] ?? '—' }} / {{ $row['candidate']['factor_total'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono">{{ $row['legacy']['pu'] ?? '—' }} / {{ $row['candidate']['pu'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">{{ implode(', ', $row['changed_fields'] ?? []) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">Nenhuma linha divergente.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

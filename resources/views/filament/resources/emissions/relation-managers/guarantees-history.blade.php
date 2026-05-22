@php
    /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $history */
    $history ??= collect();

    $formatCurrency = static fn (mixed $value): string => 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);
    $formatRatio = static fn (?float $value): string => $value === null ? 'Nao disponivel' : number_format($value * 100, 0, ',', '.') . '%';
@endphp

<div class="mt-6 space-y-4">
    <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/10">
        <div class="flex flex-col gap-2 border-b border-white/10 px-6 py-5 sm:px-8">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Historico mensal</span>
            <div class="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
                <h3 class="text-lg font-semibold text-white">Evolucao da cobertura por competencia</h3>
                <p class="text-sm text-gray-400">Cada linha usa as quotas registradas manualmente em Garantias e os demais dados consolidados automaticamente na mesma competencia.</p>
            </div>
        </div>

        @if ($history->isEmpty())
            <div class="px-6 py-6 sm:px-8">
                <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.03] px-4 py-5 text-sm text-gray-400">
                    Nenhum historico mensal cadastrado ainda.
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/[0.03]">
                        <tr class="text-left text-xs font-semibold uppercase tracking-[0.16em] text-gray-400">
                            <th class="px-4 py-3">Mes</th>
                            <th class="px-4 py-3">Quotas</th>
                            <th class="px-4 py-3">Unidades</th>
                            <th class="px-4 py-3">Recebiveis</th>
                            <th class="px-4 py-3">Contas</th>
                            <th class="px-4 py-3">Saldo devedor</th>
                            <th class="px-4 py-3">Indice</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($history as $row)
                            <tr class="align-top text-gray-200">
                                <td class="px-4 py-4">
                                    <div class="font-medium text-white">{{ $row['reference_month_label'] }}</div>
                                    @if (count($row['missing_sources']) > 0)
                                        <div class="mt-1 text-xs text-amber-200">
                                            Pendente: {{ implode(', ', $row['missing_sources']) }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">{{ $formatCurrency($row['quota_value']) }}</td>
                                <td class="px-4 py-4">{{ $formatCurrency($row['units_value']) }}</td>
                                <td class="px-4 py-4">{{ $formatCurrency($row['receivables_value']) }}</td>
                                <td class="px-4 py-4">{{ $formatCurrency($row['account_balance_value']) }}</td>
                                <td class="px-4 py-4">{{ $formatCurrency($row['outstanding_balance_value']) }}</td>
                                <td class="px-4 py-4">
                                    <div class="font-medium text-white">{{ $formatRatio($row['coverage_ratio']) }}</div>
                                    <div class="mt-1 text-xs text-gray-400">Total: {{ $formatCurrency($row['total_guarantees_value']) }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

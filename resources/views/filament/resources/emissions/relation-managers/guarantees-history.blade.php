@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\GuaranteeSnapshot> $history */
    $history ??= collect();

    $money = static fn (mixed $value): string => $value === null
        ? '—'
        : 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay((float) $value);

    $ratio = static fn (mixed $value): string => $value === null
        ? '—'
        : number_format((float) $value * 100, 2, ',', '.') . '%';
@endphp

<div class="mt-8 pt-6 border-t border-[#1d4554]/30 space-y-4">
    <section class="overflow-hidden rounded-2xl border border-[#1d4554]/50 bg-[#0c232e] shadow-xl shadow-black/15">
        <div class="flex flex-col gap-1 border-b border-[#1d4554]/40 px-5 py-3.5 sm:px-6">
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#a06e28] font-mono">Histórico de Enquadramento</span>
            <div class="flex flex-col gap-1 xl:flex-row xl:items-center xl:justify-between">
                <h3 class="text-base font-semibold text-[#fbfaf8]">Cobertura por Competência</h3>
                <p class="text-xs text-slate-400">
                    Snapshots consolidados e imutáveis gravados no fechamento de cada competência.
                </p>
            </div>
        </div>

        @if ($history->isEmpty())
            <div class="px-5 py-6 sm:px-6 text-center">
                <div class="rounded-xl border border-dashed border-[#1d4554]/45 bg-[#081a22] px-4 py-5 text-xs text-slate-400">
                    Nenhuma competência consolidada ainda. Use <strong class="text-[#fbfaf8] font-medium">Atualizar competência</strong> no topo para gravar a primeira posição.
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-[#1d4554]/35 text-xs">
                    <thead class="bg-[#081a22]">
                        <tr class="text-left text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">
                            <th class="px-4 py-2.5">Competência</th>
                            <th class="px-4 py-2.5 text-right">Saldo Devedor</th>
                            <th class="px-4 py-2.5 text-right">Valor Bruto</th>
                            <th class="px-4 py-2.5 text-right">Valor Elegível</th>
                            <th class="px-4 py-2.5 text-right">Exigido</th>
                            <th class="px-4 py-2.5 text-right">Cobertura</th>
                            <th class="px-4 py-2.5 text-right">Exced./Déficit</th>
                            <th class="px-4 py-2.5">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#1d4554]/25">
                        @foreach ($history as $snapshot)
                            @php
                                $snapshotStatus = $snapshot->coverage_status;
                                $badgeClasses = match ($snapshotStatus?->color()) {
                                    'success' => 'bg-emerald-950/40 text-emerald-300 border border-emerald-500/30',
                                    'warning' => 'bg-amber-950/40 text-amber-300/90 border border-amber-500/30',
                                    'danger' => 'bg-rose-950/50 text-rose-300 border border-rose-500/30',
                                    default => 'bg-[#081a22] text-slate-400 border border-[#1d4554]/40',
                                };
                                $surplus = $snapshot->surplus_deficit;
                                $isNegative = $surplus !== null && (float) $surplus < 0;
                            @endphp
                            <tr class="align-top text-slate-200 hover:bg-[#081a22]/50 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-[#fbfaf8]">{{ $snapshot->formatted_reference_month }}</div>
                                    @if ($snapshot->isClosed())
                                        <div class="mt-0.5 text-[10px] text-slate-400 flex items-center gap-1">
                                            <x-heroicon-m-lock-closed class="h-3 w-3 text-slate-400" />
                                            Fechada
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono tabular-nums text-slate-300">{{ $money($snapshot->outstanding_balance) }}</td>
                                <td class="px-4 py-3 text-right font-mono tabular-nums text-slate-400">{{ $money($snapshot->total_gross_value) }}</td>
                                <td class="px-4 py-3 text-right font-mono tabular-nums font-semibold text-[#fbfaf8]">{{ $money($snapshot->total_eligible_value) }}</td>
                                <td class="px-4 py-3 text-right font-mono tabular-nums text-slate-400">{{ $money($snapshot->total_required_value) }}</td>
                                <td class="px-4 py-3 text-right font-mono tabular-nums">
                                    <div class="font-bold text-[#fbfaf8]">{{ $ratio($snapshot->coverage_ratio) }}</div>
                                    <div class="mt-0.5 text-[10px] text-slate-400">Mín.: {{ $ratio($snapshot->required_ratio) }}</div>
                                </td>
                                <td class="px-4 py-3 text-right font-mono tabular-nums font-medium {{ $isNegative ? 'text-rose-400' : 'text-slate-300' }}">
                                    {{ $money($surplus) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-md px-2.5 py-0.5 text-[11px] font-semibold {{ $badgeClasses }}">
                                        {{ $snapshotStatus?->label() ?? '—' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

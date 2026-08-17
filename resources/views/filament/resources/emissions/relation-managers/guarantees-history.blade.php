@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\GuaranteeSnapshot> $history */
    $history ??= collect();

    $money = static fn (mixed $value): string => $value === null
        ? '—'
        : 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);
    $ratio = static fn (mixed $value): string => $value === null
        ? '—'
        : number_format((float) $value * 100, 2, ',', '.') . '%';
@endphp

<div class="mt-6 space-y-4">
    <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/10">
        <div class="flex flex-col gap-2 border-b border-white/10 px-6 py-5 sm:px-8">
            <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Evolução histórica</span>
            <div class="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
                <h3 class="text-lg font-semibold text-white">Cobertura por competência</h3>
                <p class="text-sm text-gray-400">
                    Cada linha é o snapshot gravado no fechamento — não muda quando uma fonte é corrigida depois.
                </p>
            </div>
        </div>

        @if ($history->isEmpty())
            <div class="px-6 py-6 sm:px-8">
                <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.03] px-4 py-5 text-sm text-gray-400">
                    Nenhuma competência consolidada ainda. Use <span class="font-medium text-white">Atualizar competência</span> para gravar a primeira posição.
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/[0.03]">
                        <tr class="text-left text-xs font-semibold uppercase tracking-[0.16em] text-gray-400">
                            <th class="px-4 py-3">Competência</th>
                            <th class="px-4 py-3 text-right">Saldo devedor</th>
                            <th class="px-4 py-3 text-right">Valor bruto</th>
                            <th class="px-4 py-3 text-right">Valor elegível</th>
                            <th class="px-4 py-3 text-right">Exigido</th>
                            <th class="px-4 py-3 text-right">Cobertura</th>
                            <th class="px-4 py-3 text-right">Exced./Déficit</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($history as $snapshot)
                            @php
                                $snapshotStatus = $snapshot->coverage_status;
                                $badgeClasses = match ($snapshotStatus?->color()) {
                                    'success' => 'bg-emerald-500/10 text-emerald-200',
                                    'warning' => 'bg-amber-500/10 text-amber-200',
                                    'danger' => 'bg-rose-500/10 text-rose-200',
                                    default => 'bg-white/[0.05] text-gray-300',
                                };
                                $surplus = $snapshot->surplus_deficit;
                            @endphp
                            <tr class="align-top text-gray-200">
                                <td class="px-4 py-4">
                                    <div class="font-medium text-white">{{ $snapshot->formatted_reference_month }}</div>
                                    @if ($snapshot->isClosed())
                                        <div class="mt-0.5 text-xs text-gray-400">Fechada</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-right">{{ $money($snapshot->outstanding_balance) }}</td>
                                <td class="px-4 py-4 text-right">{{ $money($snapshot->total_gross_value) }}</td>
                                <td class="px-4 py-4 text-right">{{ $money($snapshot->total_eligible_value) }}</td>
                                <td class="px-4 py-4 text-right">{{ $money($snapshot->total_required_value) }}</td>
                                <td class="px-4 py-4 text-right">
                                    <div class="font-medium text-white">{{ $ratio($snapshot->coverage_ratio) }}</div>
                                    <div class="mt-0.5 text-xs text-gray-400">Mín.: {{ $ratio($snapshot->required_ratio) }}</div>
                                </td>
                                <td class="px-4 py-4 text-right {{ ($surplus !== null && (float) $surplus < 0) ? 'text-rose-200' : '' }}">
                                    {{ $money($surplus) }}
                                </td>
                                <td class="px-4 py-4">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badgeClasses }}">
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

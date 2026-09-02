@php
    /** @var \App\Models\Negotiation $record */
    /** @var array<int, array{type:string,code:string,development:string,block:string,unit:string,date:\Illuminate\Support\Carbon|null,date_formatted:string,display:string,contract_id:int}> $events */
    $month = $record->reference_month instanceof \Carbon\CarbonInterface
        ? \Carbon\Carbon::parse($record->reference_month)->format('m/Y')
        : \App\Models\Negotiation::formatReferenceMonthForDisplay($record->reference_month);
    $salesCount = collect($events)->where('type', 'Venda')->count();
    $distratosCount = collect($events)->where('type', 'Distrato')->count();
    $totalCount = $salesCount + $distratosCount;
    $emissionName = $record->emission?->name ?? $record->getAttribute('emission_name') ?? '—';
    $developmentName = $record->construction?->development_name ?? $record->getAttribute('development_name') ?? '—';
@endphp

<div class="space-y-4">
    {{-- Resumo da Competência --}}
    <div>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Resumo da competência
        </h3>
        <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4 dark:border-white/10 dark:bg-[#0c222b]">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <span class="block text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Emissão</span>
                    <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-[#fbfaf8] truncate" title="{{ $emissionName }}">
                        {{ $emissionName }}
                    </p>
                </div>
                <div>
                    <span class="block text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Empreendimento</span>
                    <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-[#fbfaf8] truncate" title="{{ $developmentName }}">
                        {{ $developmentName }}
                    </p>
                </div>
                <div>
                    <span class="block text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Competência</span>
                    <p class="mt-0.5 text-sm font-semibold text-slate-900 dark:text-[#fbfaf8] tabular-nums">
                        {{ $month }}
                    </p>
                </div>
            </div>

            {{-- Faixa de Métricas --}}
            <div class="mt-3.5 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-200/70 pt-3 text-xs dark:border-white/10">
                <span class="inline-flex items-center gap-1.5 font-medium text-slate-700 dark:text-slate-200">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    <span><strong class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $salesCount }}</strong> {{ $salesCount === 1 ? 'Venda' : 'Vendas' }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 font-medium text-slate-700 dark:text-slate-200">
                    <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                    <span><strong class="font-semibold text-rose-700 dark:text-rose-400">{{ $distratosCount }}</strong> {{ $distratosCount === 1 ? 'Distrato' : 'Distratos' }}</span>
                </span>
                <span class="inline-flex items-center gap-1.5 font-medium text-slate-500 dark:text-slate-400">
                    <span><strong class="font-semibold text-slate-700 dark:text-slate-300">{{ $totalCount }}</strong> {{ $totalCount === 1 ? 'movimentação' : 'movimentações' }}</span>
                </span>
            </div>
        </div>
    </div>

    {{-- Listagem de Movimentações --}}
    <div>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Movimentações
        </h3>

        @if (empty($events))
            <div class="rounded-xl border border-slate-200/80 bg-slate-50/50 p-6 text-center text-sm text-slate-500 dark:border-white/10 dark:bg-[#0c222b] dark:text-slate-400">
                Nenhuma movimentação encontrada para esta competência.
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-slate-200/80 bg-white shadow-xs dark:border-white/10 dark:bg-[#07151c]">
                {{-- Desktop Table --}}
                <div class="hidden sm:block overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-slate-200/80 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-600 dark:border-white/10 dark:bg-[#0a2028] dark:text-slate-300">
                            <tr>
                                <th scope="col" class="w-28 px-4 py-2.5">Tipo</th>
                                <th scope="col" class="px-4 py-2.5">Contrato</th>
                                <th scope="col" class="w-24 px-4 py-2.5">Bloco</th>
                                <th scope="col" class="w-24 px-4 py-2.5">Unidade</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white dark:divide-white/5 dark:bg-[#07151c]">
                            @foreach ($events as $event)
                                <tr class="transition-colors duration-100 hover:bg-slate-50 dark:hover:bg-[#0e2733]/60">
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        @if ($event['type'] === 'Venda')
                                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/25">Venda</span>
                                        @else
                                            <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-400/25">Distrato</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs font-semibold tabular-nums text-slate-900 select-all dark:text-[#fbfaf8]">
                                        {{ $event['code'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-xs font-medium text-slate-700 dark:text-slate-300">
                                        {{ $event['block'] ?? '—' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-xs font-medium text-slate-700 dark:text-slate-300">
                                        {{ $event['unit'] ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile List (Stacked) --}}
                <div class="divide-y divide-slate-100 dark:divide-white/5 sm:hidden">
                    @foreach ($events as $event)
                        <div class="space-y-2 p-3 transition-colors hover:bg-slate-50 dark:hover:bg-[#0e2733]/60">
                            <div class="flex items-center justify-between gap-2">
                                @if ($event['type'] === 'Venda')
                                    <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/25">Venda</span>
                                @else
                                    <span class="inline-flex items-center rounded-md bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-400/25">Distrato</span>
                                @endif
                                <span class="font-mono text-xs font-semibold tabular-nums text-slate-900 select-all dark:text-[#fbfaf8]">{{ $event['code'] }}</span>
                            </div>
                            <div class="flex items-center gap-4 text-xs text-slate-600 dark:text-slate-300">
                                <span>Bloco: <strong class="font-medium text-slate-900 dark:text-[#fbfaf8]">{{ $event['block'] ?? '—' }}</strong></span>
                                <span>Unidade: <strong class="font-medium text-slate-900 dark:text-[#fbfaf8]">{{ $event['unit'] ?? '—' }}</strong></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Nota Técnica / Fonte --}}
    <div class="flex items-start gap-2 rounded-lg border border-slate-200/50 bg-slate-50/70 p-3 text-xs text-slate-500 dark:border-white/5 dark:bg-white/[0.02] dark:text-slate-400">
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" />
        </svg>
        <div class="space-y-0.5 leading-relaxed">
            <p class="font-medium text-slate-700 dark:text-slate-300">Fonte dos dados: Contratos</p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400">Vendas são identificadas pela data da venda e distratos pela data de cancelamento.</p>
        </div>
    </div>
</div>

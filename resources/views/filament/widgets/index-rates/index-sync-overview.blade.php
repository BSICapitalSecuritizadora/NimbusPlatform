<x-filament-widgets::widget class="bsi-index-sync-overview-widget">
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Resumo de Sincronização
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Status operacional das séries econômicas integradas ao Banco Central do Brasil (SGS).
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach ($cards as $indexer => $card)
                <div class="relative overflow-hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-gray-300 dark:border-white/10 dark:bg-[#0d252e] dark:hover:border-amber-500/30">
                    <!-- Header do Card -->
                    <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-3 dark:border-white/5">
                        <div class="flex items-center gap-2.5">
                            <span @class([
                                'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold',
                                'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-300' => $indexer === 'CDI',
                                'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' => $indexer === 'IPCA',
                            ])>
                                {{ $indexer }}
                            </span>
                            <div>
                                <h4 class="text-sm font-semibold text-gray-950 dark:text-[#fbfaf8]">
                                    {{ $indexer === 'CDI' ? 'Taxa CDI (DI over)' : 'IPCA (Preços ao Consumidor)' }}
                                </h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Série SGS {{ $card['series_code'] }}
                                </p>
                            </div>
                        </div>

                        <!-- Status Badge -->
                        @if ($card['state'] === 'synced')
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                                </svg>
                                Sincronizado
                            </span>
                        @elseif ($card['state'] === 'failed')
                            <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                                </svg>
                                Falhou
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 000-1.5h-3.25V5z" clip-rule="evenodd" />
                                </svg>
                                Pendente
                            </span>
                        @endif
                    </div>

                    <!-- Dados Principais em Grade -->
                    <div class="mt-3 grid grid-cols-2 gap-3 text-xs">
                        <div>
                            <dt class="text-[0.6875rem] font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                Última Referência
                            </dt>
                            <dd class="mt-0.5 font-mono text-sm font-semibold text-gray-950 dark:text-[#fbfaf8]">
                                {{ $card['latest_rate_date'] }}
                            </dd>
                            <div class="mt-0.5 font-mono text-[0.6875rem] text-gray-600 dark:text-gray-300">
                                {{ $card['latest_rate_value'] }}
                            </div>
                        </div>

                        <div>
                            <dt class="text-[0.6875rem] font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">
                                Última Sincronização
                            </dt>
                            <dd class="mt-0.5 font-mono text-xs font-medium text-gray-900 dark:text-[#fbfaf8]">
                                {{ $card['last_synced_at'] }}
                            </dd>
                            <div class="mt-0.5 text-[0.6875rem] text-gray-500 dark:text-gray-400">
                                {{ $card['source_label'] }}
                            </div>
                        </div>
                    </div>

                    @if ($card['error'])
                        <div class="mt-2.5 rounded-md bg-rose-50 p-2 text-xs text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">
                            Erro no último sync: {{ $card['error'] }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>

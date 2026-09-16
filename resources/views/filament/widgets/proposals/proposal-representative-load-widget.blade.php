@php
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $details = $this->getDetails();
    $representativesUrl = $this->getRepresentativesUrl();
@endphp

<x-filament-widgets::widget class="bsi-cockpit-widget bsi-proposal-representative-load-widget">
    <x-filament::section
        :description="$description"
        :heading="$heading"
    >
        <x-slot name="afterHeader">
            <a
                href="{{ $representativesUrl }}"
                class="group inline-flex items-center gap-1.5 rounded-lg border border-gray-300/80 bg-white/90 px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-2xs transition-all duration-150 hover:border-[#a06e28]/50 hover:bg-gray-50 hover:text-gray-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#a06e28]/40 dark:border-white/10 dark:bg-gray-800/90 dark:text-gray-200 dark:hover:border-[#a06e28]/60 dark:hover:bg-gray-700/80 dark:hover:text-white"
                aria-label="Gerenciar fila comercial e distribuição de representantes"
            >
                <span>Gerenciar Fila</span>
                <x-heroicon-m-arrow-top-right-on-square class="size-3.5 shrink-0 text-gray-400 transition-colors group-hover:text-gray-600 dark:text-gray-400 dark:group-hover:text-gray-200" aria-hidden="true" />
            </a>
        </x-slot>

        @if($details['total_representatives'] === 0)
            <div class="flex flex-col items-center justify-center py-8 text-center">
                <div class="flex size-10 items-center justify-center rounded-xl border border-gray-200/80 bg-gray-100/80 text-gray-400 dark:border-white/10 dark:bg-[#091b23] dark:text-gray-400">
                    <x-heroicon-o-users class="size-5" aria-hidden="true" />
                </div>
                <p class="mt-3 text-sm font-semibold text-gray-950 dark:text-white">Nenhum representante comercial cadastrado ou ativo.</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Ative representantes na gestão de fila para iniciar a distribuição de processos.</p>
            </div>
        @else
            {{-- Níveis 1 & 2: Resumo da Fila (Mini-KPIs) e Status Geral da Distribuição --}}
            <div class="mb-4 flex flex-col gap-3 rounded-xl border border-gray-200/80 bg-gray-50/60 p-3 sm:flex-row sm:items-center sm:justify-between sm:px-4 sm:py-2.5 dark:border-white/10 dark:bg-[#091b23]/50">
                {{-- Mini-KPIs Compactos --}}
                <div class="flex items-center divide-x divide-gray-200/80 dark:divide-white/10">
                    <div class="pr-3.5 sm:pr-5">
                        <span class="block text-base font-bold tabular-nums leading-tight text-gray-950 sm:text-lg dark:text-white">
                            {{ $details['total_active_proposals'] }}
                        </span>
                        <span class="block text-[0.6875rem] font-medium text-gray-500 dark:text-gray-400">
                            {{ $details['total_active_proposals'] === 1 ? 'Processo ativo' : 'Processos ativos' }}
                        </span>
                    </div>

                    <div class="px-3.5 sm:px-5">
                        <span class="block text-base font-bold tabular-nums leading-tight text-gray-950 sm:text-lg dark:text-white">
                            {{ $details['total_representatives'] }}
                        </span>
                        <span class="block text-[0.6875rem] font-medium text-gray-500 dark:text-gray-400">
                            {{ $details['total_representatives'] === 1 ? 'Responsável' : 'Responsáveis' }}
                        </span>
                    </div>

                    <div class="pl-3.5 sm:pl-5">
                        <span class="block text-base font-bold tabular-nums leading-tight text-gray-950 sm:text-lg dark:text-white">
                            {{ number_format($details['average_load'], 1, ',', '.') }}
                        </span>
                        <span class="block text-[0.6875rem] font-medium text-gray-500 dark:text-gray-400">
                            Média por responsável
                        </span>
                    </div>
                </div>

                {{-- Status Geral de Balanceamento --}}
                <div class="shrink-0 self-start sm:self-auto">
                    @php
                        $statusStyles = match ($details['balance_status']['badge_color']) {
                            'success' => 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/30',
                            'info' => 'bg-sky-500/10 text-sky-700 border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-400 dark:border-sky-500/30',
                            'warning' => 'bg-amber-500/10 text-amber-700 border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/30',
                            default => 'bg-gray-500/10 text-gray-700 border-gray-500/20 dark:bg-gray-500/10 dark:text-gray-400 dark:border-gray-500/30',
                        };
                    @endphp
                    <div class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[0.6875rem] font-medium {{ $statusStyles }}">
                        @if($details['balance_status']['badge_color'] === 'success')
                            <x-heroicon-m-check-badge class="size-3.5 shrink-0" aria-hidden="true" />
                        @elseif($details['balance_status']['badge_color'] === 'warning')
                            <x-heroicon-m-exclamation-circle class="size-3.5 shrink-0" aria-hidden="true" />
                        @else
                            <x-heroicon-m-scale class="size-3.5 shrink-0" aria-hidden="true" />
                        @endif
                        <span class="leading-none">{{ $details['balance_status']['label'] }}</span>
                    </div>
                </div>
            </div>

            {{-- Nível 3: Distribuição por Responsável (Cards em Grade Responsiva) --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach($details['items'] as $item)
                    <div class="group relative flex flex-col justify-between rounded-xl border border-gray-200/80 bg-white p-3.5 transition-all duration-150 hover:border-gray-300 hover:shadow-2xs dark:border-white/10 dark:bg-[#091b23] dark:hover:border-[#a06e28]/35 sm:p-4">
                        <div class="flex items-start justify-between gap-3 min-w-0">
                            {{-- Bloco de Identificação: Avatar + Nome + Fila + E-mail --}}
                            <div class="flex items-start gap-3 min-w-0 flex-1">
                                {{-- Avatar / Iniciais --}}
                                <div class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-[#a06e28]/25 bg-[#091b23] text-xs font-bold tracking-wider text-[#b7832f] shadow-2xs dark:border-[#a06e28]/35 dark:bg-[#06151c] dark:text-[#d4af37]">
                                    {{ strtoupper(substr($item['name'], 0, 2)) }}
                                </div>

                                {{-- Detalhes do Responsável --}}
                                <div class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-gray-950 line-clamp-2 leading-snug dark:text-white" title="{{ $item['name'] }}">
                                        {{ $item['name'] }}
                                    </span>
                                    <div class="mt-1 space-y-0.5">
                                        @if(filled($item['queue_position']))
                                            <span class="block text-[0.6875rem] font-medium text-gray-500 dark:text-gray-400">
                                                Fila #{{ $item['queue_position'] }}
                                            </span>
                                        @endif
                                        <span class="block truncate text-[0.6875rem] text-gray-400 dark:text-gray-500" title="{{ $item['email'] }}">
                                            {{ $item['email'] }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {{-- Métrica: Quantidade de Processos e Percentual da Carga --}}
                            <div class="shrink-0 text-right">
                                <span class="block text-sm font-bold tabular-nums leading-snug text-gray-950 dark:text-white">
                                    {{ $item['count'] }}
                                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400">
                                        {{ $item['count'] === 1 ? 'processo' : 'processos' }}
                                    </span>
                                </span>
                                <span class="mt-1 block text-[0.6875rem] tabular-nums">
                                    @if($item['count'] > 0)
                                        <span class="font-medium text-gray-500 dark:text-gray-400">{{ $item['percentage'] }}% da carga</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400">
                                            <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                            Disponível
                                        </span>
                                    @endif
                                </span>
                            </div>
                        </div>

                        {{-- Barra Proporcional de Carga com Acabamento Dourado Institucional --}}
                        <div class="mt-3.5 w-full">
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                                <div
                                    class="h-full rounded-full transition-all duration-300 {{ $item['count'] > 0 ? 'bg-[#a06e28] dark:bg-[#b7832f]' : 'bg-transparent' }}"
                                    style="width: {{ $details['total_active_proposals'] > 0 ? max($item['percentage'], ($item['count'] > 0 ? 5 : 0)) : 0 }}%;"
                                ></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

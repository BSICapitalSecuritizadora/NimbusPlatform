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
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200/80 bg-bsi-paper px-2.5 py-1 text-xs font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:border-gray-300 hover:bg-gray-50 hover:text-gray-950 dark:border-gray-700/80 dark:bg-gray-800/80 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-700 dark:hover:text-white"
                aria-label="Gerenciar fila comercial e distribuição de representantes"
            >
                <span>Gerenciar Fila</span>
                <x-heroicon-m-arrow-top-right-on-square class="size-3.5 text-gray-400 dark:text-gray-500" aria-hidden="true" />
            </a>
        </x-slot>

        @if($details['total_representatives'] === 0)
            <div class="flex flex-col items-center justify-center py-6 text-center">
                <div class="flex size-10 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500">
                    <x-heroicon-o-users class="size-5" aria-hidden="true" />
                </div>
                <p class="mt-2.5 text-sm font-semibold text-gray-950 dark:text-white">Nenhum representante comercial cadastrado ou ativo.</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Ative representantes na gestão de fila para iniciar a distribuição de processos.</p>
            </div>
        @else
            {{-- Faixa de Síntese Executiva e Equilíbrio da Carga --}}
            <div class="mb-3.5 flex flex-wrap items-center justify-between gap-2.5 rounded-lg border border-gray-200/70 bg-gray-50/70 px-3 py-2 text-xs dark:border-gray-800 dark:bg-gray-900/40">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-gray-600 dark:text-gray-300">
                    <span>
                        <strong class="font-bold tabular-nums text-gray-950 dark:text-white">{{ $details['total_active_proposals'] }}</strong>
                        {{ $details['total_active_proposals'] === 1 ? 'processo ativo' : 'processos ativos' }}
                    </span>
                    <span class="text-gray-300 dark:text-gray-700">&bull;</span>
                    <span>
                        <strong class="font-bold tabular-nums text-gray-950 dark:text-white">{{ $details['total_representatives'] }}</strong>
                        {{ $details['total_representatives'] === 1 ? 'responsável' : 'responsáveis' }}
                    </span>
                    <span class="text-gray-300 dark:text-gray-700">&bull;</span>
                    <span>
                        Média: <strong class="font-bold tabular-nums text-gray-950 dark:text-white">{{ number_format($details['average_load'], 1, ',', '.') }}</strong> por responsável
                    </span>
                </div>

                {{-- Badge de Avaliação de Equilíbrio --}}
                <div>
                    @php
                        $badgeBg = match ($details['balance_status']['badge_color']) {
                            'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200/80 dark:bg-emerald-500/15 dark:text-emerald-300 dark:border-emerald-500/30',
                            'info' => 'bg-blue-50 text-blue-700 border-blue-200/80 dark:bg-blue-500/15 dark:text-blue-300 dark:border-blue-500/30',
                            'warning' => 'bg-amber-50 text-amber-700 border-amber-200/80 dark:bg-amber-500/15 dark:text-amber-300 dark:border-amber-500/30',
                            default => 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700',
                        };
                    @endphp
                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[0.6875rem] font-semibold {{ $badgeBg }}">
                        @if($details['balance_status']['badge_color'] === 'success')
                            <x-heroicon-m-check-badge class="size-3.5 shrink-0" aria-hidden="true" />
                        @elseif($details['balance_status']['badge_color'] === 'warning')
                            <x-heroicon-m-exclamation-circle class="size-3.5 shrink-0" aria-hidden="true" />
                        @else
                            <x-heroicon-m-scale class="size-3.5 shrink-0" aria-hidden="true" />
                        @endif
                        <span>{{ $details['balance_status']['label'] }}</span>
                    </span>
                </div>
            </div>

            {{-- Lista Visual de Carga Operacional (Barras Horizontais) --}}
            <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                @foreach($details['items'] as $item)
                    <div class="group relative flex flex-col justify-between rounded-xl border border-gray-200/80 bg-bsi-paper p-3 transition-all duration-150 hover:border-gray-300 hover:shadow-sm dark:border-gray-800 dark:bg-gray-900/60 dark:hover:border-gray-700">
                        <div class="flex items-start justify-between gap-2.5">
                            <div class="flex items-center gap-2.5 min-w-0">
                                {{-- Avatar / Iniciais --}}
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-bsi-navy-900 text-xs font-bold text-bsi-paper shadow-sm dark:bg-bsi-navy-800 dark:text-bsi-gold-500">
                                    {{ strtoupper(substr($item['name'], 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <span class="block truncate text-sm font-semibold text-gray-950 dark:text-white" title="{{ $item['name'] }}">
                                        {{ $item['name'] }}
                                    </span>
                                    <span class="block text-[0.6875rem] text-gray-500 dark:text-gray-400">
                                        @if(filled($item['queue_position']))
                                            Fila #{{ $item['queue_position'] }} &bull;
                                        @endif
                                        {{ $item['email'] }}
                                    </span>
                                </div>
                            </div>

                            <div class="shrink-0 text-right">
                                <span class="block text-sm font-bold tabular-nums text-gray-950 dark:text-white">
                                    {{ $item['count'] }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $item['count'] === 1 ? 'processo' : 'processos' }}</span>
                                </span>
                                <span class="block text-[0.6875rem]">
                                    @if($item['count'] > 0)
                                        <span class="font-medium text-gray-500 dark:text-gray-400">{{ $item['percentage'] }}% da carga</span>
                                    @else
                                        <span class="font-medium text-emerald-600 dark:text-emerald-400">Disponível</span>
                                    @endif
                                </span>
                            </div>
                        </div>

                        {{-- Barra Proporcional de Carga --}}
                        <div class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div
                                class="h-full rounded-full transition-all duration-300 {{ $item['count'] > 0 ? 'bg-bsi-navy-900 dark:bg-bsi-gold-500' : 'bg-gray-200 dark:bg-gray-700' }}"
                                style="width: {{ $details['total_active_proposals'] > 0 ? max($item['percentage'], ($item['count'] > 0 ? 8 : 0)) : 0 }}%;"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

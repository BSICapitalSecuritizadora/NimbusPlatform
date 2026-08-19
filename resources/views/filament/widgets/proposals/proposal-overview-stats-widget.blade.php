@php
    $heading = $this->getHeading();
    $description = $this->getDescription();
@endphp

<x-filament-widgets::widget class="bsi-cockpit-widget bsi-proposal-overview-widget">
    <x-filament::section
        :heading="$heading"
        :description="$description"
        icon="heroicon-o-presentation-chart-line"
        icon-color="primary"
    >
        <x-slot name="afterHeader">
            {{-- Faixa de Síntese Executiva no Cabeçalho --}}
            <div class="hidden sm:flex flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200/80 bg-bsi-paper px-2.5 py-0.5 font-medium text-gray-700 dark:border-gray-700/80 dark:bg-gray-800/80 dark:text-gray-300">
                    <span class="size-1.5 rounded-full bg-bsi-gold-500"></span>
                    <span><strong class="font-semibold text-gray-900 dark:text-white">{{ $summary['total'] }}</strong> na carteira</span>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200/80 bg-bsi-paper px-2.5 py-0.5 font-medium text-gray-700 dark:border-gray-700/80 dark:bg-gray-800/80 dark:text-gray-300">
                    <span class="size-1.5 rounded-full bg-info-500"></span>
                    <span><strong class="font-semibold text-gray-900 dark:text-white">{{ $summary['active_pipeline'] }}</strong> em fluxo</span>
                </span>
                @if($summary['attention'] > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-300/80 bg-amber-50 px-2.5 py-0.5 font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-200">
                        <span class="size-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                        <span><strong class="font-semibold">{{ $summary['attention'] }}</strong> em atenção</span>
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-300/80 bg-emerald-50 px-2.5 py-0.5 font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-200">
                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                        <span>SLAs regulares</span>
                    </span>
                @endif
            </div>
        </x-slot>

        {{-- Grid de Indicadores Executivos --}}
        <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            @foreach($cards as $card)
                @php
                    $toneClasses = match ($card['tone']) {
                        'primary' => [
                            'bar' => 'bg-bsi-gold-500',
                            'border' => 'border-gray-200/90 dark:border-white/10',
                            'icon' => 'bg-bsi-navy-900/10 text-bsi-navy-900 dark:bg-bsi-gold-500/20 dark:text-bsi-gold-500',
                            'value' => 'text-gray-950 dark:text-white',
                            'desc' => 'text-gray-600 dark:text-gray-300',
                        ],
                        'info' => [
                            'bar' => 'bg-info-500',
                            'border' => 'border-gray-200/90 dark:border-white/10',
                            'icon' => 'bg-info-500/10 text-info-700 dark:bg-info-500/20 dark:text-info-300',
                            'value' => 'text-gray-950 dark:text-white',
                            'desc' => 'text-gray-600 dark:text-gray-300',
                        ],
                        'success' => [
                            'bar' => 'bg-emerald-500',
                            'border' => 'border-gray-200/90 dark:border-white/10',
                            'icon' => 'bg-emerald-500/10 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
                            'value' => 'text-emerald-700 dark:text-emerald-400',
                            'desc' => 'text-gray-600 dark:text-gray-300',
                        ],
                        'warning' => [
                            'bar' => 'bg-amber-500',
                            'border' => 'border-amber-300/70 dark:border-amber-500/30',
                            'icon' => 'bg-amber-500/15 text-amber-800 dark:bg-amber-500/25 dark:text-amber-200',
                            'value' => 'text-amber-800 dark:text-amber-200',
                            'desc' => 'text-amber-900/90 dark:text-amber-200/90 font-medium',
                        ],
                        'danger' => [
                            'bar' => 'bg-danger-500',
                            'border' => 'border-danger-300/70 dark:border-danger-500/30',
                            'icon' => 'bg-danger-500/15 text-danger-700 dark:bg-danger-500/25 dark:text-danger-300',
                            'value' => 'text-danger-700 dark:text-danger-300',
                            'desc' => 'text-danger-900/90 dark:text-danger-200/90 font-medium',
                        ],
                        default => [
                            'bar' => 'bg-gray-300/80 dark:bg-white/10',
                            'border' => 'border-gray-200/90 dark:border-white/10',
                            'icon' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
                            'value' => 'text-gray-950 dark:text-white',
                            'desc' => 'text-gray-500 dark:text-gray-400',
                        ],
                    };
                @endphp

                <a
                    href="{{ $card['url'] }}"
                    aria-label="{{ $card['aria_label'] }}"
                    class="bsi-proposal-stat-card group relative flex flex-col justify-between overflow-hidden rounded-xl border {{ $toneClasses['border'] }} bg-bsi-paper p-4 shadow-sm transition-[border-color,background-color,box-shadow,transform] duration-200 ease-out hover:-translate-y-0.5 hover:border-bsi-gold-500/60 hover:bg-white hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-bsi-gold-600 motion-reduce:transition-none motion-reduce:hover:translate-y-0 dark:bg-[#0d252e] dark:hover:border-bsi-gold-500/50 dark:hover:bg-[#12313b]"
                >
                    {{-- Filete Superior Institucional / Semântico --}}
                    <div class="absolute inset-x-0 top-0 h-[2.5px] {{ $toneClasses['bar'] }}"></div>

                    {{-- Topo do Card: Ícone + Categoria + Título + Seta Hover --}}
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl {{ $toneClasses['icon'] }} transition-transform duration-200 group-hover:scale-105">
                                <x-dynamic-component :component="$card['icon']" class="size-4.5" aria-hidden="true" />
                            </span>

                            <x-heroicon-m-arrow-up-right class="size-3.5 shrink-0 text-gray-400 opacity-0 transition-[opacity,transform] duration-200 group-hover:opacity-100 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 dark:text-gray-500" aria-hidden="true" />
                        </div>

                        <div class="mt-2.5 min-w-0">
                            <span class="block text-[0.6875rem] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ $card['category'] }}
                            </span>
                            <h3 class="mt-0.5 block truncate text-sm font-semibold text-gray-950 dark:text-white" title="{{ $card['title'] }}">
                                {{ $card['title'] }}
                            </h3>
                        </div>
                    </div>

                    {{-- Meio: Número Principal em Alto Contraste --}}
                    <div class="my-3">
                        <span class="block text-2xl sm:text-3xl font-bold tracking-tight tabular-nums {{ $toneClasses['value'] }}">
                            {{ $card['value'] }}
                        </span>
                    </div>

                    {{-- Rodapé: Contexto Auxiliar com Respiro Adequado --}}
                    <div class="border-t border-gray-100 pt-2.5 dark:border-white/5">
                        <p class="text-xs leading-relaxed {{ $toneClasses['desc'] }}">
                            {{ $card['description'] }}
                        </p>
                    </div>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

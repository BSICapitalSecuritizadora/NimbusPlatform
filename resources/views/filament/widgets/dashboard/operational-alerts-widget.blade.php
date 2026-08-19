<x-filament-widgets::widget class="bsi-cockpit-widget bsi-cockpit-alerts">
    <x-filament::section
        heading="Alertas operacionais"
        description="Situações que pedem acompanhamento ou decisão."
        icon="heroicon-o-bell-alert"
        icon-color="warning"
        collapsible
        persist-collapsed
        collapse-id="cockpit-operational-alerts"
    >
        @if($alerts->isEmpty())
            <div class="flex items-start gap-3 py-1">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300">
                    <x-heroicon-o-check-circle class="size-5" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">Nenhum alerta operacional no momento.</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-gray-600 dark:text-gray-300">Todas as situações monitoradas estão dentro do esperado.</span>
                </span>
            </div>
        @else
            @php
                $alertCount = $alerts->count();
            @endphp

            <div
                @class([
                    'grid grid-cols-1 gap-3',
                    'md:grid-cols-2' => $alertCount > 1,
                    'xl:grid-cols-3' => $alertCount === 3,
                    'xl:grid-cols-4' => $alertCount >= 4,
                ])
                data-alert-count="{{ $alertCount }}"
            >
                @foreach($alerts as $alert)
                    @php
                        $toneSurfaceClasses = match ($alert['tone']) {
                            'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
                            'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
                            'info' => 'bg-info-100 text-info-800 dark:bg-info-500/20 dark:text-info-200',
                            default => 'bg-gray-100 text-gray-700 dark:bg-gray-500/15 dark:text-gray-300',
                        };
                        $toneTextClasses = match ($alert['tone']) {
                            'danger' => 'text-danger-700 dark:text-danger-300',
                            'warning' => 'text-amber-800 dark:text-amber-200',
                            'info' => 'text-info-800 dark:text-info-200',
                            default => 'text-gray-700 dark:text-gray-300',
                        };
                        $isSingleAlert = $alertCount === 1;
                    @endphp

                    @if ($alert['url'])
                        <a
                            href="{{ $alert['url'] }}"
                            wire:key="operational-alert-{{ $alert['key'] }}"
                            @class([
                                'bsi-operational-alert group grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 rounded-xl border border-gray-200 bg-bsi-paper p-3.5 transition-[border-color,background-color] duration-200 ease-out hover:border-primary-400 hover:bg-primary-50/50 focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-primary-600 motion-reduce:transition-none dark:border-gray-700 dark:bg-gray-900 dark:hover:border-primary-500 dark:hover:bg-primary-500/5',
                                'md:grid-cols-[auto_minmax(0,1fr)_auto] md:items-center md:px-4' => $isSingleAlert,
                            ])
                            aria-label="Abrir alerta {{ $alert['severity'] }}: {{ $alert['count'] }} {{ $alert['title'] }}"
                        >
                    @else
                        <div
                            wire:key="operational-alert-{{ $alert['key'] }}"
                            @class([
                                'bsi-operational-alert grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 rounded-xl border border-gray-200 bg-bsi-paper p-3.5 dark:border-gray-700 dark:bg-gray-900',
                                'md:grid-cols-[auto_minmax(0,1fr)_auto] md:items-center md:px-4' => $isSingleAlert,
                            ])
                        >
                    @endif
                            <span class="flex h-12 min-w-12 shrink-0 items-center justify-center rounded-xl px-2 {{ $toneSurfaceClasses }}">
                                <strong class="text-2xl font-semibold leading-none tabular-nums">{{ $alert['count'] }}</strong>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold {{ $toneTextClasses }}">
                                    <x-dynamic-component :component="$alert['icon']" class="size-3.5 shrink-0" aria-hidden="true" />
                                    {{ $alert['severity'] }}
                                </span>
                                <span class="mt-0.5 block text-sm font-semibold leading-snug text-gray-950 dark:text-white">{{ $alert['title'] }}</span>
                                <span class="mt-1 block text-xs leading-relaxed text-gray-600 dark:text-gray-300">{{ $alert['description'] }}</span>
                            </span>

                            @if ($alert['url'])
                                <span
                                    @class([
                                        'col-start-2 inline-flex items-center gap-1 text-xs font-semibold text-primary-700 dark:text-primary-300',
                                        'md:col-start-3 md:row-start-1 md:justify-self-end' => $isSingleAlert,
                                    ])
                                >
                                    {{ $alert['action'] }}
                                    <x-heroicon-m-arrow-right class="size-3.5 shrink-0 transition-transform duration-200 ease-out group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true" />
                                </span>
                            @endif
                    @if ($alert['url'])
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

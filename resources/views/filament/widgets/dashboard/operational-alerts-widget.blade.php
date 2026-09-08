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
            <div
                class="grid grid-cols-1 gap-2"
                data-alert-count="{{ $alerts->count() }}"
            >
                @foreach($alerts as $alert)
                    @php
                        $toneSurfaceClasses = match ($alert['tone']) {
                            'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
                            'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
                            'info' => 'bg-info-100 text-info-800 dark:bg-info-500/20 dark:text-info-200',
                            'success' => 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-300',
                            default => 'bg-gray-100 text-gray-700 dark:bg-gray-500/15 dark:text-gray-300',
                        };
                        $toneBadgeClasses = match ($alert['tone']) {
                            'danger' => 'border-danger-200/60 bg-danger-50 text-danger-700 dark:border-danger-500/25 dark:bg-danger-500/10 dark:text-danger-300',
                            'warning' => 'border-amber-200/60 bg-amber-50 text-amber-800 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-200',
                            'info' => 'border-info-200/60 bg-info-50 text-info-800 dark:border-info-500/25 dark:bg-info-500/10 dark:text-info-200',
                            'success' => 'border-success-200/60 bg-success-50 text-success-700 dark:border-success-500/25 dark:bg-success-500/10 dark:text-success-300',
                            default => 'border-gray-200/70 bg-gray-100 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300',
                        };
                        $toneDotClasses = match ($alert['tone']) {
                            'danger' => 'bg-danger-500',
                            'warning' => 'bg-amber-500',
                            'info' => 'bg-info-500',
                            'success' => 'bg-success-500',
                            default => 'bg-gray-400 dark:bg-gray-500',
                        };
                    @endphp

                    @if ($alert['url'])
                        <a
                            href="{{ $alert['url'] }}"
                            wire:key="operational-alert-{{ $alert['key'] }}"
                            class="bsi-operational-alert group flex flex-col gap-1 rounded-xl border border-gray-200 bg-white px-3 py-2.5 transition-[border-color,background-color] duration-200 ease-out hover:border-bsi-gold-500/60 focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-primary-600 motion-reduce:transition-none sm:px-3.5 dark:border-bsi-gold-500/15 dark:bg-[#091f28]/75 dark:hover:border-bsi-gold-500/40 dark:hover:bg-[#0e2c38]"
                            aria-label="Abrir alerta {{ $alert['severity'] }}: {{ $alert['count'] }} {{ $alert['title'] }}"
                        >
                    @else
                        <div
                            wire:key="operational-alert-{{ $alert['key'] }}"
                            class="bsi-operational-alert flex flex-col gap-1 rounded-xl border border-gray-200 bg-white px-3 py-2.5 sm:px-3.5 dark:border-bsi-gold-500/15 dark:bg-[#091f28]/75"
                        >
                    @endif
                            <span class="flex items-start gap-3">
                                <span class="flex h-9 min-w-9 shrink-0 items-center justify-center rounded-lg px-1.5 text-sm font-semibold tabular-nums {{ $toneSurfaceClasses }}">
                                    {{ $alert['count'] }}
                                </span>

                                <span class="min-w-0 flex-1 break-words">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="text-sm font-semibold leading-snug text-gray-950 dark:text-white">{{ $alert['title'] }}</span>
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-px text-[0.6875rem] font-semibold leading-4 {{ $toneBadgeClasses }}">
                                            <span aria-hidden="true" class="size-1.5 rounded-full {{ $toneDotClasses }}"></span>
                                            {{ $alert['severity'] }}
                                        </span>
                                    </span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-gray-600 dark:text-gray-300">{{ $alert['description'] }}</span>
                                </span>

                                @if ($alert['url'])
                                    <span class="mt-0.5 hidden shrink-0 items-center gap-1 rounded-md px-2 py-1.5 text-xs font-semibold text-gray-500 transition-colors group-hover:text-bsi-gold-600 md:inline-flex dark:text-gray-400 dark:group-hover:text-bsi-gold-500">
                                        {{ $alert['action'] }}
                                        <x-heroicon-m-arrow-right class="size-3.5 shrink-0 transition-transform duration-200 ease-out group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true" />
                                    </span>
                                @endif
                            </span>

                            @if ($alert['url'])
                                <span class="inline-flex items-center gap-1 self-start rounded-md py-1 ps-12 text-xs font-semibold text-gray-500 transition-colors group-hover:text-bsi-gold-600 md:hidden dark:text-gray-400 dark:group-hover:text-bsi-gold-500">
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

<x-filament-widgets::widget class="bsi-cockpit-widget bsi-cockpit-deadlines">
    <x-filament::section
        heading="Prazos e vencimentos"
        description="Obrigações agrupadas por urgência para facilitar a priorização."
        icon="heroicon-o-calendar-days"
        icon-color="primary"
        collapsible
        persist-collapsed
        collapse-id="cockpit-deadlines"
    >
        @if(! $canViewObligations)
            <div class="flex items-start gap-3 text-sm text-gray-600 dark:text-gray-300">
                <x-heroicon-o-lock-closed class="mt-0.5 size-5 shrink-0 text-gray-500" aria-hidden="true" />
                <p>Você não tem permissão para visualizar obrigações.</p>
            </div>
        @else
            @if($activeUrgentGroups->isEmpty())
                <div
                    data-deadline-health="clear"
                    class="grid gap-4 border-b border-gray-200/80 pb-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center dark:border-white/10"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300">
                            <x-heroicon-o-check-circle class="size-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $totalCount === 0 ? 'Nenhuma obrigação em aberto' : 'Nenhum vencimento crítico nos próximos 7 dias' }}
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">
                                {{ $totalCount === 0 ? 'Não há itens que exijam acompanhamento de prazo.' : 'Os itens sem prazo permanecem visíveis abaixo, sem competir com alertas de vencimento.' }}
                            </p>
                        </div>
                    </div>

                    <dl @class([
                        'grid grid-cols-2 gap-x-4 gap-y-2 text-xs sm:grid-cols-4',
                        'lg:grid-cols-5' => $totalCount === 0,
                    ]) aria-label="Resumo das janelas de vencimento">
                        @foreach($urgentGroups as $group)
                            <div class="flex items-center justify-between gap-2 sm:block">
                                <dt class="text-gray-500 dark:text-gray-400">{{ $group['title'] }}</dt>
                                <dd class="font-semibold tabular-nums text-gray-700 sm:mt-0.5 dark:text-gray-200">{{ $group['count'] }}</dd>
                            </div>
                        @endforeach
                        @if($totalCount === 0)
                            <div class="col-span-2 flex items-center justify-between gap-2 sm:col-span-1 sm:block">
                                <dt class="text-gray-500 dark:text-gray-400">Sem prazo</dt>
                                <dd class="font-semibold tabular-nums text-gray-700 sm:mt-0.5 dark:text-gray-200">0</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @elseif($emptyGroups->isNotEmpty())
                <div
                    data-deadline-zero-summary
                    class="grid grid-cols-2 gap-2 border-b border-gray-200/80 pb-4 sm:flex sm:flex-wrap dark:border-white/10"
                    aria-label="Janelas sem vencimentos"
                >
                    @foreach($emptyGroups as $group)
                        <div class="flex min-h-10 items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/4">
                            <x-heroicon-m-check-circle class="size-4 shrink-0 text-success-600 dark:text-success-300" aria-hidden="true" />
                            <span class="min-w-0 truncate text-xs font-medium text-gray-600 dark:text-gray-300">{{ $group['title'] }}</span>
                            <span class="ml-auto text-xs font-semibold tabular-nums text-gray-500 dark:text-gray-400">0</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($activeUrgentGroups->isNotEmpty())
                <div
                    @class([
                        'mt-4 grid items-start gap-4',
                        'md:grid-cols-2' => $activeUrgentGroups->count() >= 2,
                        'xl:grid-cols-3' => $activeUrgentGroups->count() === 3,
                        'xl:grid-cols-4' => $activeUrgentGroups->count() >= 4,
                    ])
                    data-active-deadline-groups="{{ $activeUrgentGroups->count() }}"
                >
                    @foreach($activeUrgentGroups as $group)
                        @php
                            $toneBorderClasses = match ($group['tone']) {
                                'danger' => 'border-t-danger-400 dark:border-t-danger-500',
                                'urgent' => 'border-t-orange-400 dark:border-t-orange-500',
                                'warning' => 'border-t-amber-400 dark:border-t-amber-500',
                                default => 'border-t-primary-400 dark:border-t-primary-500',
                            };
                            $toneBadgeClasses = match ($group['tone']) {
                                'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
                                'urgent' => 'bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-200',
                                'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
                                default => 'bg-primary-100 text-primary-800 dark:bg-primary-500/15 dark:text-primary-200',
                            };
                            $toneTextClasses = match ($group['tone']) {
                                'danger' => 'text-danger-700 dark:text-danger-300',
                                'urgent' => 'text-orange-700 dark:text-orange-300',
                                'warning' => 'text-amber-800 dark:text-amber-200',
                                default => 'text-primary-700 dark:text-primary-300',
                            };
                        @endphp

                        <section
                            class="self-start overflow-hidden rounded-xl border border-gray-200/90 bg-gray-50/50 {{ $toneBorderClasses }} dark:border-bsi-gold-500/15 dark:bg-[#071820]/70"
                            aria-labelledby="deadline-group-{{ $group['key'] }}"
                        >
                            <header class="flex items-start justify-between gap-3 border-b border-gray-200/80 bg-gray-100/40 px-3.5 py-3 dark:border-bsi-gold-500/12 dark:bg-[#05131a]/50">
                                <div class="flex min-w-0 items-start gap-2.5">
                                    <x-dynamic-component :component="$group['icon']" class="mt-0.5 size-4 shrink-0 {{ $toneTextClasses }}" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <h3 id="deadline-group-{{ $group['key'] }}" class="text-sm font-semibold text-gray-950 dark:text-white">{{ $group['title'] }}</h3>
                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $group['description'] }}</p>
                                    </div>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums {{ $toneBadgeClasses }}">
                                    {{ $group['count'] }}
                                </span>
                            </header>

                            <div class="p-2.5">
                                <ul
                                    @class([
                                        'grid gap-2.5',
                                        'sm:grid-cols-2 xl:grid-cols-3' => $activeUrgentGroups->count() === 1,
                                    ])
                                    aria-label="Obrigações em {{ mb_strtolower($group['title']) }}"
                                >
                                    @foreach($group['items'] as $item)
                                        <li wire:key="deadline-{{ $group['key'] }}-{{ $item['id'] }}" class="min-w-0">
                                            <a
                                                href="{{ $item['url'] }}"
                                                class="group/item flex min-h-24 flex-col justify-between rounded-lg border border-gray-200/90 bg-white p-3 shadow-xs transition-[border-color,background-color,box-shadow,transform] duration-200 ease-out hover:-translate-y-0.5 hover:border-bsi-gold-500/60 hover:bg-white hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 motion-reduce:transition-none motion-reduce:hover:translate-y-0 dark:border-bsi-gold-500/15 dark:bg-[#091f28]/75 dark:shadow-[0_1px_3px_rgba(4,14,19,0.25)] dark:hover:border-bsi-gold-500/50 dark:hover:bg-[#0e2c38] dark:hover:shadow-[0_4px_14px_rgba(4,14,19,0.4)]"
                                                aria-label="Abrir obrigação: {{ $item['title'] }}"
                                            >
                                                <div>
                                                    <div class="flex items-start justify-between gap-1.5">
                                                        <span class="line-clamp-2 text-sm font-semibold leading-snug text-gray-950 dark:text-white" title="{{ $item['title'] }}">{{ $item['title'] }}</span>
                                                        <x-heroicon-o-chevron-right class="mt-0.5 size-4 shrink-0 text-gray-400 transition-[color,transform] duration-200 ease-out group-hover/item:translate-x-0.5 group-hover/item:text-bsi-gold-500 motion-reduce:transition-none dark:text-gray-500 dark:group-hover/item:text-bsi-gold-400" aria-hidden="true" />
                                                    </div>
                                                    <span class="mt-1.5 flex min-w-0 items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                                        <x-heroicon-m-building-office-2 class="size-3.5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                                                        <span class="truncate" title="{{ $item['operation'] }}">{{ $item['operation'] }}</span>
                                                    </span>
                                                </div>
                                                <div class="mt-2.5 flex flex-wrap items-center justify-between gap-1.5 border-t border-gray-100 pt-2 dark:border-white/6">
                                                    <span class="inline-flex items-center gap-1 text-xs font-medium {{ $toneTextClasses }}">
                                                        <x-heroicon-m-calendar-days class="size-3.5 shrink-0" aria-hidden="true" />
                                                        {{ $item['deadline'] }}
                                                    </span>
                                                    <span @class([
                                                        'rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium tracking-wide',
                                                        'bg-danger-50 text-danger-700 border border-danger-200/60 dark:bg-danger-500/12 dark:text-danger-300 dark:border-danger-500/25' => $item['priorityTone'] === 'danger',
                                                        'bg-amber-50 text-amber-800 border border-amber-200/60 dark:bg-amber-500/12 dark:text-amber-300 dark:border-amber-500/25' => $item['priorityTone'] === 'warning',
                                                        'bg-gray-100 text-gray-600 border border-gray-200/60 dark:bg-white/6 dark:text-gray-300 dark:border-white/8' => $item['priorityTone'] === 'neutral',
                                                    ])>
                                                        {{ $item['priority'] }}
                                                    </span>
                                                </div>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            @if($group['count'] > 5 && $group['dashboardUrl'])
                                <div class="border-t border-gray-200/80 bg-gray-100/30 px-3.5 py-2.5 dark:border-bsi-gold-500/12 dark:bg-[#05131a]/40">
                                    <x-filament::link
                                        :href="$group['dashboardUrl']"
                                        icon="heroicon-m-arrow-right"
                                        icon-position="after"
                                        size="sm"
                                    >
                                        {{ $group['actionLabel'] }}
                                    </x-filament::link>
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>
            @endif

            @if($withoutDeadlineGroup['count'] > 0)
                <section
                    data-without-deadline-count="{{ $withoutDeadlineGroup['count'] }}"
                    class="mt-4 overflow-hidden rounded-xl border border-gray-200/90 bg-gray-50/50 dark:border-bsi-gold-500/15 dark:bg-[#071820]/70"
                    aria-labelledby="deadline-group-without-deadline"
                >
                    <header class="flex flex-col gap-3 border-b border-gray-200/80 bg-gray-100/40 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-bsi-gold-500/12 dark:bg-[#05131a]/50">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-gray-200/80 bg-white text-gray-700 shadow-xs dark:border-bsi-gold-500/20 dark:bg-bsi-navy-900/80 dark:text-bsi-gold-400">
                                <x-heroicon-o-calendar-date-range class="size-4" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 id="deadline-group-without-deadline" class="text-sm font-semibold text-gray-950 dark:text-white">Sem prazo</h3>
                                    <span class="rounded-full border border-gray-200/80 bg-white px-2.5 py-0.5 text-xs font-semibold tabular-nums text-gray-800 shadow-xs dark:border-bsi-gold-500/20 dark:bg-bsi-navy-900/90 dark:text-gray-200">
                                        {{ $withoutDeadlineGroup['count'] }}
                                    </span>
                                </div>
                                <p class="mt-0.5 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $withoutDeadlineGroup['description'] }}</p>
                            </div>
                        </div>

                        @if($withoutDeadlineGroup['count'] > 5 && $withoutDeadlineGroup['dashboardUrl'])
                            <x-filament::link
                                :href="$withoutDeadlineGroup['dashboardUrl']"
                                icon="heroicon-m-arrow-right"
                                icon-position="after"
                                size="sm"
                                aria-label="Ver todas as {{ $withoutDeadlineGroup['count'] }} obrigações sem prazo"
                            >
                                {{ $withoutDeadlineGroup['actionLabel'] }}
                            </x-filament::link>
                        @endif
                    </header>

                    <div class="p-3">
                        <ul class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" aria-label="Prévia das obrigações sem prazo">
                            @foreach($withoutDeadlineGroup['items'] as $item)
                                <li wire:key="deadline-without-deadline-{{ $item['id'] }}" class="min-w-0">
                                    <a
                                        href="{{ $item['url'] }}"
                                        class="group/item flex min-h-24 flex-col justify-between rounded-lg border border-gray-200/90 bg-white p-3 shadow-xs transition-[border-color,background-color,box-shadow,transform] duration-200 ease-out hover:-translate-y-0.5 hover:border-bsi-gold-500/60 hover:bg-white hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 motion-reduce:transition-none motion-reduce:hover:translate-y-0 dark:border-bsi-gold-500/15 dark:bg-[#091f28]/75 dark:shadow-[0_1px_3px_rgba(4,14,19,0.25)] dark:hover:border-bsi-gold-500/50 dark:hover:bg-[#0e2c38] dark:hover:shadow-[0_4px_14px_rgba(4,14,19,0.4)]"
                                        aria-label="Abrir obrigação: {{ $item['title'] }}"
                                    >
                                        <div>
                                            <div class="flex items-start justify-between gap-1.5">
                                                <span class="line-clamp-2 text-sm font-semibold leading-snug text-gray-950 dark:text-white" title="{{ $item['title'] }}">{{ $item['title'] }}</span>
                                                <x-heroicon-o-chevron-right class="mt-0.5 size-4 shrink-0 text-gray-400 transition-[color,transform] duration-200 ease-out group-hover/item:translate-x-0.5 group-hover/item:text-bsi-gold-500 motion-reduce:transition-none dark:text-gray-500 dark:group-hover/item:text-bsi-gold-400" aria-hidden="true" />
                                            </div>
                                            <span class="mt-1.5 flex min-w-0 items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                                <x-heroicon-m-building-office-2 class="size-3.5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                                                <span class="truncate" title="{{ $item['operation'] }}">{{ $item['operation'] }}</span>
                                            </span>
                                        </div>

                                        <div class="mt-2.5 flex flex-wrap items-center justify-between gap-1.5 border-t border-gray-100 pt-2 dark:border-white/6">
                                            <span class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                                <x-heroicon-m-calendar-days class="size-3.5 shrink-0" aria-hidden="true" />
                                                {{ $item['deadline'] }}
                                            </span>
                                            <span @class([
                                                'rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium tracking-wide',
                                                'bg-danger-50 text-danger-700 border border-danger-200/60 dark:bg-danger-500/12 dark:text-danger-300 dark:border-danger-500/25' => $item['priorityTone'] === 'danger',
                                                'bg-amber-50 text-amber-800 border border-amber-200/60 dark:bg-amber-500/12 dark:text-amber-300 dark:border-amber-500/25' => $item['priorityTone'] === 'warning',
                                                'bg-gray-100 text-gray-600 border border-gray-200/60 dark:bg-white/6 dark:text-gray-300 dark:border-white/8' => $item['priorityTone'] === 'neutral',
                                            ])>
                                                {{ $item['priority'] }}
                                            </span>
                                        </div>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

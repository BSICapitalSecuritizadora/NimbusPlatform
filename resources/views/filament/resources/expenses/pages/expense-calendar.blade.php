<x-filament-panels::page>
    @php
        $calendar = $this->getCalendarData();
        $weekdayLabels = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
        $emissionOptions = $this->getEmissionOptions();
        $categoryOptions = $this->getCategoryOptions();
        $hasActiveFilters = $this->hasActiveFilters();
        $selectedDay = $this->selectedDate !== null
            ? collect($calendar['weeks'])->flatten(1)->firstWhere('date', $this->selectedDate)
            : null;

        $eventTooltip = fn (array $event): string => implode(' — ', array_filter([
            $event['category'].' · '.$event['amount_label'],
            $event['operation'],
            'Prestador: '.$event['service_provider'],
            'Vencimento: '.\Carbon\CarbonImmutable::parse($event['date'])->format('d/m/Y'),
            $event['period_label'],
            $event['is_overdue'] ? 'Vencida' : null,
        ]));
    @endphp

    <div class="space-y-6">
        <section class="overflow-hidden rounded-3xl border border-slate-400/15 bg-[#0d252e] shadow-2xl shadow-black/25">
            <div class="flex flex-col gap-5 border-b border-slate-400/15 px-6 py-6 sm:px-8 xl:flex-row xl:items-center xl:justify-between">
                <div class="space-y-1.5">
                    <span class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">Despesas</span>
                    <h2 class="text-3xl font-semibold tracking-tight text-white">{{ $calendar['month_label'] }}</h2>
                </div>

                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <button
                        type="button"
                        wire:click="previousMonth"
                        aria-label="Mês anterior"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-400/20 bg-bsi-navy-900/60 text-slate-300 transition hover:border-bsi-gold-500/50 hover:text-white"
                    >
                        <x-filament::icon icon="heroicon-o-chevron-left" class="h-5 w-5" />
                    </button>

                    <label class="sr-only" for="expense-calendar-month">Selecionar competência</label>
                    <input
                        id="expense-calendar-month"
                        type="month"
                        wire:model.live="visibleMonth"
                        value="{{ $calendar['visible_month'] }}"
                        class="h-11 rounded-xl border border-slate-400/20 bg-bsi-navy-900/60 px-4 text-sm font-medium text-white transition [color-scheme:dark] focus:border-bsi-gold-500 focus:outline-none focus:ring-2 focus:ring-bsi-gold-500/30"
                    >

                    <button
                        type="button"
                        wire:click="nextMonth"
                        aria-label="Próximo mês"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-400/20 bg-bsi-navy-900/60 text-slate-300 transition hover:border-bsi-gold-500/50 hover:text-white"
                    >
                        <x-filament::icon icon="heroicon-o-chevron-right" class="h-5 w-5" />
                    </button>

                    <button
                        type="button"
                        wire:click="currentMonth"
                        class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-bsi-gold-500/40 bg-bsi-gold-500/10 px-4 text-sm font-semibold text-[#d5aa67] transition hover:border-bsi-gold-500/70 hover:bg-bsi-gold-500/20"
                    >
                        <x-filament::icon icon="heroicon-o-calendar-days" class="h-4 w-4" />
                        <span>Hoje</span>
                    </button>
                </div>
            </div>

            <div class="px-6 py-6 sm:px-8">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-2xl border border-slate-400/15 bg-white/[0.03] p-5">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Eventos previstos</span>
                        <div class="mt-2 text-3xl font-semibold tabular-nums text-white">{{ $calendar['summary']['event_count'] }}</div>
                        <p class="mt-1.5 text-xs text-slate-400">Pagamentos agendados no mês exibido.</p>
                    </div>

                    <div class="rounded-2xl border border-slate-400/15 bg-white/[0.03] p-5">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Valor do mês</span>
                        <div class="mt-2 text-3xl font-semibold tabular-nums text-white">{{ $calendar['summary']['total_amount'] }}</div>
                        <p class="mt-1.5 text-xs text-slate-400">Soma das despesas previstas no período.</p>
                    </div>

                    <div class="rounded-2xl border border-slate-400/15 bg-white/[0.03] p-5">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Operações impactadas</span>
                        <div class="mt-2 text-3xl font-semibold tabular-nums text-white">{{ $calendar['summary']['operation_count'] }}</div>
                        <p class="mt-1.5 text-xs text-slate-400">Operações com pagamento previsto.</p>
                    </div>
                </div>

                <p class="mt-3 text-right text-[11px] text-slate-500">
                    {{ $hasActiveFilters ? 'Indicadores refletem os filtros aplicados.' : 'Indicadores referentes ao mês exibido.' }}
                </p>
            </div>

            <div class="border-t border-slate-400/15 px-6 py-6 sm:px-8">
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                    <label class="grid gap-2">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Operação</span>
                        <span class="group relative block">
                            <select
                                wire:model.live="selectedEmissionId"
                                class="h-11 w-full appearance-none rounded-xl border border-slate-400/20 bg-bsi-navy-900/60 pl-4 pr-12 text-sm leading-6 text-white transition [color-scheme:dark] focus:border-bsi-gold-500 focus:outline-none focus:ring-2 focus:ring-bsi-gold-500/30"
                            >
                                <option value="">Todas as operações</option>
                                @foreach ($emissionOptions as $emissionId => $emissionName)
                                    <option value="{{ $emissionId }}">{{ $emissionName }}</option>
                                @endforeach
                            </select>
                            <span class="pointer-events-none absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 transition duration-200 ease-out group-hover:text-slate-200 group-focus-within:rotate-180 group-focus-within:text-[#d5aa67]">
                                <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" />
                            </span>
                        </span>
                    </label>

                    <label class="grid gap-2">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Categoria</span>
                        <span class="group relative block">
                            <select
                                wire:model.live="selectedCategory"
                                class="h-11 w-full appearance-none rounded-xl border border-slate-400/20 bg-bsi-navy-900/60 pl-4 pr-12 text-sm leading-6 text-white transition [color-scheme:dark] focus:border-bsi-gold-500 focus:outline-none focus:ring-2 focus:ring-bsi-gold-500/30"
                            >
                                <option value="">Todas as categorias</option>
                                @foreach ($categoryOptions as $categoryValue => $categoryLabel)
                                    <option value="{{ $categoryValue }}">{{ $categoryLabel }}</option>
                                @endforeach
                            </select>
                            <span class="pointer-events-none absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 transition duration-200 ease-out group-hover:text-slate-200 group-focus-within:rotate-180 group-focus-within:text-[#d5aa67]">
                                <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" />
                            </span>
                        </span>
                    </label>

                    <button
                        type="button"
                        wire:click="clearFilters"
                        @disabled(! $hasActiveFilters)
                        @class([
                            'inline-flex h-11 items-center justify-center gap-2 rounded-xl border px-4 text-sm font-medium transition',
                            'border-bsi-gold-500/40 bg-bsi-gold-500/10 text-[#d5aa67] hover:border-bsi-gold-500/70 hover:bg-bsi-gold-500/20' => $hasActiveFilters,
                            'cursor-not-allowed border-slate-400/15 bg-transparent text-slate-500 opacity-60' => ! $hasActiveFilters,
                        ])
                    >
                        <x-filament::icon icon="heroicon-o-funnel" class="h-4 w-4" />
                        <span>Limpar filtros</span>
                    </button>
                </div>
            </div>
        </section>

        <section class="hidden overflow-hidden rounded-3xl border border-slate-400/15 bg-[#0d252e] shadow-2xl shadow-black/25 lg:block">
            @if ($calendar['summary']['event_count'] === 0)
                <div class="flex items-center gap-3 border-b border-slate-400/15 px-6 py-4 sm:px-8">
                    <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5 text-slate-500" />
                    <p class="text-sm text-slate-400">
                        Nenhum pagamento previsto para {{ $calendar['month_label'] }}{{ $hasActiveFilters ? ' com os filtros aplicados' : '' }}.
                    </p>
                </div>
            @endif

            <div class="overflow-x-auto">
                <div class="min-w-[1080px]">
                    <div class="grid grid-cols-7 border-b border-slate-400/15 bg-white/[0.02]">
                        @foreach ($weekdayLabels as $weekdayLabel)
                            <div
                                @class([
                                    'px-4 py-3.5 text-[11px] font-semibold uppercase tracking-[0.18em]',
                                    'text-slate-400' => $loop->index < 5,
                                    'text-slate-500' => $loop->index >= 5,
                                ])
                            >
                                {{ $weekdayLabel }}
                            </div>
                        @endforeach
                    </div>

                    @foreach ($calendar['weeks'] as $week)
                        <div class="grid grid-cols-7">
                            @foreach ($week as $day)
                                <div
                                    wire:key="expense-calendar-day-{{ $day['date'] }}"
                                    @class([
                                        'min-h-44 border-b border-r border-slate-400/10 px-3 py-3 align-top',
                                        'bg-slate-400/[0.04]' => $day['is_current_month'] && $loop->index >= 5,
                                        'bg-white/[0.02]' => $day['is_current_month'] && $loop->index < 5,
                                        'bg-black/20' => ! $day['is_current_month'],
                                    ])
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span
                                            @class([
                                                'inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold',
                                                'bg-bsi-gold-500 text-bsi-navy-950' => $day['is_today'],
                                                'text-white' => ! $day['is_today'] && $day['is_current_month'],
                                                'text-slate-600' => ! $day['is_today'] && ! $day['is_current_month'],
                                            ])
                                        >
                                            {{ $day['day_number'] }}
                                        </span>
                                    </div>

                                    @if (count($day['events']) > 0)
                                        <div class="mt-2 space-y-1.5">
                                            @foreach (array_slice($day['events'], 0, 2) as $event)
                                                @php $eventTag = $event['url'] !== null ? 'a' : 'article'; @endphp

                                                <{{ $eventTag }}
                                                    wire:key="expense-calendar-event-{{ $event['id'] }}"
                                                    @if ($event['url'] !== null) href="{{ $event['url'] }}" @endif
                                                    title="{{ $eventTooltip($event) }}"
                                                    @class([
                                                        'block rounded-xl border border-slate-400/15 bg-bsi-navy-900/60 px-2.5 py-2 shadow-sm shadow-black/10 transition',
                                                        'hover:border-bsi-gold-500/50 hover:bg-bsi-navy-800/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-bsi-gold-500/60' => $event['url'] !== null,
                                                        'opacity-55' => ! $day['is_current_month'],
                                                    ])
                                                >
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="inline-flex min-w-0 items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                                            <span
                                                                @class([
                                                                    'h-1.5 w-1.5 shrink-0 rounded-full',
                                                                    'bg-red-400' => $event['is_overdue'],
                                                                    'bg-amber-400' => ! $event['is_overdue'] && $event['is_due_soon'],
                                                                    'bg-slate-500' => ! $event['is_overdue'] && ! $event['is_due_soon'],
                                                                ])
                                                            ></span>
                                                            <span class="truncate">{{ $event['category'] }}</span>
                                                        </span>

                                                        @if ($event['is_overdue'])
                                                            <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wider text-red-300">Vencida</span>
                                                        @endif
                                                    </div>

                                                    <p class="mt-1 text-sm font-semibold tabular-nums text-white">{{ $event['amount_label'] }}</p>
                                                    <p class="mt-0.5 truncate text-[11px] text-slate-400">{{ $event['operation'] }}</p>
                                                </{{ $eventTag }}>
                                            @endforeach

                                            @if (count($day['events']) > 2)
                                                <button
                                                    type="button"
                                                    wire:click="openDay('{{ $day['date'] }}')"
                                                    class="inline-flex items-center gap-1 rounded-lg px-1.5 py-1 text-[11px] font-semibold text-[#d5aa67] transition hover:bg-bsi-gold-500/10 hover:text-bsi-gold-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-bsi-gold-500/60"
                                                >
                                                    +{{ count($day['events']) - 2 }} {{ count($day['events']) - 2 === 1 ? 'pagamento' : 'pagamentos' }}
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-slate-400/15 bg-[#0d252e] p-5 shadow-2xl shadow-black/25 lg:hidden">
            <div class="space-y-4">
                @forelse (collect($calendar['weeks'])->flatten(1)->filter(fn (array $day): bool => $day['is_current_month'] && count($day['events']) > 0) as $day)
                    <div wire:key="expense-calendar-mobile-{{ $day['date'] }}" class="rounded-2xl border border-slate-400/15 bg-white/[0.03] p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-white">{{ \Carbon\CarbonImmutable::parse($day['date'])->locale('pt_BR')->translatedFormat('d \d\e F') }}</h3>
                            <span class="text-xs text-slate-400">{{ count($day['events']) }} {{ count($day['events']) === 1 ? 'pagamento' : 'pagamentos' }}</span>
                        </div>

                        <div class="space-y-2">
                            @foreach ($day['events'] as $event)
                                @php $eventTag = $event['url'] !== null ? 'a' : 'article'; @endphp

                                <{{ $eventTag }}
                                    wire:key="expense-calendar-mobile-event-{{ $event['id'] }}"
                                    @if ($event['url'] !== null) href="{{ $event['url'] }}" @endif
                                    class="block rounded-xl border border-slate-400/15 bg-bsi-navy-900/60 p-3 transition hover:border-bsi-gold-500/50"
                                >
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="inline-flex min-w-0 items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-slate-400">
                                            <span
                                                @class([
                                                    'h-1.5 w-1.5 shrink-0 rounded-full',
                                                    'bg-red-400' => $event['is_overdue'],
                                                    'bg-amber-400' => ! $event['is_overdue'] && $event['is_due_soon'],
                                                    'bg-slate-500' => ! $event['is_overdue'] && ! $event['is_due_soon'],
                                                ])
                                            ></span>
                                            <span class="truncate">{{ $event['category'] }}</span>
                                        </span>

                                        <span class="shrink-0 text-sm font-semibold tabular-nums text-white">{{ $event['amount_label'] }}</span>
                                    </div>

                                    <p class="mt-1.5 truncate text-xs text-slate-400">{{ $event['operation'] }} · {{ $event['service_provider'] }}</p>

                                    @if ($event['is_overdue'])
                                        <p class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-red-300">Vencida</p>
                                    @endif
                                </{{ $eventTag }}>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-400/20 bg-white/[0.02] px-4 py-5 text-sm text-slate-400">
                        Nenhum pagamento previsto para o mês selecionado.
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    @if ($selectedDay !== null)
        <div
            x-data
            x-on:keydown.escape.window="$wire.closeDay()"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
        >
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" wire:click="closeDay"></div>

            <div
                role="dialog"
                aria-modal="true"
                aria-label="Pagamentos do dia"
                class="relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-400/20 bg-bsi-navy-900 shadow-2xl shadow-black/40"
            >
                <div class="flex items-start justify-between gap-4 border-b border-slate-400/15 px-6 py-5">
                    <div>
                        <h3 class="text-lg font-semibold text-white">
                            {{ ucfirst(\Carbon\CarbonImmutable::parse($selectedDay['date'])->locale('pt_BR')->translatedFormat('l, d \d\e F')) }}
                        </h3>
                        <p class="mt-1 text-xs text-slate-400">
                            {{ count($selectedDay['events']) }} {{ count($selectedDay['events']) === 1 ? 'pagamento previsto' : 'pagamentos previstos' }}
                            · Total de {{ 'R$ '.number_format(collect($selectedDay['events'])->sum('amount'), 2, ',', '.') }}
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeDay"
                        aria-label="Fechar"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-400/20 text-slate-400 transition hover:border-bsi-gold-500/50 hover:text-white"
                    >
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
                    </button>
                </div>

                <div class="space-y-2 overflow-y-auto px-6 py-5">
                    @foreach ($selectedDay['events'] as $event)
                        @php $eventTag = $event['url'] !== null ? 'a' : 'article'; @endphp

                        <{{ $eventTag }}
                            wire:key="expense-calendar-modal-event-{{ $event['id'] }}"
                            @if ($event['url'] !== null) href="{{ $event['url'] }}" @endif
                            @class([
                                'block rounded-xl border border-slate-400/15 bg-white/[0.03] p-4 transition',
                                'hover:border-bsi-gold-500/50 hover:bg-white/[0.05] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-bsi-gold-500/60' => $event['url'] !== null,
                            ])
                        >
                            <div class="flex items-center justify-between gap-3">
                                <span class="inline-flex min-w-0 items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-300">
                                    <span
                                        @class([
                                            'h-1.5 w-1.5 shrink-0 rounded-full',
                                            'bg-red-400' => $event['is_overdue'],
                                            'bg-amber-400' => ! $event['is_overdue'] && $event['is_due_soon'],
                                            'bg-slate-500' => ! $event['is_overdue'] && ! $event['is_due_soon'],
                                        ])
                                    ></span>
                                    <span class="truncate">{{ $event['category'] }}</span>
                                </span>

                                <span class="shrink-0 text-base font-semibold tabular-nums text-white">{{ $event['amount_label'] }}</span>
                            </div>

                            <p class="mt-1.5 text-sm text-slate-300">{{ $event['operation'] }}</p>
                            <p class="mt-1 text-xs text-slate-400">
                                Prestador: {{ $event['service_provider'] }} · {{ $event['period_label'] }}
                            </p>

                            @if ($event['is_overdue'])
                                <p class="mt-1.5 text-[10px] font-semibold uppercase tracking-wider text-red-300">Vencida</p>
                            @endif
                        </{{ $eventTag }}>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>

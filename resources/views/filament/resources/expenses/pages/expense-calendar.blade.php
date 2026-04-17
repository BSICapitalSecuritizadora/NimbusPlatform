<x-filament-panels::page>
    @php
        $calendar = $this->getCalendarData();
        $weekdayLabels = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
        $emissionOptions = $this->getEmissionOptions();
        $categoryOptions = $this->getCategoryOptions();
    @endphp

    <div class="space-y-6">
        <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/20">
            <div class="flex flex-col gap-5 border-b border-white/10 px-6 py-6 sm:px-8 xl:flex-row xl:items-center xl:justify-between">
                <div class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.22em] text-gray-400">Despesas</span>
                    <div>
                        <h2 class="text-2xl font-semibold text-white">{{ $calendar['month_label'] }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-6 text-gray-400">
                            Visualize os eventos previstos de pagamento a partir das despesas cadastradas e suas recorrências.
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                    <button
                        type="button"
                        wire:click="previousMonth"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-2.5 text-sm font-medium text-white transition hover:border-primary-400/40 hover:bg-primary-500/10"
                    >
                        <x-filament::icon icon="heroicon-o-chevron-left" class="h-4 w-4" />
                        <span>Anterior</span>
                    </button>

                    <label class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-2.5 text-sm text-gray-300">
                        <span class="font-medium text-white">Mês</span>
                        <input
                            type="month"
                            wire:model.live="visibleMonth"
                            class="rounded-xl border border-white/10 bg-gray-950/80 px-3 py-1.5 text-sm text-white focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-400/30"
                        >
                    </label>

                    <button
                        type="button"
                        wire:click="currentMonth"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border border-primary-400/30 bg-primary-500/10 px-4 py-2.5 text-sm font-medium text-primary-200 transition hover:border-primary-300/50 hover:bg-primary-500/20"
                    >
                        <x-filament::icon icon="heroicon-o-calendar-days" class="h-4 w-4" />
                        <span>Mês atual</span>
                    </button>

                    <button
                        type="button"
                        wire:click="nextMonth"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-2.5 text-sm font-medium text-white transition hover:border-primary-400/40 hover:bg-primary-500/10"
                    >
                        <span>Próximo</span>
                        <x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <div class="grid gap-4 px-6 py-6 sm:px-8 lg:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Eventos previstos</span>
                    <div class="mt-3 text-3xl font-semibold text-white">{{ $calendar['summary']['event_count'] }}</div>
                    <p class="mt-2 text-sm text-gray-400">Total de pagamentos agendados no mês selecionado.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Valor do mês</span>
                    <div class="mt-3 text-3xl font-semibold text-white">{{ $calendar['summary']['total_amount'] }}</div>
                    <p class="mt-2 text-sm text-gray-400">Soma das despesas previstas para o período exibido.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Operações impactadas</span>
                    <div class="mt-3 text-3xl font-semibold text-white">{{ $calendar['summary']['operation_count'] }}</div>
                    <p class="mt-2 text-sm text-gray-400">Quantidade de operações com pagamento previsto no mês.</p>
                </div>
            </div>

            <div class="border-t border-white/10 px-6 py-6 sm:px-8">
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                    <label class="grid gap-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Operação</span>
                        <select
                            wire:model.live="selectedEmissionId"
                            class="rounded-2xl border border-white/10 bg-gray-950/80 px-4 py-3 text-sm text-white transition focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-400/30"
                        >
                            <option value="">Todas as operações</option>
                            @foreach ($emissionOptions as $emissionId => $emissionName)
                                <option value="{{ $emissionId }}">{{ $emissionName }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="grid gap-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Categoria</span>
                        <select
                            wire:model.live="selectedCategory"
                            class="rounded-2xl border border-white/10 bg-gray-950/80 px-4 py-3 text-sm text-white transition focus:border-primary-400 focus:outline-none focus:ring-2 focus:ring-primary-400/30"
                        >
                            <option value="">Todas as categorias</option>
                            @foreach ($categoryOptions as $categoryValue => $categoryLabel)
                                <option value="{{ $categoryValue }}">{{ $categoryLabel }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-3 text-sm font-medium text-white transition hover:border-primary-400/40 hover:bg-primary-500/10"
                    >
                        <x-filament::icon icon="heroicon-o-funnel" class="h-4 w-4" />
                        <span>Limpar filtros</span>
                    </button>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/20">
            <div class="overflow-x-auto">
                <div class="min-w-[980px]">
                    <div class="grid grid-cols-7 border-b border-white/10 bg-white/[0.02]">
                        @foreach ($weekdayLabels as $weekdayLabel)
                            <div class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.2em] text-gray-400">
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
                                        'min-h-56 border-b border-r border-white/10 px-3 py-3 align-top',
                                        'bg-white/[0.02]' => $day['is_current_month'],
                                        'bg-black/10 opacity-60' => ! $day['is_current_month'],
                                    ])
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span
                                            @class([
                                                'inline-flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold',
                                                'bg-primary-500 text-gray-950' => $day['is_today'],
                                                'text-white' => ! $day['is_today'],
                                            ])
                                        >
                                            {{ $day['day_number'] }}
                                        </span>

                                        @if (count($day['events']) > 0)
                                            <span class="rounded-full border border-primary-400/20 bg-primary-500/10 px-2.5 py-1 text-[11px] font-semibold text-primary-200">
                                                {{ count($day['events']) }} pgto(s)
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-3 space-y-2">
                                        @forelse ($day['events'] as $event)
                                            <article
                                                wire:key="expense-calendar-event-{{ $event['id'] }}"
                                                class="rounded-2xl border border-amber-400/20 bg-amber-500/10 p-3 shadow-lg shadow-black/10"
                                            >
                                                <div class="flex items-start justify-between gap-3">
                                                    <p class="text-sm font-semibold leading-5 text-white">{{ $event['operation'] }}</p>
                                                    <span class="rounded-full bg-black/20 px-2 py-1 text-[11px] font-semibold text-amber-100">
                                                        {{ $event['amount_label'] }}
                                                    </span>
                                                </div>

                                                <p class="mt-2 text-xs font-medium text-amber-100">{{ $event['category'] }}</p>
                                                <p class="mt-1 text-xs leading-5 text-gray-400">{{ $event['service_provider'] }}</p>
                                                <p class="mt-2 text-[11px] uppercase tracking-[0.16em] text-gray-500">{{ $event['period_label'] }}</p>
                                            </article>
                                        @empty
                                            @if ($day['is_current_month'])
                                                <div class="rounded-2xl border border-dashed border-white/10 bg-black/10 px-3 py-4 text-xs text-gray-500">
                                                    Sem pagamentos previstos.
                                                </div>
                                            @endif
                                        @endforelse
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-gray-950/70 p-6 shadow-2xl shadow-black/20 lg:hidden">
            <div class="space-y-4">
                @forelse (collect($calendar['weeks'])->flatten(1)->filter(fn (array $day): bool => $day['is_current_month'] && count($day['events']) > 0) as $day)
                    <div wire:key="expense-calendar-mobile-{{ $day['date'] }}" class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-white">{{ \Carbon\CarbonImmutable::parse($day['date'])->locale('pt_BR')->translatedFormat('d \d\e F') }}</h3>
                            <span class="text-xs text-gray-400">{{ count($day['events']) }} evento(s)</span>
                        </div>

                        <div class="space-y-2">
                            @foreach ($day['events'] as $event)
                                <div wire:key="expense-calendar-mobile-event-{{ $event['id'] }}" class="rounded-2xl border border-amber-400/20 bg-amber-500/10 p-3">
                                    <p class="text-sm font-semibold text-white">{{ $event['operation'] }}</p>
                                    <p class="mt-1 text-xs text-amber-100">{{ $event['category'] }}</p>
                                    <p class="mt-1 text-xs text-gray-400">{{ $event['service_provider'] }}</p>
                                    <p class="mt-2 text-xs font-semibold text-white">{{ $event['amount_label'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.03] px-4 py-5 text-sm text-gray-400">
                        Nenhum pagamento previsto para o mês selecionado.
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-filament-panels::page>

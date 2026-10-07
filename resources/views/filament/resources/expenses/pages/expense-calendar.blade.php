<x-filament-panels::page>
    @php
        $calendar = $this->getCalendarData();
        $weekdayLabels = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
        $emissionOptions = $this->getEmissionOptions();
        $categoryOptions = $this->getCategoryOptions();
        $hasActiveFilters = $this->hasActiveFilters();
        $allEvents = collect($calendar['weeks'])->flatten(1)->flatMap(fn (array $day): array => $day['events']);
        $selectedDay = $this->selectedDate !== null
            ? collect($calendar['weeks'])->flatten(1)->firstWhere('date', $this->selectedDate)
            : null;
        $selectedEvent = $this->selectedEventId !== null
            ? $allEvents->firstWhere('id', $this->selectedEventId)
            : null;

        $eventTooltip = fn (array $event): string => implode(' — ', array_filter([
            $event['category'].' · '.$event['amount_label'],
            'Status: '.$event['status_label'],
            $event['operation'],
            'Prestador: '.$event['service_provider'],
            'Vencimento: '.$event['due_date_label'],
            $event['payment_date_label'] !== '—' ? 'Pago em: '.$event['payment_date_label'] : null,
            $event['period_label'],
        ]));
    @endphp

    <div class="space-y-6">
        <section class="overflow-hidden rounded-3xl border border-[var(--border-subtle)] bg-[var(--surface-card)] shadow-sm">
            <div class="flex flex-col gap-5 border-b border-[var(--border-subtle)] px-6 py-6 sm:px-8 xl:flex-row xl:items-center xl:justify-between">
                <div class="space-y-1.5">
                    <span class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[var(--text-muted)]">Despesas</span>
                    <h2 class="text-3xl font-semibold tracking-tight text-[var(--text-primary)]">{{ $calendar['month_label'] }}</h2>
                </div>

                <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                    <button
                        type="button"
                        wire:click="previousMonth"
                        aria-label="Mês anterior"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] text-[var(--text-secondary)] transition hover:border-[var(--accent)] hover:bg-[var(--surface-highlight)] hover:text-[var(--text-primary)]"
                    >
                        <x-filament::icon icon="heroicon-o-chevron-left" class="h-5 w-5" />
                    </button>

                    <label class="sr-only" for="expense-calendar-month">Selecionar competência</label>
                    <input
                        id="expense-calendar-month"
                        type="month"
                        wire:model.live="visibleMonth"
                        value="{{ $calendar['visible_month'] }}"
                        class="h-11 rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] px-4 text-sm font-medium text-[var(--text-primary)] transition focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/30"
                    >

                    <button
                        type="button"
                        wire:click="nextMonth"
                        aria-label="Próximo mês"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] text-[var(--text-secondary)] transition hover:border-[var(--accent)] hover:bg-[var(--surface-highlight)] hover:text-[var(--text-primary)]"
                    >
                        <x-filament::icon icon="heroicon-o-chevron-right" class="h-5 w-5" />
                    </button>

                    <button
                        type="button"
                        wire:click="currentMonth"
                        class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-[var(--accent)]/40 bg-[var(--accent-subtle)] px-4 text-sm font-semibold text-[var(--accent)] transition hover:border-[var(--accent)]/70 hover:bg-[var(--accent-subtle)]/80"
                    >
                        <x-filament::icon icon="heroicon-o-calendar-days" class="h-4 w-4" />
                        <span>Hoje</span>
                    </button>
                </div>
            </div>

            <div class="px-6 py-6 sm:px-8">
                <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
                    <div class="min-w-0 rounded-2xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] p-5">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[var(--text-muted)]">Eventos previstos</span>
                        <div class="mt-2 text-3xl font-semibold tabular-nums text-[var(--text-primary)]">{{ $calendar['summary']['event_count'] }}</div>
                        <p class="mt-1.5 text-xs text-[var(--text-secondary)]">Pagamentos agendados no mês exibido.</p>
                    </div>

                    <div class="min-w-0 rounded-2xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] p-5">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[var(--text-muted)]">Valor previsto</span>
                        <div class="mt-2 text-3xl font-semibold tabular-nums text-[var(--text-primary)]">{{ $calendar['summary']['total_amount'] }}</div>
                        <p class="mt-1.5 text-xs text-[var(--text-secondary)]">Soma das despesas previstas no período.</p>
                    </div>

                    <div class="min-w-0 rounded-2xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] p-5">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[var(--text-muted)]">Valor pago</span>
                        <div class="mt-2 text-3xl font-semibold tabular-nums text-[var(--text-primary)]">{{ $calendar['summary']['paid_amount'] }}</div>
                        @if ($calendar['summary']['paid_percentage'] !== null)
                            <p class="mt-1.5 text-xs text-[var(--text-secondary)]">
                                <span class="font-semibold tabular-nums text-[var(--accent)]">{{ $calendar['summary']['paid_percentage'] }}</span> do previsto
                            </p>
                            <p class="mt-0.5 text-xs tabular-nums text-[var(--text-muted)]">{{ $calendar['summary']['settlement_label'] }}</p>
                        @else
                            <p class="mt-1.5 text-xs text-[var(--text-secondary)]">Sem valor previsto no período.</p>
                        @endif
                    </div>

                    <div class="min-w-0 rounded-2xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] p-5">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[var(--text-muted)]">Operações impactadas</span>
                        <div class="mt-2 text-3xl font-semibold tabular-nums text-[var(--text-primary)]">{{ $calendar['summary']['operation_count'] }}</div>
                        <p class="mt-1.5 text-xs text-[var(--text-secondary)]">Operações com pagamento previsto.</p>
                    </div>
                </div>

                <p class="mt-3 text-right text-[11px] text-[var(--text-muted)]">
                    {{ $hasActiveFilters ? 'Indicadores refletem os filtros aplicados.' : 'Indicadores referentes ao mês exibido.' }}
                </p>
            </div>

            <div class="border-t border-[var(--border-subtle)] px-6 py-6 sm:px-8">
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                    <label class="grid gap-2">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[var(--text-muted)]">Operação</span>
                        <span class="group relative block">
                            <select
                                wire:model.live="selectedEmissionId"
                                class="h-11 w-full appearance-none rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] pl-4 pr-12 text-sm leading-6 text-[var(--text-primary)] transition focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/30"
                            >
                                <option value="">Todas as operações</option>
                                @foreach ($emissionOptions as $emissionId => $emissionName)
                                    <option value="{{ $emissionId }}">{{ $emissionName }}</option>
                                @endforeach
                            </select>
                            <span class="pointer-events-none absolute inset-y-0 right-0 flex w-11 items-center justify-center text-[var(--text-muted)] transition duration-200 ease-out group-hover:text-[var(--text-primary)] group-focus-within:rotate-180 group-focus-within:text-[var(--accent)]">
                                <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" />
                            </span>
                        </span>
                    </label>

                    <label class="grid gap-2">
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[var(--text-muted)]">Categoria</span>
                        <span class="group relative block">
                            <select
                                wire:model.live="selectedCategory"
                                class="h-11 w-full appearance-none rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] pl-4 pr-12 text-sm leading-6 text-[var(--text-primary)] transition focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/30"
                            >
                                <option value="">Todas as categorias</option>
                                @foreach ($categoryOptions as $categoryValue => $categoryLabel)
                                    <option value="{{ $categoryValue }}">{{ $categoryLabel }}</option>
                                @endforeach
                            </select>
                            <span class="pointer-events-none absolute inset-y-0 right-0 flex w-11 items-center justify-center text-[var(--text-muted)] transition duration-200 ease-out group-hover:text-[var(--text-primary)] group-focus-within:rotate-180 group-focus-within:text-[var(--accent)]">
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
                            'border-[var(--accent)]/40 bg-[var(--accent-subtle)] text-[var(--accent)] hover:border-[var(--accent)]/70 hover:bg-[var(--accent-subtle)]/80' => $hasActiveFilters,
                            'cursor-not-allowed border-[var(--border-subtle)] bg-transparent text-[var(--text-muted)] opacity-60' => ! $hasActiveFilters,
                        ])
                    >
                        <x-filament::icon icon="heroicon-o-funnel" class="h-4 w-4" />
                        <span>Limpar filtros</span>
                    </button>
                </div>
            </div>
        </section>

        <section class="hidden overflow-hidden rounded-3xl border border-[var(--border-subtle)] bg-[var(--surface-card)] shadow-sm lg:block">
            @if ($calendar['summary']['event_count'] === 0)
                <div class="flex items-center gap-3 border-b border-[var(--border-subtle)] px-6 py-4 sm:px-8">
                    <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5 text-[var(--text-muted)]" />
                    <p class="text-sm text-[var(--text-secondary)]">
                        Nenhum pagamento previsto para {{ $calendar['month_label'] }}{{ $hasActiveFilters ? ' com os filtros aplicados' : '' }}.
                    </p>
                </div>
            @endif

            <div class="overflow-x-auto">
                <div class="min-w-[1080px]">
                    <div class="grid grid-cols-7 border-b border-[var(--border-subtle)] bg-[var(--surface-ground)]/60">
                        @foreach ($weekdayLabels as $weekdayLabel)
                            <div
                                @class([
                                    'px-4 py-3.5 text-[11px] font-semibold uppercase tracking-[0.18em]',
                                    'text-[var(--text-secondary)]' => $loop->index < 5,
                                    'text-[var(--text-muted)]' => $loop->index >= 5,
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
                                        'min-h-44 border-b border-r border-[var(--border-subtle)] px-3 py-3 align-top transition-colors',
                                        'bg-[var(--surface-ground)]/40 hover:bg-[var(--surface-highlight)]' => $day['is_current_month'] && $loop->index >= 5,
                                        'bg-[var(--surface-card)] hover:bg-[var(--surface-highlight)]' => $day['is_current_month'] && $loop->index < 5,
                                        'bg-[var(--surface-ground)]/80 opacity-60' => ! $day['is_current_month'],
                                    ])
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span
                                            @class([
                                                'inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold',
                                                'bg-[var(--accent)] text-[var(--accent-foreground)] font-bold shadow-xs' => $day['is_today'],
                                                'text-[var(--text-primary)]' => ! $day['is_today'] && $day['is_current_month'],
                                                'text-[var(--text-muted)]' => ! $day['is_today'] && ! $day['is_current_month'],
                                            ])
                                        >
                                            {{ $day['day_number'] }}
                                        </span>
                                    </div>

                                    @if (count($day['events']) > 0)
                                        <div class="mt-2 space-y-1.5">
                                            @foreach (array_slice($day['events'], 0, 2) as $event)
                                                <button
                                                    type="button"
                                                    wire:key="expense-calendar-event-{{ $event['id'] }}"
                                                    wire:click="openEvent('{{ $event['id'] }}')"
                                                    title="{{ $eventTooltip($event) }}"
                                                    @class([
                                                        'block w-full text-left rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] px-2.5 py-2 shadow-xs transition',
                                                        'hover:border-[var(--accent)]/50 hover:bg-[var(--surface-highlight)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent)]/60',
                                                        'opacity-60' => ! $day['is_current_month'],
                                                    ])
                                                >
                                                    <div class="flex items-center justify-between gap-1.5">
                                                        <span class="inline-flex min-w-0 items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-[var(--text-secondary)]">
                                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $event['dot_classes'] }}"></span>
                                                            <span class="truncate">{{ $event['category'] }}</span>
                                                        </span>

                                                        <span class="shrink-0 inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold {{ $event['badge_classes'] }}">
                                                            {{ $event['status_label'] }}
                                                        </span>
                                                    </div>

                                                    <p class="mt-1 text-sm font-semibold tabular-nums text-[var(--text-primary)]">{{ $event['amount_label'] }}</p>
                                                    <p class="mt-0.5 truncate text-[11px] text-[var(--text-muted)]">{{ $event['operation'] }}</p>
                                                </button>
                                            @endforeach

                                            @if (count($day['events']) > 2)
                                                <button
                                                    type="button"
                                                    wire:click="openDay('{{ $day['date'] }}')"
                                                    class="inline-flex items-center gap-1 rounded-lg px-1.5 py-1 text-[11px] font-semibold text-[var(--accent)] transition hover:bg-[var(--accent-subtle)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent)]/60"
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

        <section class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--surface-card)] p-5 shadow-sm lg:hidden">
            <div class="space-y-4">
                @forelse (collect($calendar['weeks'])->flatten(1)->filter(fn (array $day): bool => $day['is_current_month'] && count($day['events']) > 0) as $day)
                    <div wire:key="expense-calendar-mobile-{{ $day['date'] }}" class="rounded-2xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-[var(--text-primary)]">{{ \Carbon\CarbonImmutable::parse($day['date'])->locale('pt_BR')->translatedFormat('d \d\e F') }}</h3>
                            <span class="text-xs text-[var(--text-muted)]">{{ count($day['events']) }} {{ count($day['events']) === 1 ? 'pagamento' : 'pagamentos' }}</span>
                        </div>

                        <div class="space-y-2">
                            @foreach ($day['events'] as $event)
                                <button
                                    type="button"
                                    wire:key="expense-calendar-mobile-event-{{ $event['id'] }}"
                                    wire:click="openEvent('{{ $event['id'] }}')"
                                    class="w-full text-left block rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-card)] p-3 transition hover:border-[var(--accent)]/50 hover:bg-[var(--surface-highlight)]"
                                >
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="inline-flex min-w-0 items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-[var(--text-secondary)]">
                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $event['dot_classes'] }}"></span>
                                            <span class="truncate">{{ $event['category'] }}</span>
                                        </span>

                                        <span class="shrink-0 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold {{ $event['badge_classes'] }}">
                                            {{ $event['status_label'] }}
                                        </span>
                                    </div>

                                    <div class="mt-2 flex items-baseline justify-between gap-2">
                                        <p class="text-sm font-semibold tabular-nums text-[var(--text-primary)]">{{ $event['amount_label'] }}</p>
                                        <p class="truncate text-xs text-[var(--text-muted)]">{{ $event['operation'] }}</p>
                                    </div>
                                    <p class="mt-1 truncate text-xs text-[var(--text-muted)]">{{ $event['service_provider'] }}</p>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-[var(--border-subtle)] bg-[var(--surface-ground)] px-4 py-5 text-sm text-[var(--text-secondary)]">
                        Nenhum pagamento previsto para o mês selecionado.
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    @if ($selectedEvent !== null)
        <div
            x-data
            x-on:keydown.escape.window="$wire.closeEvent()"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
        >
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" wire:click="closeEvent"></div>

            <div
                role="dialog"
                aria-modal="true"
                aria-label="Detalhes do pagamento"
                class="relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--surface-elevated)] shadow-2xl"
            >
                <div class="flex items-start justify-between gap-4 border-b border-[var(--border-subtle)] px-6 py-5">
                    <div>
                        <span class="text-[11px] font-semibold uppercase tracking-[0.2em] text-[var(--text-muted)]">Detalhes da ocorrência</span>
                        <h3 class="mt-1 text-xl font-semibold text-[var(--text-primary)]">{{ $selectedEvent['category'] }}</h3>
                    </div>

                    <button
                        type="button"
                        wire:click="closeEvent"
                        aria-label="Fechar"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-[var(--border-subtle)] text-[var(--text-muted)] transition hover:border-[var(--accent)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-highlight)]"
                    >
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
                    </button>
                </div>

                <div class="space-y-4 overflow-y-auto px-6 py-5 text-sm">
                    <div class="grid grid-cols-2 gap-4 rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] p-4">
                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Status</span>
                            <div class="mt-1.5">
                                <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold {{ $selectedEvent['badge_classes'] }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $selectedEvent['dot_classes'] }}"></span>
                                    {{ $selectedEvent['status_label'] }}
                                </span>
                            </div>
                        </div>

                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Vencimento</span>
                            <p class="mt-1.5 font-medium text-[var(--text-primary)]">{{ $selectedEvent['due_date_label'] }}</p>
                        </div>

                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-secondary)]">Valor previsto</span>
                            <p class="mt-1.5 font-semibold text-[var(--text-primary)] tabular-nums">{{ $selectedEvent['expected_amount_label'] }}</p>
                        </div>

                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-secondary)]">Valor pago</span>
                            <p class="mt-1.5 font-semibold tabular-nums {{ $selectedEvent['paid_amount_label'] !== '—' ? 'text-emerald-500 dark:text-emerald-400' : 'text-[var(--text-muted)]' }}">
                                {{ $selectedEvent['paid_amount_label'] }}
                            </p>
                        </div>

                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Data do pagamento</span>
                            <p class="mt-1.5 font-medium {{ $selectedEvent['payment_date_label'] !== '—' ? 'text-emerald-500 dark:text-emerald-400' : 'text-[var(--text-muted)]' }}">
                                {{ $selectedEvent['payment_date_label'] }}
                            </p>
                        </div>

                        @if (! empty($selectedEvent['is_partially_paid']))
                            <div>
                                <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Saldo em aberto</span>
                                <p class="mt-1.5 font-semibold tabular-nums text-amber-600 dark:text-amber-400">
                                    {{ $selectedEvent['remaining_amount_label'] }}
                                </p>
                            </div>
                        @endif

                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Periodicidade</span>
                            <p class="mt-1.5 font-medium text-[var(--text-secondary)]">{{ $selectedEvent['period_label'] }}</p>
                        </div>
                    </div>

                    <div class="space-y-3 rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] p-4">
                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Operação</span>
                            <p class="mt-1 font-medium text-[var(--text-primary)]">{{ $selectedEvent['operation'] }}</p>
                        </div>

                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Categoria</span>
                            <p class="mt-1 font-medium text-[var(--text-primary)]">{{ $selectedEvent['category'] }}</p>
                        </div>

                        <div>
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">Prestador</span>
                            <p class="mt-1 font-medium text-[var(--text-secondary)]">{{ $selectedEvent['service_provider'] }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between border-t border-[var(--border-subtle)] px-6 py-4 bg-[var(--surface-ground)]/40">
                    @if ($selectedEvent['url'] !== null)
                        <a
                            href="{{ $selectedEvent['url'] }}"
                            class="inline-flex items-center gap-2 rounded-xl border border-[var(--accent)]/40 bg-[var(--accent-subtle)] px-4 py-2 text-sm font-semibold text-[var(--accent)] transition hover:border-[var(--accent)]/70 hover:bg-[var(--accent-subtle)]/80"
                        >
                            <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                            <span>Editar despesa</span>
                        </a>
                    @else
                        <div></div>
                    @endif

                    <button
                        type="button"
                        wire:click="closeEvent"
                        class="inline-flex items-center justify-center rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] px-4 py-2 text-sm font-medium text-[var(--text-secondary)] transition hover:text-[var(--text-primary)] hover:border-[var(--border-strong)]"
                    >
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    @endif

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
                class="relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--surface-elevated)] shadow-2xl"
            >
                <div class="flex items-start justify-between gap-4 border-b border-[var(--border-subtle)] px-6 py-5">
                    <div>
                        <h3 class="text-lg font-semibold text-[var(--text-primary)]">
                            {{ ucfirst(\Carbon\CarbonImmutable::parse($selectedDay['date'])->locale('pt_BR')->translatedFormat('l, d \d\e F')) }}
                        </h3>
                        <p class="mt-1 text-xs text-[var(--text-muted)]">
                            {{ count($selectedDay['events']) }} {{ count($selectedDay['events']) === 1 ? 'pagamento previsto' : 'pagamentos previstos' }}
                            · Total de {{ 'R$ '.number_format(collect($selectedDay['events'])->sum('amount'), 2, ',', '.') }}
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeDay"
                        aria-label="Fechar"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-[var(--border-subtle)] text-[var(--text-muted)] transition hover:border-[var(--accent)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-highlight)]"
                    >
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
                    </button>
                </div>

                <div class="space-y-3 overflow-y-auto px-6 py-5">
                    @foreach ($selectedDay['events'] as $event)
                        <div
                            wire:key="expense-calendar-modal-event-{{ $event['id'] }}"
                            class="rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-ground)] p-4 transition hover:border-[var(--border-strong)]"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <span class="inline-flex min-w-0 items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-[var(--text-secondary)]">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $event['dot_classes'] }}"></span>
                                    <span class="truncate">{{ $event['category'] }}</span>
                                </span>

                                <span class="shrink-0 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold {{ $event['badge_classes'] }}">
                                    {{ $event['status_label'] }}
                                </span>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs border-y border-[var(--border-subtle)] py-2.5">
                                <div>
                                    <span class="text-[var(--text-muted)]">Previsto:</span>
                                    <span class="font-semibold text-[var(--text-primary)] tabular-nums">{{ $event['expected_amount_label'] }}</span>
                                </div>
                                <div>
                                    <span class="text-[var(--text-muted)]">Pago:</span>
                                    <span class="font-semibold tabular-nums {{ $event['paid_amount_label'] !== '—' ? 'text-emerald-500 dark:text-emerald-400' : 'text-[var(--text-muted)]' }}">
                                        {{ $event['paid_amount_label'] }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-[var(--text-muted)]">Vencimento:</span>
                                    <span class="text-[var(--text-secondary)]">{{ $event['due_date_label'] }}</span>
                                </div>
                                <div>
                                    <span class="text-[var(--text-muted)]">Data pagamento:</span>
                                    <span class="{{ $event['payment_date_label'] !== '—' ? 'text-emerald-500 dark:text-emerald-400' : 'text-[var(--text-muted)]' }}">
                                        {{ $event['payment_date_label'] }}
                                    </span>
                                </div>
                            </div>

                            <p class="mt-2.5 text-sm text-[var(--text-primary)] font-medium">{{ $event['operation'] }}</p>
                            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                Prestador: {{ $event['service_provider'] }} · {{ $event['period_label'] }}
                            </p>

                            <div class="mt-3 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    wire:click="openEvent('{{ $event['id'] }}')"
                                    class="inline-flex items-center gap-1 text-xs font-semibold text-[var(--accent)] hover:underline"
                                >
                                    Ver detalhes
                                </button>
                                @if ($event['url'] !== null)
                                    <span class="text-[var(--border-strong)]">·</span>
                                    <a
                                        href="{{ $event['url'] }}"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:underline"
                                    >
                                        Editar
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>

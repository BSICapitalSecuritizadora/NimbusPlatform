@php
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\Contracts\Support\Htmlable;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
    $filters = $this->getFilters();
    $isCollapsible = $this->isCollapsible();
    $type = $this->getType();
    $maxHeight = $this->getMaxHeight();
    $hasMaxHeight = filled($maxHeight) && $maxHeight !== '100%';
    $isEmpty = $this->isEmpty();
    $metrics = $this->getMetrics();

    $chartAccessibleLabel = trim(implode('. ', array_filter([
        $heading instanceof Htmlable ? strip_tags($heading->toHtml()) : $heading,
        $description instanceof Htmlable ? strip_tags($description->toHtml()) : $description,
    ], fn ($value): bool => filled($value))));
@endphp

<x-filament-widgets::widget class="fi-wi-chart bsi-cockpit-widget bsi-proposal-volume-widget">
    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        @if ($filters || method_exists($this, 'getFiltersSchema'))
            <x-slot name="afterHeader">
                @if ($filters)
                    <x-filament::input.wrapper
                        inline-prefix
                        wire:target="filter"
                        class="fi-wi-chart-filter"
                    >
                        <x-filament::input.select
                            :aria-label="__('filament-widgets::chart.filter.label')"
                            inline-prefix
                            wire:model.live="filter"
                        >
                            @foreach ($filters as $value => $label)
                                <option value="{{ $value }}">
                                    {{ $label }}
                                </option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif

                @if (method_exists($this, 'getFiltersSchema'))
                    <x-filament::dropdown
                        placement="bottom-end"
                        shift
                        width="xs"
                        class="fi-wi-chart-filter"
                    >
                        <x-slot name="trigger">
                            {{ $this->getFiltersTriggerAction() }}
                        </x-slot>

                        <div class="fi-wi-chart-filter-content">
                            {{ $this->getFiltersSchema() }}

                            @if (method_exists($this, 'hasDeferredFilters') && $this->hasDeferredFilters())
                                <div class="fi-wi-chart-filter-content-actions-ctn">
                                    {{ $this->getFiltersApplyAction() }}
                                    {{ $this->getFiltersResetAction() }}
                                </div>
                            @endif
                        </div>
                    </x-filament::dropdown>
                @endif
            </x-slot>
        @endif

        {{-- Faixa de Síntese Analítica Executiva --}}
        <div class="mb-2.5 grid grid-cols-2 gap-2 sm:grid-cols-4">
            {{-- Total Captado --}}
            <div class="flex flex-col justify-between rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-card)] p-2 sm:px-2.5 sm:py-2 shadow-none transition-colors">
                <div class="flex items-center justify-between gap-1">
                    <span class="text-[0.59rem] font-semibold uppercase tracking-tight text-[var(--text-muted)] truncate">Total Captado</span>
                    <div class="flex size-4.5 shrink-0 items-center justify-center rounded bg-bsi-gold-500/15 text-bsi-gold-500">
                        <x-heroicon-m-arrow-down-tray class="size-3" aria-hidden="true" />
                    </div>
                </div>
                <div class="mt-1 flex items-baseline gap-1">
                    <span class="text-sm sm:text-base font-bold tabular-nums text-[var(--text-primary)]">{{ $metrics['total_received'] }}</span>
                    <span class="text-[0.65rem] font-normal text-[var(--text-secondary)] truncate">{{ $metrics['total_received'] === 1 ? 'proposta' : 'propostas' }}</span>
                </div>
            </div>

            {{-- Formalizações --}}
            <div class="flex flex-col justify-between rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-card)] p-2 sm:px-2.5 sm:py-2 shadow-none transition-colors">
                <div class="flex items-center justify-between gap-1">
                    <span class="text-[0.59rem] font-semibold uppercase tracking-tight text-[var(--text-muted)] truncate">Formalizações</span>
                    <div class="flex size-4.5 shrink-0 items-center justify-center rounded bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                        <x-heroicon-m-check-circle class="size-3" aria-hidden="true" />
                    </div>
                </div>
                <div class="mt-1 flex items-baseline gap-1">
                    <span class="text-sm sm:text-base font-bold tabular-nums text-[var(--text-primary)]">{{ $metrics['total_completed'] }}</span>
                    <span class="text-[0.65rem] font-normal text-[var(--text-secondary)] truncate">{{ $metrics['total_completed'] === 1 ? 'concluída' : 'concluídas' }}</span>
                </div>
            </div>

            {{-- Conversão --}}
            <div class="flex flex-col justify-between rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-card)] p-2 sm:px-2.5 sm:py-2 shadow-none transition-colors">
                <div class="flex items-center justify-between gap-1">
                    <span class="text-[0.59rem] font-semibold uppercase tracking-tight text-[var(--text-muted)] truncate">Conversão</span>
                    <div class="flex size-4.5 shrink-0 items-center justify-center rounded bg-blue-500/15 text-blue-600 dark:text-blue-400">
                        <x-heroicon-m-chart-pie class="size-3" aria-hidden="true" />
                    </div>
                </div>
                <div class="mt-1 flex items-baseline gap-1">
                    <span class="text-sm sm:text-base font-bold tabular-nums text-[var(--text-primary)]">{{ $metrics['conversion_rate'] }}%</span>
                    <span class="text-[0.65rem] font-normal text-[var(--text-secondary)] truncate">(no período)</span>
                </div>
            </div>

            {{-- Mês Destaque --}}
            <div class="flex flex-col justify-between rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-card)] p-2 sm:px-2.5 sm:py-2 shadow-none transition-colors">
                <div class="flex items-center justify-between gap-1">
                    <span class="text-[0.59rem] font-semibold uppercase tracking-tight text-[var(--text-muted)] truncate">Mês Destaque</span>
                    <div class="flex size-4.5 shrink-0 items-center justify-center rounded bg-[var(--surface-ground)] text-[var(--text-secondary)]">
                        <x-heroicon-m-calendar-days class="size-3" aria-hidden="true" />
                    </div>
                </div>
                <div class="mt-1 flex items-baseline gap-1 truncate">
                    @if($metrics['peak_count'] > 0)
                        <span class="text-sm sm:text-base font-bold tabular-nums text-[var(--text-primary)]">{{ $metrics['peak_month'] }}</span>
                        <span class="text-[0.65rem] font-normal text-[var(--text-secondary)]">({{ $metrics['peak_count'] }})</span>
                    @else
                        <span class="text-sm sm:text-base font-bold tabular-nums text-[var(--text-primary)]">{{ $metrics['current_month_label'] }}</span>
                        <span class="text-[0.65rem] font-normal text-[var(--text-secondary)]">(0)</span>
                    @endif
                </div>
            </div>
        </div>

        @if(! $metrics['has_activity'])
            <div class="mb-3 flex items-center gap-2 rounded-lg bg-[var(--surface-ground)] px-3 py-2 text-xs text-[var(--text-secondary)]">
                <x-heroicon-m-information-circle class="size-4 shrink-0 text-[var(--text-muted)]" />
                <span>Nenhuma movimentação de propostas registrada no período selecionado.</span>
            </div>
        @endif

        <div
            @if ($pollingInterval = $this->getPollingInterval())
                wire:poll.{{ $pollingInterval }}="updateChartData"
            @endif
            @if ($isEmpty)
                style="display: none"
            @endif
        >
            <div
                x-load
                x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                data-chart-type="{{ $type }}"
                x-data="chart({
                            cachedData: @js($this->getCachedData()),
                            options: @js($this->getOptions()),
                            type: @js($type),
                        })"
                {{
                    (new FilamentComponentAttributeBag)
                        ->color(ChartWidgetComponent::class, $color)
                        ->class([
                            'fi-wi-chart-frame',
                            'fi-wi-chart-canvas-ctn',
                            'fi-wi-chart-frame-no-aspect-ratio' => $hasMaxHeight,
                        ])
                }}
            >
                <canvas
                    x-ref="canvas"
                    @if (filled($chartAccessibleLabel))
                        role="img"
                        aria-label="{{ $chartAccessibleLabel }}"
                    @endif
                    @style([
                        'width: 100%',
                        'height: 100%; max-height: 100%' => ! $hasMaxHeight,
                        ('max-height: ' . e($maxHeight)) => $hasMaxHeight,
                    ])
                ></canvas>

                <span
                    aria-hidden="true"
                    x-ref="backgroundColorElement"
                    class="fi-wi-chart-bg-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="borderColorElement"
                    class="fi-wi-chart-border-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="gridColorElement"
                    class="fi-wi-chart-grid-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="textColorElement"
                    class="fi-wi-chart-text-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="tooltipBackgroundColorElement"
                    class="fi-wi-chart-tooltip-bg-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="tooltipTextColorElement"
                    class="fi-wi-chart-tooltip-text-color"
                ></span>

                <span
                    aria-hidden="true"
                    x-ref="tooltipBorderColorElement"
                    class="fi-wi-chart-tooltip-border-color"
                ></span>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

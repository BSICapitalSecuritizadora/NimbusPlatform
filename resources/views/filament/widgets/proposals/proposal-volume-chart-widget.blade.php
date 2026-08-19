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
        <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="flex items-center gap-2.5 rounded-lg border border-gray-200/80 bg-bsi-paper p-2.5 dark:border-gray-700/80 dark:bg-gray-900/60">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-bsi-gold-500/15 text-bsi-gold-500">
                    <x-heroicon-m-arrow-down-tray class="size-4" aria-hidden="true" />
                </div>
                <div class="min-w-0">
                    <span class="block text-[0.6875rem] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Captado</span>
                    <span class="block text-sm font-bold tabular-nums text-gray-950 dark:text-white">{{ $metrics['total_received'] }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">propostas</span></span>
                </div>
            </div>

            <div class="flex items-center gap-2.5 rounded-lg border border-gray-200/80 bg-bsi-paper p-2.5 dark:border-gray-700/80 dark:bg-gray-900/60">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                    <x-heroicon-m-check-circle class="size-4" aria-hidden="true" />
                </div>
                <div class="min-w-0">
                    <span class="block text-[0.6875rem] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Formalizações</span>
                    <span class="block text-sm font-bold tabular-nums text-gray-950 dark:text-white">{{ $metrics['total_completed'] }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">concluídas</span></span>
                </div>
            </div>

            <div class="flex items-center gap-2.5 rounded-lg border border-gray-200/80 bg-bsi-paper p-2.5 dark:border-gray-700/80 dark:bg-gray-900/60">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-blue-500/15 text-blue-600 dark:text-blue-400">
                    <x-heroicon-m-chart-pie class="size-4" aria-hidden="true" />
                </div>
                <div class="min-w-0">
                    <span class="block text-[0.6875rem] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Conversão</span>
                    <span class="block text-sm font-bold tabular-nums text-gray-950 dark:text-white">
                        {{ $metrics['conversion_rate'] }}%
                        <span class="text-[0.6875rem] font-normal text-gray-500 dark:text-gray-400">(no período)</span>
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-2.5 rounded-lg border border-gray-200/80 bg-bsi-paper p-2.5 dark:border-gray-700/80 dark:bg-gray-900/60">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <x-heroicon-m-calendar-days class="size-4" aria-hidden="true" />
                </div>
                <div class="min-w-0">
                    <span class="block text-[0.6875rem] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Mês Destaque</span>
                    <span class="block text-sm font-bold tabular-nums text-gray-950 dark:text-white truncate">
                        @if($metrics['peak_count'] > 0)
                            {{ $metrics['peak_month'] }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">({{ $metrics['peak_count'] }})</span>
                        @else
                            {{ $metrics['current_month_label'] }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(0)</span>
                        @endif
                    </span>
                </div>
            </div>
        </div>

        @if(! $metrics['has_activity'])
            <div class="mb-3 flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-gray-800/40 dark:text-gray-400">
                <x-heroicon-m-information-circle class="size-4 shrink-0 text-gray-400" />
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
                    x-ref="backgroundColorElement"
                    class="fi-wi-chart-bg-color"
                ></span>

                <span
                    x-ref="borderColorElement"
                    class="fi-wi-chart-border-color"
                ></span>

                <span
                    x-ref="gridColorElement"
                    class="fi-wi-chart-grid-color"
                ></span>

                <span
                    x-ref="textColorElement"
                    class="fi-wi-chart-text-color"
                ></span>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

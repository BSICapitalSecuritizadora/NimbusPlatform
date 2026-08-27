@php
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\Contracts\Support\Htmlable;

    $color = $this->getColor();
    $heading = $this->getHeading();
    $description = $this->getDescription();
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

<x-filament-widgets::widget class="fi-wi-chart bsi-cockpit-widget bsi-nimbus-volume-widget">
    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        @if(! $metrics['has_activity'])
            <div class="flex flex-col items-center justify-center py-10 text-center">
                <div class="flex size-12 items-center justify-center rounded-xl bg-slate-800/60 border border-slate-700/50 text-slate-400">
                    <x-heroicon-o-presentation-chart-line class="size-6" aria-hidden="true" />
                </div>
                <p class="mt-3.5 text-sm font-semibold text-gray-950 dark:text-white">Nenhum envio registrado nos últimos 30 dias</p>
                <p class="mt-1 max-w-xs text-xs text-gray-500 dark:text-slate-400">O gráfico de volume diário será exibido conforme novas submissões forem registradas.</p>
            </div>
        @else
            {{-- Faixa de Indicadores de Volume --}}
            <div class="mb-4 grid grid-cols-3 gap-2.5">
                <div class="flex items-center gap-2.5 rounded-lg border border-slate-800/80 bg-slate-900/60 p-2.5">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-sky-500/15 text-sky-400">
                        <x-heroicon-m-inbox-stack class="size-4" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <span class="block text-[0.625rem] font-semibold uppercase tracking-wider text-slate-400">Total Período</span>
                        <span class="block text-sm font-bold tabular-nums text-gray-950 dark:text-white">
                            {{ $metrics['total'] }} <span class="text-xs font-normal text-slate-400">envios</span>
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-2.5 rounded-lg border border-slate-800/80 bg-slate-900/60 p-2.5">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-amber-500/15 text-amber-400">
                        <x-heroicon-m-calculator class="size-4" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <span class="block text-[0.625rem] font-semibold uppercase tracking-wider text-slate-400">Média Diária</span>
                        <span class="block text-sm font-bold tabular-nums text-gray-950 dark:text-white">
                            {{ $metrics['daily_average'] }} <span class="text-xs font-normal text-slate-400">/dia</span>
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-2.5 rounded-lg border border-slate-800/80 bg-slate-900/60 p-2.5">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-emerald-500/15 text-emerald-400">
                        <x-heroicon-m-bolt class="size-4" aria-hidden="true" />
                    </div>
                    <div class="min-w-0">
                        <span class="block text-[0.625rem] font-semibold uppercase tracking-wider text-slate-400">Pico de Envios</span>
                        <span class="block text-sm font-bold tabular-nums text-gray-950 dark:text-white truncate">
                            {{ $metrics['peak_count'] }} <span class="text-xs font-normal text-slate-400">({{ $metrics['peak_date'] }})</span>
                        </span>
                    </div>
                </div>
            </div>

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
                            'height: 100%; max-height: 200px' => ! $hasMaxHeight,
                            ('max-height: ' . e($maxHeight)) => $hasMaxHeight,
                        ])
                    ></canvas>

                    <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                    <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                    <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                    <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

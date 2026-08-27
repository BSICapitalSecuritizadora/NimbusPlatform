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
    $details = $this->getDetails();
    $submissionsUrl = $this->getSubmissionsUrl();

    $chartAccessibleLabel = trim(implode('. ', array_filter([
        $heading instanceof Htmlable ? strip_tags($heading->toHtml()) : $heading,
        $description instanceof Htmlable ? strip_tags($description->toHtml()) : $description,
    ], fn ($value): bool => filled($value))));
@endphp

<x-filament-widgets::widget class="fi-wi-chart bsi-cockpit-widget bsi-nimbus-status-widget">
    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        @if($details['total'] === 0)
            <div class="flex flex-col items-center justify-center py-10 text-center">
                <div class="flex size-12 items-center justify-center rounded-xl bg-slate-800/60 border border-slate-700/50 text-slate-400">
                    <x-heroicon-o-chart-pie class="size-6" aria-hidden="true" />
                </div>
                <p class="mt-3.5 text-sm font-semibold text-gray-950 dark:text-white">Sem dados para distribuição</p>
                <p class="mt-1 max-w-xs text-xs text-gray-500 dark:text-slate-400">A distribuição aparecerá quando houver envios classificados.</p>
            </div>
        @else
            {{-- Rosca Donut com Centro Executivo --}}
            <div class="relative flex items-center justify-center py-2">
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
                                'w-full flex justify-center',
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
                            'max-width: 170px',
                            'width: 100%',
                            'height: 100%; max-height: 150px' => ! $hasMaxHeight,
                            ('max-height: ' . e($maxHeight)) => $hasMaxHeight,
                        ])
                    ></canvas>

                    <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                    <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                    <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                    <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                </div>

                {{-- Informação Central do Donut --}}
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                    <span class="text-2xl font-bold tabular-nums leading-tight text-gray-950 dark:text-white">
                        {{ $details['total'] }}
                    </span>
                    <span class="text-[0.625rem] font-semibold uppercase tracking-wider text-gray-500 dark:text-slate-400">
                        {{ $details['total'] === 1 ? 'Submissão' : 'Submissões' }}
                    </span>
                </div>
            </div>

            {{-- Lista de Distribuição com Barras Proporcionais --}}
            <div class="mt-3.5 space-y-2 border-t border-gray-100 pt-3 dark:border-slate-800">
                @foreach($details['active_items'] as $item)
                    <a
                        href="{{ $submissionsUrl }}"
                        class="group flex flex-col gap-1 rounded-lg px-2 py-1.5 transition-colors duration-150 hover:bg-slate-800/40"
                        title="Ver envios com status {{ $item['label'] }}"
                    >
                        <div class="flex items-center justify-between gap-2 text-xs">
                            <span class="flex items-center gap-2 min-w-0 font-medium text-gray-700 dark:text-slate-300 group-hover:text-gray-950 dark:group-hover:text-white">
                                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $item['color_hex'] }};"></span>
                                <span class="truncate">{{ $item['label'] }}</span>
                            </span>
                            <span class="shrink-0 font-semibold tabular-nums text-gray-950 dark:text-white">
                                {{ $item['count'] }} <span class="font-normal text-gray-500 dark:text-slate-400">({{ $item['percentage'] }}%)</span>
                            </span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-800">
                            <div
                                class="h-full rounded-full transition-all duration-300"
                                style="width: {{ $item['percentage'] }}%; background-color: {{ $item['color_hex'] }};"
                            ></div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

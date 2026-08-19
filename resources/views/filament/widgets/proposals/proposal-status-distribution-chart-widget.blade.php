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
    $proposalsUrl = $this->getProposalsUrl();

    $chartAccessibleLabel = trim(implode('. ', array_filter([
        $heading instanceof Htmlable ? strip_tags($heading->toHtml()) : $heading,
        $description instanceof Htmlable ? strip_tags($description->toHtml()) : $description,
    ], fn ($value): bool => filled($value))));
@endphp

<x-filament-widgets::widget class="fi-wi-chart bsi-cockpit-widget bsi-proposal-status-widget">
    <x-filament::section
        :description="$description"
        :heading="$heading"
        :collapsible="$isCollapsible"
    >
        @if($details['total'] === 0)
            <div class="flex flex-col items-center justify-center py-8 text-center">
                <div class="flex size-11 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500">
                    <x-heroicon-o-folder-open class="size-6" aria-hidden="true" />
                </div>
                <p class="mt-3 text-sm font-semibold text-gray-950 dark:text-white">Nenhuma proposta na carteira no momento.</p>
                <p class="mt-1 max-w-xs text-xs text-gray-500 dark:text-gray-400">As entradas recebidas serão distribuídas automaticamente pelos estágios operacionais.</p>
            </div>
        @else
            @if($details['dominant_item'] && count($details['active_items']) > 1)
                <div class="mb-3 flex items-center justify-between rounded-md bg-gray-50/80 px-2.5 py-1.5 text-xs dark:bg-gray-800/40">
                    <span class="text-gray-500 dark:text-gray-400">Maior concentração:</span>
                    <span class="font-semibold text-gray-900 dark:text-white">
                        {{ $details['dominant_item']['label'] }} ({{ $details['dominant_item']['percentage'] }}%)
                    </span>
                </div>
            @endif

            {{-- Rosca Donut com Centro Executivo --}}
            <div class="relative flex items-center justify-center py-1">
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
                    <span class="text-[0.625rem] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        {{ $details['total'] === 1 ? 'Em Carteira' : 'Em Carteira' }}
                    </span>
                </div>
            </div>

            {{-- Lista de Distribuição Executiva com Barras Proporcionais --}}
            <div class="mt-3.5 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                @foreach($details['active_items'] as $item)
                    <a
                        href="{{ $proposalsUrl }}"
                        class="group flex flex-col gap-1 rounded-lg px-2 py-1.5 transition-colors duration-150 hover:bg-gray-100/60 dark:hover:bg-gray-800/60"
                        title="Ver propostas com status {{ $item['label'] }}"
                    >
                        <div class="flex items-center justify-between gap-2 text-xs">
                            <span class="flex items-center gap-2 min-w-0 font-medium text-gray-700 dark:text-gray-300 group-hover:text-gray-950 dark:group-hover:text-white">
                                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $item['color_hex'] }};"></span>
                                <span class="truncate">{{ $item['label'] }}</span>
                            </span>
                            <span class="shrink-0 font-semibold tabular-nums text-gray-950 dark:text-white">
                                {{ $item['count'] }} <span class="font-normal text-gray-500 dark:text-gray-400">({{ $item['percentage'] }}%)</span>
                            </span>
                        </div>
                        <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div
                                class="h-full rounded-full transition-all duration-300"
                                style="width: {{ $item['percentage'] }}%; background-color: {{ $item['color_hex'] }};"
                            ></div>
                        </div>
                    </a>
                @endforeach

                @if($details['inactive_items_count'] > 0)
                    <div class="pt-1 text-center text-[0.6875rem] text-gray-600 dark:text-gray-400">
                        <span>{{ $details['inactive_items_count'] }} {{ $details['inactive_items_count'] === 1 ? 'outro estágio sem propostas ativas' : 'outros estágios sem propostas ativas' }}</span>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

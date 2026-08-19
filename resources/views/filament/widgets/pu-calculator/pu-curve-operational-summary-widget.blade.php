@php
    /**
     * Paleta semantica discreta: neutro enquanto o indicador esta zerado, cor
     * apenas quando ha ocorrencia. O tom ja chega calculado pelo
     * PuOperationalDashboardData; aqui so viram classes.
     */
    $toneClasses = [
        'neutral' => [
            'border' => 'border-gray-200 dark:border-white/10',
            'surface' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
            'value' => 'text-gray-900 dark:text-white',
            'text' => 'text-gray-600 dark:text-gray-300',
        ],
        'info' => [
            'border' => 'border-info-300/70 dark:border-info-500/30',
            'surface' => 'bg-info-100 text-info-700 dark:bg-info-500/15 dark:text-info-300',
            'value' => 'text-info-700 dark:text-info-300',
            'text' => 'text-info-700 dark:text-info-300',
        ],
        'success' => [
            'border' => 'border-success-300/70 dark:border-success-500/30',
            'surface' => 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-300',
            'value' => 'text-success-700 dark:text-success-300',
            'text' => 'text-success-700 dark:text-success-300',
        ],
        'warning' => [
            'border' => 'border-amber-300/80 dark:border-amber-500/30',
            'surface' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
            'value' => 'text-amber-800 dark:text-amber-200',
            'text' => 'text-amber-800 dark:text-amber-200',
        ],
        'danger' => [
            'border' => 'border-danger-300/80 dark:border-danger-500/30',
            'surface' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
            'value' => 'text-danger-700 dark:text-danger-300',
            'text' => 'text-danger-700 dark:text-danger-300',
        ],
    ];

    $healthTone = match ($health['tone']) {
        'danger' => [
            'panel' => 'border-danger-300/70 bg-danger-50/70 dark:border-danger-500/25 dark:bg-danger-500/10',
            'icon' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300',
            'badge' => 'bg-danger-100 text-danger-800 dark:bg-danger-500/15 dark:text-danger-200',
        ],
        'warning' => [
            'panel' => 'border-amber-300/70 bg-amber-50/70 dark:border-amber-500/25 dark:bg-amber-500/10',
            'icon' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
            'badge' => 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-200',
        ],
        default => [
            'panel' => 'border-success-300/60 bg-success-50/60 dark:border-success-500/25 dark:bg-success-500/10',
            'icon' => 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-300',
            'badge' => 'bg-success-100 text-success-800 dark:bg-success-500/15 dark:text-success-200',
        ],
    };

    $hasException = $exception_total > 0;
    $hasQueueActivity = $queue_total > 0;

    $exceptionsInEvidence = array_values(array_filter($exceptions, fn (array $indicator): bool => $indicator['value'] > 0));
    $exceptionsClear = array_values(array_filter($exceptions, fn (array $indicator): bool => $indicator['value'] <= 0));
    $queueInEvidence = array_values(array_filter($queue, fn (array $indicator): bool => $indicator['value'] > 0));
    $queueClear = array_values(array_filter($queue, fn (array $indicator): bool => $indicator['value'] <= 0));

    $progressCounters = [
        [
            'key' => 'homologated',
            'label' => 'homologadas',
            'value' => $homologated,
            'description' => 'Curvas homologadas e protegidas contra sobrescrita',
            'focus' => 'homologated',
        ],
        [
            'key' => 'validated',
            'label' => 'validadas',
            'value' => $validated,
            'description' => 'Curvas validadas contra a planilha de referência',
            'focus' => 'validated',
        ],
    ];

    $focusedIndicator = filled($focusedState)
        ? collect($pipeline)
            ->concat($exceptions)
            ->concat($queue)
            ->concat($progressCounters)
            ->firstWhere('focus', $focusedState)
        : null;
@endphp

<x-filament-widgets::widget class="bsi-cockpit-widget bsi-pu-summary">
    <div class="flex flex-col gap-4">
        {{-- Resumo operacional: leitura imediata do estado do painel --}}
        <section
            aria-labelledby="pu-health-headline"
            data-pu-health="{{ $health['tone'] }}"
            class="bsi-pu-panel grid gap-4 rounded-2xl border p-4 sm:p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:gap-6 {{ $healthTone['panel'] }}"
        >
            <div class="flex items-start gap-3.5">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $healthTone['icon'] }}">
                    <x-dynamic-component :component="$health['icon']" class="size-5.5" aria-hidden="true" />
                </span>

                <div class="min-w-0">
                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[0.6875rem] font-semibold tracking-wide uppercase {{ $healthTone['badge'] }}">
                        {{ $health['label'] }}
                    </span>

                    <h2 id="pu-health-headline" class="mt-1.5 text-lg leading-tight font-semibold text-gray-950 sm:text-xl dark:text-white">
                        {{ $health['headline'] }}
                    </h2>

                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                        {{ $health['description'] }}
                    </p>
                </div>
            </div>

            <dl class="flex flex-wrap items-center gap-x-5 gap-y-2 lg:justify-end lg:border-l lg:border-gray-900/10 lg:py-1 lg:pl-6 dark:lg:border-white/10">
                @foreach ($health['chips'] as $chip)
                    <div wire:key="pu-health-chip-{{ $loop->index }}" class="flex items-baseline gap-1.5">
                        <dd @class([
                            'text-lg font-semibold tabular-nums',
                            'text-gray-900 dark:text-white' => $chip['tone'] === 'neutral',
                            'text-amber-800 dark:text-amber-200' => $chip['tone'] === 'warning',
                            'text-danger-700 dark:text-danger-300' => $chip['tone'] === 'danger',
                        ])>{{ $chip['value'] }}</dd>
                        <dt class="text-xs text-gray-600 dark:text-gray-400">{{ $chip['label'] }}</dt>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- Estado geral: volume configurado e posicao na esteira --}}
        <x-filament::section
            heading="Estado geral"
            description="Emissões com parâmetros de PU e a posição da versão mais recente de cada curva."
            icon="heroicon-o-chart-bar-square"
            icon-color="primary"
        >
            <div class="grid gap-5 lg:grid-cols-[minmax(0,17rem)_minmax(0,1fr)] lg:gap-7">
                <div class="lg:border-r lg:border-gray-200/80 lg:pr-7 dark:lg:border-white/10">
                    <p class="text-5xl leading-none font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">
                        {{ $total }}
                    </p>

                    <p class="mt-2.5 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $total === 1 ? 'Emissão com PU' : 'Emissões com PU' }}
                    </p>

                    <p class="bsi-pu-note mt-1 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                        Emissões com parâmetros de PU configurados.
                    </p>

                    <div class="mt-3">
                        @include('filament.widgets.pu-calculator.partials.counter-strip', [
                            'indicators' => $progressCounters,
                            'focusedState' => $focusedState,
                            'label' => null,
                        ])
                    </div>
                </div>

                <div class="min-w-0">
                    <p class="text-[0.6875rem] font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                        Esteira operacional
                    </p>

                    <ol class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($pipeline as $stage)
                            @php
                                $stageTone = $toneClasses[$stage['tone']] ?? $toneClasses['neutral'];
                                $isStageActive = $focusedState === $stage['focus'];
                            @endphp

                            <li class="relative" wire:key="pu-stage-{{ $stage['key'] }}">
                                <button
                                    type="button"
                                    wire:click="focusState('{{ $stage['focus'] }}')"
                                    x-on:click="document.getElementById('pu-curvas-por-emissao')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                                    aria-pressed="{{ $isStageActive ? 'true' : 'false' }}"
                                    aria-label="{{ $stage['value'] }} {{ $stage['label'] }} — {{ $isStageActive ? 'remover recorte da tabela' : 'filtrar a tabela de curvas' }}"
                                    title="{{ $stage['description'] }}"
                                    @class([
                                        'bsi-pu-stage flex w-full flex-col gap-1 rounded-xl border px-3 py-2.5 text-left transition-[border-color,background-color] duration-200 ease-out hover:border-bsi-gold-500 hover:bg-bsi-stone-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-bsi-gold-600 motion-reduce:transition-none dark:hover:bg-bsi-navy-800/70',
                                        $stageTone['border'] => ! $isStageActive,
                                        'bg-bsi-paper dark:bg-bsi-navy-900/60' => ! $isStageActive,
                                        'bsi-pu-stage--active border-bsi-gold-500 bg-bsi-stone-50 dark:bg-bsi-navy-800/70' => $isStageActive,
                                    ])
                                >
                                    <span class="text-2xl leading-none font-semibold tabular-nums {{ $stage['value'] > 0 ? $stageTone['value'] : 'text-gray-900 dark:text-white' }}">
                                        {{ $stage['value'] }}
                                    </span>
                                    <span class="text-xs leading-snug font-medium text-gray-700 dark:text-gray-300">
                                        {{ $stage['label'] }}
                                    </span>
                                </button>

                                @unless ($loop->last)
                                    <x-heroicon-m-chevron-right
                                        class="absolute top-1/2 -right-[0.8125rem] hidden size-3.5 -translate-y-1/2 text-gray-300 lg:block dark:text-gray-600"
                                        aria-hidden="true"
                                    />
                                @endunless
                            </li>
                        @endforeach
                    </ol>

                    <p class="bsi-pu-note mt-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                        Cada emissão aparece uma única vez, no estágio da sua versão mais recente. Curvas divergentes ou com erro estão detalhadas em Exceções. Selecione um estágio para recortar a tabela.
                    </p>
                </div>
            </div>
        </x-filament::section>

        {{-- Excecoes: o que impede uma curva de chegar a homologacao --}}
        <x-filament::section
            heading="Exceções"
            description="Curvas bloqueadas, divergentes ou fora do estado esperado."
            icon="heroicon-o-exclamation-triangle"
            :icon-color="$hasException ? 'danger' : 'gray'"
        >
            @if ($hasException)
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($exceptionsInEvidence as $indicator)
                        @include('filament.widgets.pu-calculator.partials.indicator-card', [
                            'indicator' => $indicator,
                            'toneClasses' => $toneClasses,
                            'focusedState' => $focusedState,
                        ])
                    @endforeach
                </div>

                @if ($exceptionsClear !== [])
                    <div class="mt-3">
                        @include('filament.widgets.pu-calculator.partials.counter-strip', [
                            'indicators' => $exceptionsClear,
                            'focusedState' => $focusedState,
                            'label' => 'Sem ocorrências',
                        ])
                    </div>
                @endif
            @else
                <div class="flex flex-col gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300">
                            <x-heroicon-o-check-circle class="size-5" aria-hidden="true" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-950 dark:text-white">Nenhuma exceção operacional detectada</span>
                            <span class="bsi-pu-note mt-0.5 block text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                                Nenhuma curva com erro, divergência, lacuna de índice ou pendência de geração.
                            </span>
                        </span>
                    </div>

                    @include('filament.widgets.pu-calculator.partials.counter-strip', [
                        'indicators' => $exceptions,
                        'focusedState' => $focusedState,
                        'label' => null,
                    ])
                </div>
            @endif
        </x-filament::section>

        {{-- Fila de processamento: saude da camada de jobs --}}
        <x-filament::section
            heading="Fila de processamento"
            description="Camada de jobs que gera e valida as curvas."
            icon="heroicon-o-queue-list"
            :icon-color="$hasQueueActivity ? 'info' : 'gray'"
        >
            @if ($hasQueueActivity)
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($queueInEvidence as $indicator)
                        @include('filament.widgets.pu-calculator.partials.indicator-card', [
                            'indicator' => $indicator,
                            'toneClasses' => $toneClasses,
                            'focusedState' => $focusedState,
                        ])
                    @endforeach
                </div>

                @if ($queueClear !== [])
                    <div class="mt-3">
                        @include('filament.widgets.pu-calculator.partials.counter-strip', [
                            'indicators' => $queueClear,
                            'focusedState' => $focusedState,
                            'label' => 'Sem ocorrências',
                        ])
                    </div>
                @endif
            @else
                <div class="flex flex-col gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300">
                            <x-heroicon-o-check-circle class="size-5" aria-hidden="true" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-950 dark:text-white">Fila ociosa e sem falhas</span>
                            <span class="bsi-pu-note mt-0.5 block text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                                Nenhum job pendente, travado ou com falha na geração e validação de curvas.
                            </span>
                        </span>
                    </div>

                    @include('filament.widgets.pu-calculator.partials.counter-strip', [
                        'indicators' => $queue,
                        'focusedState' => $focusedState,
                        'label' => null,
                    ])
                </div>
            @endif
        </x-filament::section>

        {{-- Recorte ativo: contrato explicito entre indicadores e tabela --}}
        @if ($focusedIndicator !== null)
            <div
                data-pu-focus="{{ $focusedState }}"
                class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-bsi-gold-500/40 bg-bsi-gold-500/8 px-4 py-2.5 dark:bg-bsi-gold-500/10"
            >
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-bsi-gold-600 dark:text-bsi-gold-500">
                    <x-heroicon-m-funnel class="size-3.5" aria-hidden="true" />
                    Recorte ativo
                </span>

                <span class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-200">
                    A tabela abaixo mostra apenas <strong class="font-semibold text-gray-950 dark:text-white">{{ $focusedIndicator['label'] }}</strong>.
                </span>

                <button
                    type="button"
                    wire:click="focusState('{{ $focusedState }}')"
                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-gray-700 transition-colors duration-150 hover:bg-black/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-bsi-gold-600 motion-reduce:transition-none dark:text-gray-200 dark:hover:bg-white/5"
                >
                    <x-heroicon-m-x-mark class="size-3.5" aria-hidden="true" />
                    Limpar recorte
                </button>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>

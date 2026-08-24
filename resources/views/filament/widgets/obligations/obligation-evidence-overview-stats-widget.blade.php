@php
    $heading = $this->getHeading() ?? 'Situação Documental';
    $description = $this->getDescription() ?? 'Comprovação documental, pendências de revisão e lacunas de evidência. Apenas evidência aprovada conta como comprovação válida.';
    $summary = $this->getSummaryData();
    $primaryCards = $this->getPrimaryCards();
    $secondaryCards = $this->getSecondaryCards();
@endphp

<x-filament-widgets::widget class="bsi-cockpit-widget bsi-obligation-evidence-widget">
    <x-filament::section
        :heading="$heading"
        :description="$description"
        icon="heroicon-o-document-check"
        icon-color="primary"
    >
        <x-slot name="afterHeader">
            <div class="hidden sm:flex flex-wrap items-center gap-2 text-xs">
                @if((int) $summary['sem_evidencia_aprovada'] > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-danger-300/80 bg-danger-50 px-2.5 py-0.5 font-medium text-danger-800 dark:border-danger-500/30 dark:bg-danger-500/15 dark:text-danger-200">
                        <span class="size-1.5 rounded-full bg-danger-500"></span>
                        <span><strong class="font-semibold">{{ $summary['sem_evidencia_aprovada_formatted'] }}</strong> sem comprovação válida</span>
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-300/80 bg-emerald-50 px-2.5 py-0.5 font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-200">
                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                        <span>100% com evidência válida</span>
                    </span>
                @endif

                @if((int) $summary['com_evidencia_pendente'] > 0)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-300/80 bg-amber-50 px-2.5 py-0.5 font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-200">
                        <span class="size-1.5 rounded-full bg-amber-500"></span>
                        <span><strong class="font-semibold">{{ $summary['com_evidencia_pendente_formatted'] }}</strong> em revisão</span>
                    </span>
                @endif
            </div>
        </x-slot>

        <div class="space-y-4">
            {{-- 1. Nível Primário: Status Documental Principal --}}
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Status Principal de Comprovação
                    </span>
                    <span class="text-[0.6875rem] text-gray-400 dark:text-gray-500">
                        Aprovadas, Pendentes e Rejeitadas
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($primaryCards as $card)
                        @php
                            $tone = $card['tone'];
                            $toneClasses = match ($tone) {
                                'danger' => [
                                    'bar' => 'bg-danger-500',
                                    'border' => 'border-danger-300/60 dark:border-danger-500/30',
                                    'icon' => 'bg-danger-500/10 text-danger-700 dark:bg-danger-500/20 dark:text-danger-300',
                                    'badge' => 'border border-danger-300/70 bg-danger-50 text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/15 dark:text-danger-300',
                                    'value' => 'text-gray-950 dark:text-white',
                                    'desc' => 'text-gray-600 dark:text-gray-300',
                                ],
                                'warning' => [
                                    'bar' => 'bg-amber-500',
                                    'border' => 'border-amber-300/60 dark:border-amber-500/30',
                                    'icon' => 'bg-amber-500/10 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
                                    'badge' => 'border border-amber-300/70 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300',
                                    'value' => 'text-gray-950 dark:text-white',
                                    'desc' => 'text-gray-600 dark:text-gray-300',
                                ],
                                'success' => [
                                    'bar' => 'bg-emerald-500',
                                    'border' => 'border-emerald-300/60 dark:border-emerald-500/30',
                                    'icon' => 'bg-emerald-500/10 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
                                    'badge' => 'border border-emerald-300/70 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300',
                                    'value' => 'text-gray-950 dark:text-white',
                                    'desc' => 'text-gray-600 dark:text-gray-300',
                                ],
                                default => [
                                    'bar' => 'bg-gray-400',
                                    'border' => 'border-gray-200/90 dark:border-white/10',
                                    'icon' => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
                                    'badge' => 'border border-gray-200 bg-bsi-paper text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400',
                                    'value' => 'text-gray-950 dark:text-white',
                                    'desc' => 'text-gray-500 dark:text-gray-400',
                                ],
                            };
                        @endphp

                        <div
                            class="bsi-obligation-stat-card group relative flex flex-col justify-between overflow-hidden rounded-xl border {{ $toneClasses['border'] }} bg-bsi-paper p-4 shadow-sm transition-[border-color,background-color,box-shadow,transform] duration-200 ease-out hover:-translate-y-0.5 hover:border-bsi-gold-500/60 hover:bg-white hover:shadow-md dark:bg-[#0d252e] dark:hover:border-bsi-gold-500/50 dark:hover:bg-[#12313b]"
                        >
                            {{-- Filete Semântico Superior --}}
                            <div class="absolute inset-x-0 top-0 h-[2.5px] {{ $toneClasses['bar'] }}"></div>

                            {{-- Topo --}}
                            <div>
                                <div class="flex items-center justify-between gap-2">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl {{ $toneClasses['icon'] }} transition-transform duration-200 group-hover:scale-105">
                                        <x-dynamic-component :component="$card['icon']" class="size-4.5" aria-hidden="true" />
                                    </span>

                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[0.6875rem] font-semibold {{ $toneClasses['badge'] }}">
                                        {{ $card['badge'] }}
                                    </span>
                                </div>

                                <div class="mt-3">
                                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white" title="{{ $card['label'] }}">
                                        {{ $card['label'] }}
                                    </h3>
                                </div>
                            </div>

                            {{-- Meio: Número Principal (Off-White) --}}
                            <div class="my-2.5">
                                <span class="block text-3xl font-bold tracking-tight tabular-nums {{ $toneClasses['value'] }}">
                                    {{ $card['value'] }}
                                </span>
                            </div>

                            {{-- Rodapé --}}
                            <div class="border-t border-gray-100 pt-2 dark:border-white/5">
                                <p class="text-xs leading-relaxed {{ $toneClasses['desc'] }}">
                                    {{ $card['description'] }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- 2. Nível Secundário: Cruzamentos e Casos Especiais --}}
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-[0.6875rem] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Cruzamentos & Validação de Conclusões
                    </span>
                    <span class="text-[0.6875rem] text-gray-400 dark:text-gray-500">
                        Auditoria e Fluxos em Validação
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($secondaryCards as $item)
                        @php
                            $tone = $item['tone'];
                            $itemClasses = match ($tone) {
                                'danger' => [
                                    'dot' => 'bg-danger-500',
                                    'border' => 'border-danger-300/40 dark:border-danger-500/20',
                                    'tag' => 'text-danger-700 dark:text-danger-300',
                                ],
                                'warning' => [
                                    'dot' => 'bg-amber-500',
                                    'border' => 'border-amber-300/40 dark:border-amber-500/20',
                                    'tag' => 'text-amber-700 dark:text-amber-300',
                                ],
                                'success' => [
                                    'dot' => 'bg-emerald-500',
                                    'border' => 'border-emerald-300/40 dark:border-emerald-500/20',
                                    'tag' => 'text-emerald-700 dark:text-emerald-300',
                                ],
                                default => [
                                    'dot' => 'bg-gray-400',
                                    'border' => 'border-gray-200/80 dark:border-white/10',
                                    'tag' => 'text-gray-500 dark:text-gray-400',
                                ],
                            };
                        @endphp

                        <div class="flex flex-col justify-between rounded-lg border {{ $itemClasses['border'] }} bg-bsi-paper/80 p-3 shadow-none transition-colors duration-150 hover:border-bsi-gold-500/50 hover:bg-white dark:bg-[#0a2028]/70 dark:hover:bg-[#0d252e]">
                            <div class="flex items-center justify-between gap-1.5">
                                <span class="truncate text-xs font-semibold text-gray-900 dark:text-gray-200" title="{{ $item['label'] }}">
                                    {{ $item['label'] }}
                                </span>
                                <span class="size-1.5 shrink-0 rounded-full {{ $itemClasses['dot'] }}" aria-hidden="true"></span>
                            </div>

                            <div class="my-1.5 flex items-baseline justify-between gap-2">
                                <span class="text-xl font-bold tabular-nums text-gray-950 dark:text-white">
                                    {{ $item['value'] }}
                                </span>
                                <span class="text-[0.6875rem] font-medium {{ $itemClasses['tag'] }}">
                                    {{ $item['tag'] }}
                                </span>
                            </div>

                            <div class="border-t border-gray-100 pt-1.5 dark:border-white/5">
                                <p class="truncate text-[0.6875rem] text-gray-500 dark:text-gray-400" title="{{ $item['description'] }}">
                                    {{ $item['description'] }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

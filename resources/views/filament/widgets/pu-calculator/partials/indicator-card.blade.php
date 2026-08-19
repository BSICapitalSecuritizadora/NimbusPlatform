@php
    $tone = $toneClasses[$indicator['tone']] ?? $toneClasses['neutral'];
    $isFocusable = filled($indicator['focus'] ?? null);
    $isActive = $isFocusable && $focusedState === $indicator['focus'];
@endphp

@if ($isFocusable)
    <button
        type="button"
        wire:key="pu-indicator-{{ $indicator['key'] }}"
        wire:click="focusState('{{ $indicator['focus'] }}')"
        x-on:click="document.getElementById('pu-curvas-por-emissao')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
        aria-label="{{ $indicator['value'] }} {{ $indicator['label'] }} — {{ $isActive ? 'remover recorte da tabela' : 'filtrar a tabela de curvas' }}"
        @class([
            'bsi-pu-card group flex w-full flex-col items-start gap-2 rounded-xl border bg-bsi-paper p-4 text-left transition-[border-color,background-color,box-shadow] duration-200 ease-out hover:border-bsi-gold-500 hover:bg-bsi-stone-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-bsi-gold-600 motion-reduce:transition-none dark:bg-bsi-navy-900/60 dark:hover:bg-bsi-navy-800/70',
            $tone['border'] => ! $isActive,
            'bsi-pu-card--active border-bsi-gold-500 bg-bsi-stone-50 dark:bg-bsi-navy-800/70' => $isActive,
        ])
    >
@else
    <div
        wire:key="pu-indicator-{{ $indicator['key'] }}"
        class="bsi-pu-card flex w-full flex-col items-start gap-2 rounded-xl border bg-bsi-paper p-4 dark:bg-bsi-navy-900/60 {{ $tone['border'] }}"
    >
@endif

        <span class="flex w-full items-center justify-between gap-2">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $tone['surface'] }}">
                <x-dynamic-component :component="$indicator['icon']" class="size-4" aria-hidden="true" />
            </span>

            @if ($isFocusable)
                <span class="bsi-pu-card-hint inline-flex items-center gap-1 text-[0.6875rem] font-semibold {{ $isActive ? 'text-bsi-gold-600 dark:text-bsi-gold-500' : 'text-gray-400 opacity-0 transition-opacity duration-200 group-hover:opacity-100 group-focus-visible:opacity-100 dark:text-gray-500' }}">
                    {{ $isActive ? 'Recorte ativo' : 'Filtrar' }}
                    <x-heroicon-m-funnel class="size-3" aria-hidden="true" />
                </span>
            @endif
        </span>

        <span class="text-3xl font-semibold leading-none tabular-nums {{ $indicator['value'] > 0 ? $tone['value'] : 'text-gray-900 dark:text-white' }}">
            {{ $indicator['value'] }}
        </span>

        <span class="block w-full">
            <span class="block text-sm font-semibold leading-snug text-gray-950 dark:text-white">{{ $indicator['label'] }}</span>
            <span class="bsi-pu-card-note mt-1 block text-xs leading-relaxed text-gray-600 dark:text-gray-300">{{ $indicator['description'] }}</span>
        </span>

@if ($isFocusable)
    </button>
@else
    </div>
@endif

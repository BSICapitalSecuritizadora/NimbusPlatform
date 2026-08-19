@php
    $stripLabel = $label ?? null;
@endphp

<div class="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-xl border border-gray-200 bg-bsi-stone-50 px-4 py-3 dark:border-gray-700/60 dark:bg-bsi-navy-900/40">
    @if (filled($stripLabel))
        <span class="inline-flex items-center gap-1.5 text-[0.6875rem] font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
            <x-heroicon-m-check class="size-3.5 text-success-600 dark:text-success-400" aria-hidden="true" />
            {{ $stripLabel }}
        </span>
    @endif

    @foreach ($indicators as $indicator)
        @php
            $isFocusable = filled($indicator['focus'] ?? null);
            $isActive = $isFocusable && $focusedState === $indicator['focus'];
        @endphp

        @if ($isFocusable)
            <button
                type="button"
                wire:key="pu-counter-{{ $indicator['key'] }}"
                wire:click="focusState('{{ $indicator['focus'] }}')"
                x-on:click="document.getElementById('pu-curvas-por-emissao')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                @class([
                    'inline-flex items-baseline gap-1.5 rounded-md px-1.5 py-0.5 -mx-1.5 text-xs transition-colors duration-150 hover:bg-black/[0.04] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-bsi-gold-600 motion-reduce:transition-none dark:hover:bg-white/5',
                    'text-gray-600 dark:text-gray-400' => ! $isActive,
                    'bg-bsi-gold-500/10 text-bsi-gold-600 dark:text-bsi-gold-500' => $isActive,
                ])
                title="{{ $indicator['description'] }}"
            >
                <span class="text-sm font-semibold tabular-nums {{ $isActive ? 'text-bsi-gold-600 dark:text-bsi-gold-500' : 'text-gray-800 dark:text-gray-100' }}">{{ $indicator['value'] }}</span>
                {{ $indicator['label'] }}
            </button>
        @else
            <span
                wire:key="pu-counter-{{ $indicator['key'] }}"
                class="inline-flex items-baseline gap-1.5 text-xs text-gray-600 dark:text-gray-400"
                title="{{ $indicator['description'] }}"
            >
                <span class="text-sm font-semibold tabular-nums text-gray-800 dark:text-gray-100">{{ $indicator['value'] }}</span>
                {{ $indicator['label'] }}
            </span>
        @endif
    @endforeach
</div>

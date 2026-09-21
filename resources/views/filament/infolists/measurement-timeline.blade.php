@php
    $record = $getRecord();
    $events = app(\App\Services\MeasurementTimeline::class)->for($record);
    $isOpen = $record->isOpen();
@endphp

<div class="bsi-timeline py-2">
    @forelse ($events as $index => $event)
        @php
            $isLast = $loop->last;
            $isCurrent = $isLast && $isOpen;

            if ($isCurrent) {
                $nodeClass = 'border-2 border-[#A06E28] bg-amber-50 text-[#A06E28] ring-4 ring-[#A06E28]/15 dark:border-bsi-gold-500 dark:bg-bsi-gold-500/20 dark:text-bsi-gold-500 dark:ring-bsi-gold-500/20 shadow-xs';
            } elseif ($event['color'] === 'success') {
                $nodeClass = 'border border-emerald-300 bg-emerald-50 text-emerald-600 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-400';
            } elseif ($event['color'] === 'danger') {
                $nodeClass = 'border border-red-300 bg-red-50 text-red-600 dark:border-red-500/30 dark:bg-red-500/15 dark:text-red-400';
            } elseif ($event['color'] === 'warning') {
                $nodeClass = 'border border-amber-300 bg-amber-50 text-amber-600 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-400';
            } else {
                $nodeClass = 'border border-gray-200 bg-gray-50 text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400';
            }
        @endphp

        <div class="relative flex gap-3.5 pb-6 last:pb-0">
            {{-- Linha vertical com gradiente institucional sutil --}}
            @unless ($isLast)
                <div class="absolute left-3.5 top-7 -bottom-1 w-0.5 bg-gradient-to-b from-gray-200 to-gray-200 dark:from-white/10 dark:to-white/5" aria-hidden="true"></div>
            @endunless

            {{-- Nó do evento --}}
            <div class="relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full {{ $nodeClass }}">
                <x-filament::icon :icon="$event['icon']" class="size-3.5" />
            </div>

            {{-- Conteúdo do evento --}}
            <div class="min-w-0 flex-1 pt-0.5">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold text-gray-950 dark:text-white">
                        {{ $event['title'] }}
                    </span>

                    @if ($isCurrent)
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-[#A06E28] border border-amber-200/80 dark:bg-bsi-gold-500/20 dark:text-bsi-gold-400 dark:border-bsi-gold-500/30">
                            Etapa Atual
                        </span>
                    @endif
                </div>

                @if (filled($event['detail']))
                    <p class="mt-0.5 max-w-4xl text-xs text-gray-600 dark:text-gray-300">
                        {{ $event['detail'] }}
                    </p>
                @endif

                <div class="mt-1 flex items-center gap-1.5 text-[11px] text-gray-400 dark:text-gray-500">
                    <x-heroicon-o-clock class="size-3 shrink-0" />
                    <span>{{ $event['at']->format('d/m/Y H:i') }}</span>
                    @if (filled($event['actor']))
                        <span class="text-gray-300 dark:text-white/20">•</span>
                        <span>{{ $event['actor'] }}</span>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="py-4 text-center text-xs text-gray-400 dark:text-gray-500">
            Nenhum evento registrado nesta medição até o momento.
        </div>
    @endforelse
</div>

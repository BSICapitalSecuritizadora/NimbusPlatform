@php
    $events = app(\App\Services\MeasurementTimeline::class)->for($getRecord());

    $colors = [
        'success' => 'text-green-500',
        'danger' => 'text-red-500',
        'warning' => 'text-amber-500',
        'info' => 'text-blue-500',
        'gray' => 'text-gray-400',
    ];
@endphp

<div class="fi-in-timeline">
    @forelse ($events as $event)
        <div class="flex gap-3 pb-4 last:pb-0">
            <div class="flex flex-col items-center">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-white/5 {{ $colors[$event['color']] ?? $colors['gray'] }}">
                    <x-filament::icon :icon="$event['icon']" class="h-5 w-5" />
                </span>
                @unless ($loop->last)
                    <span class="mt-1 w-px flex-1 bg-gray-200 dark:bg-white/10"></span>
                @endunless
            </div>

            <div class="flex-1 pt-1">
                <p class="text-sm font-medium text-gray-950 dark:text-white">
                    {{ $event['title'] }}
                </p>

                @if (filled($event['detail']))
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $event['detail'] }}</p>
                @endif

                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                    {{ $event['at']->format('d/m/Y H:i') }}
                    @if (filled($event['actor']))
                        • {{ $event['actor'] }}
                    @endif
                </p>
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum evento registrado ainda.</p>
    @endforelse
</div>

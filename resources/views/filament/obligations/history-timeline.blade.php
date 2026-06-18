@php
    /** @var \App\Models\Obligation $obligation */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ObligationHistoryEntry> $entries */

    $eventColors = [
        'created' => 'primary',
        'generated_from_term' => 'info',
        'updated' => 'gray',
        'status_changed' => 'warning',
        'due_date_changed' => 'warning',
        'responsible_changed' => 'info',
        'recalculated_status' => 'warning',
        'completed' => 'success',
        'waived' => 'gray',
        'notification_sent' => 'success',
        'notification_failed' => 'danger',
    ];
@endphp

<div class="fi-obligation-history">
    @if ($entries->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Nenhum evento registrado para esta obrigação ainda.
        </p>
    @else
        <ol class="relative space-y-5 border-s border-gray-200 ps-5 dark:border-gray-700">
            @foreach ($entries as $entry)
                @php $color = $eventColors[$entry->event_type] ?? 'gray'; @endphp
                <li class="relative">
                    <span @class([
                        'absolute -start-[1.6rem] mt-1.5 size-3 rounded-full ring-4 ring-white dark:ring-gray-900',
                        'bg-primary-500' => $color === 'primary',
                        'bg-info-500' => $color === 'info',
                        'bg-success-500' => $color === 'success',
                        'bg-warning-500' => $color === 'warning',
                        'bg-danger-500' => $color === 'danger',
                        'bg-gray-400' => $color === 'gray',
                    ])></span>

                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::badge :color="$color">
                            {{ $entry->event_label }}
                        </x-filament::badge>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $entry->occurred_at?->format('d/m/Y H:i') }}
                        </span>
                        <span class="text-xs text-gray-400 dark:text-gray-500">
                            &middot; {{ $entry->source_label }}
                        </span>
                    </div>

                    @if (filled($entry->description))
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">
                            {{ $entry->description }}
                        </p>
                    @endif

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Responsável: {{ $entry->actor_label }}
                    </p>

                    @if (filled($entry->metadata['confidence_score'] ?? null))
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            Confiança da extração: {{ round(((float) $entry->metadata['confidence_score']) * 100) }}%
                        </p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>

@php
    /** @var \App\Models\LegalInstrument $instrument */
    $events = $instrument->events->sortBy(fn ($event) => $event->effective_date?->toDateString() ?? '');
@endphp

<div class="space-y-4 text-sm">
    <p class="text-gray-400">
        Cada alteração confirmada gera um evento. Nada é sobrescrito — a posição anterior continua consultável.
    </p>

    @if ($events->isEmpty())
        <div class="rounded-xl border border-dashed border-white/10 bg-white/[0.02] p-4 text-gray-400">
            Nenhum evento registrado ainda.
        </div>
    @else
        <ol class="space-y-4 border-l border-white/10 pl-4">
            @foreach ($events as $event)
                <li>
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="font-medium">{{ $event->effective_date?->format('d/m/Y') ?? 'Sem data' }}</span>
                        <span class="rounded-full bg-white/[0.05] px-2 py-0.5 text-xs">
                            {{ $event->event_type?->label() }}
                        </span>
                    </div>

                    <div class="mt-1 text-xs text-gray-400">{{ $event->title }}</div>

                    @foreach ($event->change_list as $change)
                        <div class="mt-1 text-xs">
                            <span class="text-gray-400">{{ $change['label'] }}:</span>
                            <span class="text-gray-500">{{ $change['from'] ?? 'sem valor anterior' }}</span>
                            →
                            <span class="font-medium">{{ $change['to'] }}</span>
                            @if (filled($change['clause'] ?? null))
                                <span class="text-gray-500">
                                    · Cláusula {{ $change['clause'] }}@if (filled($change['page'] ?? null)), página {{ $change['page'] }}@endif
                                </span>
                            @endif
                        </div>
                    @endforeach

                    <div class="mt-1 text-xs text-gray-500">
                        @if ($event->instrumentDocument)
                            Documento: {{ $event->instrumentDocument->title }}
                        @endif
                        @if ($event->recordedBy)
                            · Confirmado por {{ $event->recordedBy->name }}
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>

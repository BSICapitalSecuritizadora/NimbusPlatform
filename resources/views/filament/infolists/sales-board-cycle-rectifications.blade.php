@php
    /**
     * As retificações da competência, da mais recente para a mais antiga: quem
     * pediu e por quê, a publicação que ela substitui e como terminou.
     */
    $rectifications = $getRecord()->rectifications()
        ->with(['requestedBy', 'closedBy', 'openingBaseline', 'rectifiedPublication', 'resultingPublication'])
        ->get();
@endphp

<div class="space-y-3" data-sales-board-cycle-rectifications>
    @foreach ($rectifications as $rectification)
        <div wire:key="rectification-{{ $rectification->getKey() }}" @class([
            'rounded-lg border p-3',
            'border-warning-300 dark:border-warning-700' => $rectification->isOpen(),
            'border-gray-200 dark:border-gray-700' => ! $rectification->isOpen(),
        ])>
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">Retificação {{ $rectification->sequence_number }}</p>
                <x-filament::badge :color="$rectification->status->color()" size="sm">{{ $rectification->status->label() }}</x-filament::badge>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    pedida por {{ $rectification->requestedBy?->name ?? 'usuário não registrado' }}
                    em {{ \App\Support\BusinessTime::at($rectification->requested_at)->format('d/m/Y \à\s H:i') }}
                    · versão aberta {{ $rectification->openingBaseline?->versionLabel() ?? '—' }}
                </span>
            </div>

            <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">
                <span class="font-medium">Motivo:</span> {{ $rectification->reason }}
            </p>

            @if ($rectification->closed_at !== null)
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $rectification->status === \App\Enums\SalesBoardRectificationStatus::Published ? 'Publicada' : 'Desistida' }}
                    por {{ $rectification->closedBy?->name ?? 'usuário não registrado' }}
                    em {{ \App\Support\BusinessTime::at($rectification->closed_at)->format('d/m/Y \à\s H:i') }}@if (filled($rectification->closing_reason)): {{ $rectification->closing_reason }}@endif
                </p>
            @else
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Em andamento: a posição publicada continua valendo até a aprovação da Gestão -- ou a desistência.
                </p>
            @endif
        </div>
    @endforeach
</div>

<x-filament-panels::header
    :actions="$this->getCachedHeaderActions()"
    :actions-alignment="$this->getHeaderActionsAlignment()"
    :breadcrumbs="filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : []"
>
    <x-slot name="heading">
        <span class="bsi-cycle-title">
            <span>{{ $this->getHeading() }}</span>
            <x-filament::badge :color="$record->status->color()" size="sm">
                {{ $record->status->label() }}
            </x-filament::badge>
        </span>
    </x-slot>

    <x-slot name="subheading">
        <span class="bsi-cycle-context">
            Posição da {{ $record->emission?->name ?? '—' }} para {{ $record->referenceMonthLabel() }}
        </span>
        <span class="bsi-cycle-description">{{ $this->getSubheading() }}</span>
        @if ($record->updated_at)
            <span class="bsi-cycle-updated">
                Atualizada <time datetime="{{ $record->updated_at->toIso8601String() }}" title="{{ $record->updated_at->format('d/m/Y \à\s H:i') }}">{{ $record->updated_at->diffForHumans() }}</time>
            </span>
        @endif
    </x-slot>
</x-filament-panels::header>

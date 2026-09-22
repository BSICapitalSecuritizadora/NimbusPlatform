<x-filament-panels::header
    :actions="$this->getCachedHeaderActions()"
    :actions-alignment="$this->getHeaderActionsAlignment()"
    :breadcrumbs="filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : []"
>
    <x-slot name="heading">
        <span class="bsi-rollout-title">
            <span>{{ $this->getHeading() }}</span>
            <x-filament::badge :color="$emission->sales_board_source->color()">
                {{ $emission->sales_board_source->label() }}
            </x-filament::badge>
        </span>
    </x-slot>

    <x-slot name="subheading">
        <span class="bsi-rollout-context">
            {{ $emission->name }}
        </span>
        <span class="bsi-rollout-description">{{ $this->getSubheading() }}</span>
    </x-slot>
</x-filament-panels::header>

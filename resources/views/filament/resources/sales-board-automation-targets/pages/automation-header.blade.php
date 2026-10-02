{{--
    Cabeçalho da "Automação do Quadro": estado da última execução, sinal de vida
    do agendador e da fila, política de avisos em vigor e avisos da tela. Tudo
    em texto; a cor só acompanha o sinal vencido.
--}}
<x-filament-panels::header
    :actions="$this->getCachedHeaderActions()"
    :actions-alignment="$this->getHeaderActionsAlignment()"
    :breadcrumbs="filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : []"
    :heading="$this->getHeading()"
>
    <x-slot name="subheading">
        <span class="bsi-automation-state">{{ $this->getSubheading() }}</span>

        @foreach ($heartbeats as $heartbeat)
            <span
                role="note"
                @class([
                    'bsi-automation-heartbeat',
                    'bsi-automation-heartbeat-stale text-danger-700 dark:text-danger-400' => $heartbeat['healthy'] === false,
                ])
            >{{ $heartbeat['text'] }}</span>
        @endforeach

        <span class="bsi-automation-policy" role="note">{{ $reminderPolicy }}</span>

        @foreach ($notices as $notice)
            <span class="bsi-automation-notice" role="note">{{ $notice }}</span>
        @endforeach
    </x-slot>
</x-filament-panels::header>

<x-filament-widgets::widget class="bsi-cockpit-widget bsi-cockpit-shortcuts">
    <x-filament::section
        heading="Ações rápidas"
        description="Atalhos para os fluxos mais usados no dia a dia."
        icon="heroicon-o-bolt"
        icon-color="primary"
    >
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            @if(auth()->user()->can('emissions.create'))
                <a
                    href="{{ \App\Filament\Resources\Emissions\EmissionResource::getUrl('create') }}"
                    class="bsi-cockpit-action group flex min-h-20 items-center gap-3 rounded-xl bg-bsi-navy-900 px-4 py-3 text-bsi-paper shadow-[0_10px_24px_rgba(9,27,35,0.16)] transition-[background-color,box-shadow] duration-200 ease-out hover:bg-bsi-navy-800 hover:shadow-[0_14px_28px_rgba(9,27,35,0.22)] focus-visible:outline-2 focus-visible:outline-offset-3 focus-visible:outline-bsi-gold-500 motion-reduce:transition-none"
                    aria-label="Nova emissão — cadastrar uma nova operação"
                >
                    <x-heroicon-o-plus-circle class="size-5 shrink-0 text-bsi-gold-500" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold">Nova emissão</span>
                        <span class="mt-0.5 block text-xs leading-snug text-bsi-paper/70">Cadastrar uma nova operação</span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-bsi-gold-500" aria-hidden="true" />
                </a>
            @endif

            @if(auth()->user()->can('proposals.view'))
                <a
                    href="{{ \App\Filament\Resources\Proposals\ProposalResource::getUrl('index') }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut group"
                    aria-label="Ver propostas — acompanhar o fluxo comercial"
                >
                    <x-heroicon-o-document-text class="size-5 shrink-0 text-info-700 dark:text-info-300" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Ver propostas</span>
                        <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">Acompanhar o fluxo comercial</span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-gray-400 transition-colors group-hover:text-primary-600 dark:group-hover:text-primary-300" aria-hidden="true" />
                </a>
            @endif

            @if(\App\Filament\Pages\ObligationDashboard::canAccess())
                <a
                    href="{{ \App\Filament\Pages\ObligationDashboard::getUrl(['filters' => ['due_window' => 'overdue']]) }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut group"
                    aria-label="Obrigações vencidas — priorizar prazos expirados"
                >
                    <x-heroicon-o-exclamation-triangle class="size-5 shrink-0 text-danger-600 dark:text-danger-300" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Obrigações vencidas</span>
                        <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">Priorizar prazos expirados</span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-gray-400 transition-colors group-hover:text-danger-600 dark:group-hover:text-danger-300" aria-hidden="true" />
                </a>
            @endif

            @if(auth()->user()->can('funds.view'))
                <a
                    href="{{ \App\Filament\Resources\Funds\FundResource::getUrl('index') }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut group"
                    aria-label="Ver fundos — consultar cadastros financeiros"
                >
                    <x-heroicon-o-banknotes class="size-5 shrink-0 text-primary-700 dark:text-primary-300" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Ver fundos</span>
                        <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">Consultar cadastros financeiros</span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-gray-400 transition-colors group-hover:text-primary-600 dark:group-hover:text-primary-300" aria-hidden="true" />
                </a>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

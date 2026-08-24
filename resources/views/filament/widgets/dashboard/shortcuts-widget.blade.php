<x-filament-widgets::widget class="bsi-cockpit-widget bsi-cockpit-shortcuts">
    <x-filament::section
        heading="Ações rápidas"
        description="Atalhos para os fluxos mais usados no dia a dia."
        icon="heroicon-o-bolt"
        icon-color="primary"
    >
        <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
            @if(auth()->user()->can('emissions.create'))
                <a
                    href="{{ \App\Filament\Resources\Emissions\EmissionResource::getUrl('create') }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut bsi-cockpit-shortcut--primary group w-full min-w-0"
                    aria-label="Nova emissão — cadastrar uma nova operação"
                >
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-bsi-gold-500/15 text-bsi-gold-500">
                        <x-heroicon-o-plus-circle class="size-5 shrink-0 text-bsi-gold-500" aria-hidden="true" />
                    </div>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-bsi-paper">Nova emissão</span>
                        <span class="mt-0.5 block text-xs leading-snug text-bsi-paper/70">Cadastrar uma nova operação</span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-bsi-gold-500 transition-transform duration-200 ease-out group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true" />
                </a>
            @endif

            @if(auth()->user()->can('proposals.view'))
                <a
                    href="{{ \App\Filament\Resources\Proposals\ProposalResource::getUrl('index') }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut group w-full min-w-0"
                    aria-label="Ver propostas — acompanhar o fluxo comercial"
                >
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-info-500/10 text-info-600 dark:bg-info-500/15 dark:text-info-400">
                        <x-heroicon-o-document-text class="size-5 shrink-0" aria-hidden="true" />
                    </div>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Ver propostas</span>
                        <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">Acompanhar o fluxo comercial</span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-gray-400 transition-all duration-200 ease-out group-hover:translate-x-0.5 group-hover:text-primary-600 dark:group-hover:text-primary-300 motion-reduce:transition-none" aria-hidden="true" />
                </a>
            @endif

            @if(\App\Filament\Pages\ObligationDashboard::canAccess())
                <a
                    href="{{ \App\Filament\Pages\ObligationDashboard::getUrl(['filters' => ['due_window' => 'overdue']]) }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut group w-full min-w-0"
                    aria-label="Obrigações vencidas — priorizar prazos expirados"
                >
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-danger-500/10 text-danger-600 dark:bg-danger-500/15 dark:text-danger-400">
                        <x-heroicon-o-exclamation-triangle class="size-5 shrink-0" aria-hidden="true" />
                    </div>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Obrigações vencidas</span>
                        <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">Priorizar prazos expirados</span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-gray-400 transition-all duration-200 ease-out group-hover:translate-x-0.5 group-hover:text-danger-600 dark:group-hover:text-danger-300 motion-reduce:transition-none" aria-hidden="true" />
                </a>
            @endif

            @if(auth()->user()->can('funds.view'))
                <a
                    href="{{ \App\Filament\Resources\Funds\FundResource::getUrl('index') }}"
                    class="bsi-cockpit-action bsi-cockpit-shortcut group w-full min-w-0"
                    aria-label="Ver fundos — consultar cadastros financeiros"
                >
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-500/10 text-primary-600 dark:bg-primary-500/15 dark:text-primary-400">
                        <x-heroicon-o-banknotes class="size-5 shrink-0" aria-hidden="true" />
                    </div>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">Ver fundos</span>
                        <span class="mt-0.5 block text-xs leading-snug text-gray-600 dark:text-gray-300">Consultar cadastros financeiros</span>
                    </span>
                    <x-heroicon-o-chevron-right class="bsi-cockpit-action-arrow size-4 shrink-0 text-gray-400 transition-all duration-200 ease-out group-hover:translate-x-0.5 group-hover:text-primary-600 dark:group-hover:text-primary-300 motion-reduce:transition-none" aria-hidden="true" />
                </a>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

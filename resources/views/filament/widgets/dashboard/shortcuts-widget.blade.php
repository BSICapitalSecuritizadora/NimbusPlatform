<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-col gap-y-4">
            <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                Ações rápidas
            </h3>
            
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @if(auth()->user()->can('emissions.create'))
                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Resources\Emissions\EmissionResource::getUrl('create') }}"
                    color="primary"
                    icon="heroicon-o-plus-circle"
                    class="w-full"
                >
                    Nova Emissão
                </x-filament::button>
                @endif
                
                @if(auth()->user()->can('proposals.view'))
                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Resources\Proposals\ProposalResource::getUrl('index') }}"
                    color="gray"
                    icon="heroicon-o-document-text"
                    class="w-full"
                >
                    Ver Propostas
                </x-filament::button>
                @endif

                @if(\App\Filament\Pages\ObligationDashboard::canAccess())
                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Pages\ObligationDashboard::getUrl(['filters' => ['due_window' => 'overdue']]) }}"
                    color="danger"
                    icon="heroicon-o-exclamation-triangle"
                    class="w-full"
                >
                    Obrigações Vencidas
                </x-filament::button>
                @endif
                
                @if(auth()->user()->can('funds.view'))
                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Resources\Funds\FundResource::getUrl('index') }}"
                    color="info"
                    icon="heroicon-o-banknotes"
                    class="w-full"
                >
                    Ver Fundos
                </x-filament::button>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

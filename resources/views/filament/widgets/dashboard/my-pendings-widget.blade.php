<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold leading-6 text-gray-950 dark:text-white flex items-center gap-2">
                <x-heroicon-o-clipboard-document-check class="w-6 h-6 text-primary-500" />
                Minhas Pendências
            </h3>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Obrigações Pendentes -->
            <div>
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3 border-b pb-2">
                    Obrigações sob minha responsabilidade
                </h4>
                @if($obligations->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma obrigação pendente.</p>
                @else
                    <ul class="space-y-3">
                        @foreach($obligations as $obligation)
                            <li class="flex items-start justify-between gap-2 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-100 dark:border-gray-700">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $obligation->title }}
                                    </span>
                                    <span class="text-xs text-gray-500">
                                        Vencimento: {{ $obligation->due_date ? $obligation->due_date->format('d/m/Y') : 'Sem prazo' }}
                                    </span>
                                </div>
                                <x-filament::button
                                    tag="a"
                                    href="{{ \App\Filament\Resources\Emissions\EmissionResource::getUrl('edit', ['record' => $obligation->emission_id, 'relation' => \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager::class]) }}"
                                    size="xs"
                                    color="gray"
                                >
                                    Ver
                                </x-filament::button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <!-- Propostas Pendentes -->
            <div>
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3 border-b pb-2">
                    Propostas em andamento
                </h4>
                @if($proposals->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nenhuma proposta sob sua responsabilidade no momento.</p>
                @else
                    <ul class="space-y-3">
                        @foreach($proposals as $proposal)
                            <li class="flex items-start justify-between gap-2 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-100 dark:border-gray-700">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $proposal->company->name ?? 'Empresa não informada' }}
                                    </span>
                                    <span class="text-xs text-gray-500">
                                        Status: {{ \App\Enums\ProposalStatus::labelFor($proposal->status) }}
                                    </span>
                                </div>
                                <x-filament::button
                                    tag="a"
                                    href="{{ \App\Filament\Resources\Proposals\ProposalResource::getUrl('view', ['record' => $proposal->id]) }}"
                                    size="xs"
                                    color="gray"
                                >
                                    Abrir
                                </x-filament::button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

<div class="overflow-x-auto">
    <table class="w-full divide-y divide-gray-200 text-left text-sm dark:divide-white/10">
        <thead>
            <tr class="text-gray-600 dark:text-gray-300">
                <th class="px-3 py-2 font-medium">Competência</th>
                <th class="px-3 py-2 font-medium">Vencimento</th>
                <th class="px-3 py-2 font-medium">Status</th>
                <th class="px-3 py-2 font-medium">Responsável</th>
                <th class="px-3 py-2 font-medium">Evidências</th>
                <th class="px-3 py-2 font-medium">Origem</th>
                <th class="px-3 py-2 font-medium"><span class="sr-only">Ação</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($occurrences as $occurrence)
                <tr wire:key="series-occurrence-{{ $occurrence->id }}">
                    <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">
                        {{ $occurrence->competence_label ?? '—' }}
                    </td>
                    <td class="px-3 py-3 text-gray-700 dark:text-gray-300">
                        {{ $occurrence->due_date?->format('d/m/Y') ?? 'Sem prazo' }}
                    </td>
                    <td class="px-3 py-3 text-gray-700 dark:text-gray-300">
                        {{ $occurrence->status_label }}
                    </td>
                    <td class="px-3 py-3 text-gray-700 dark:text-gray-300">
                        {{ $occurrence->responsibleUser?->name ?? 'Não atribuído' }}
                    </td>
                    <td class="px-3 py-3 text-gray-700 dark:text-gray-300">
                        {{ $occurrence->evidences_count }}
                    </td>
                    <td class="px-3 py-3 text-gray-700 dark:text-gray-300">
                        {{ match ($occurrence->generation_source) {
                            \App\Models\Obligation::GENERATION_SOURCE_AUTOMATIC => 'Automática',
                            \App\Models\Obligation::GENERATION_SOURCE_ON_DEMAND => 'Sob demanda',
                            \App\Models\Obligation::GENERATION_SOURCE_LEGACY => 'Legado',
                            default => 'Manual',
                        } }}
                    </td>
                    <td class="px-3 py-3 text-right">
                        <x-filament::link
                            :href="\App\Filament\Resources\Emissions\EmissionResource::getUrl('edit', ['record' => $occurrence->emission_id, 'relation' => \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager::class])"
                        >
                            Abrir ocorrência
                        </x-filament::link>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                        Nenhuma competência foi materializada para esta recorrência.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

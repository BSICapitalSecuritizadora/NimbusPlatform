<div class="overflow-x-auto rounded-xl border border-[#1d4554]/60 bg-[#081a22] p-2 shadow-sm">
    <table class="w-full text-left text-xs divide-y divide-[#1d4554]/40">
        <thead>
            <tr class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <th class="px-3 py-2.5">Competência</th>
                <th class="px-3 py-2.5">Vencimento</th>
                <th class="px-3 py-2.5">Status</th>
                <th class="px-3 py-2.5">Responsável</th>
                <th class="px-3 py-2.5">Evidências</th>
                <th class="px-3 py-2.5">Origem</th>
                <th class="px-3 py-2.5 text-right"><span class="sr-only">Ação</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[#1d4554]/20">
            @forelse ($occurrences as $occurrence)
                <tr wire:key="series-occurrence-{{ $occurrence->id }}" class="hover:bg-[#0c232e]/60 transition-colors">
                    <td class="px-3 py-2.5 font-bold text-white">
                        {{ $occurrence->competence_label ?? '—' }}
                    </td>
                    <td class="px-3 py-2.5 font-mono text-slate-300">
                        {{ $occurrence->due_date?->format('d/m/Y') ?? 'Sem prazo' }}
                    </td>
                    <td class="px-3 py-2.5">
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium border
                            @if(in_array($occurrence->status, ['em_dia', 'concluida']))
                                bg-emerald-950/60 text-emerald-300 border-emerald-500/30
                            @elseif($occurrence->status === 'vencida')
                                bg-rose-950/60 text-rose-300 border-rose-500/30
                            @elseif($occurrence->status === 'em_analise')
                                bg-amber-950/60 text-amber-300 border-amber-500/30
                            @else
                                bg-sky-950/60 text-sky-300 border-sky-500/30
                            @endif">
                            {{ $occurrence->status_label }}
                        </span>
                    </td>
                    <td class="px-3 py-2.5 text-slate-300">
                        {{ $occurrence->responsibleUser?->name ?? '—' }}
                    </td>
                    <td class="px-3 py-2.5 font-mono text-slate-300">
                        <span class="rounded bg-[#0c232e] px-1.5 py-0.5 border border-[#1d4554]/40">{{ $occurrence->evidences_count }}</span>
                    </td>
                    <td class="px-3 py-2.5 text-slate-400">
                        {{ match ($occurrence->generation_source) {
                            \App\Models\Obligation::GENERATION_SOURCE_AUTOMATIC => 'Automática',
                            \App\Models\Obligation::GENERATION_SOURCE_ON_DEMAND => 'Sob demanda',
                            \App\Models\Obligation::GENERATION_SOURCE_LEGACY => 'Legado',
                            default => 'Manual',
                        } }}
                    </td>
                    <td class="px-3 py-2.5 text-right">
                        <x-filament::link
                            :href="\App\Filament\Resources\Emissions\EmissionResource::getUrl('edit', ['record' => $occurrence->emission_id, 'relation' => \App\Filament\Resources\Emissions\EmissionResource\RelationManagers\ObligationsRelationManager::class])"
                            size="xs"
                            color="warning"
                        >
                            Abrir ocorrência
                        </x-filament::link>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-3 py-8 text-center text-slate-400">
                        Nenhuma competência foi materializada para esta recorrência.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

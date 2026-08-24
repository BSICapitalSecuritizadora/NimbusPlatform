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
                        @if($occurrence->anchorEvent)
                            {{ $occurrence->anchorEvent->event_name }}
                            <span class="mt-0.5 block text-[11px] font-normal text-slate-400">
                                Âncora em {{ $occurrence->anchorEvent->occurred_on->format('d/m/Y') }}
                            </span>
                        @else
                            {{ $occurrence->competence_label ?? '—' }}
                        @endif
                    </td>
                    <td class="px-3 py-2.5 text-slate-300">
                        @if($occurrence->due_date_calculation_status === \App\Enums\ObligationDueDateCalculationStatus::AwaitingCalendar)
                            <span class="inline-flex rounded-md border border-amber-500/30 bg-amber-950/60 px-2 py-0.5 text-[11px] font-semibold text-amber-300">
                                Aguardando cobertura do calendário
                            </span>
                            <p class="mt-1 max-w-md text-[11px] leading-relaxed text-slate-400">
                                {{ $occurrence->due_date_resolution['blocking_reason'] ?? 'O vencimento será calculado quando o período necessário estiver confirmado.' }}
                            </p>
                        @else
                            <span class="font-mono">{{ $occurrence->due_date?->format('d/m/Y') ?? 'Sem prazo informado' }}</span>
                        @endif
                        @if($occurrence->due_date_resolution)
                            <details class="mt-1 font-sans text-[11px] text-slate-400">
                                <summary class="cursor-pointer text-amber-300 underline decoration-amber-500/40 underline-offset-2">
                                    {{ $occurrence->due_date ? 'Como foi calculado' : 'Detalhes da pendência' }}
                                </summary>
                                <div class="mt-1.5 max-w-md space-y-1 rounded-lg bg-[#0c232e] p-2 text-slate-300">
                                    <p>{{ $occurrence->due_date_resolution['rule'] ?? 'Regra registrada na ocorrência.' }}</p>
                                    @if(filled($occurrence->due_date_resolution['calendar_code'] ?? null))
                                        <p>Calendário: {{ $occurrence->due_date_resolution['calendar_code'] }}</p>
                                    @endif
                                    @if(filled($occurrence->due_date_resolution['anchor_date'] ?? null))
                                        <p>Data da âncora: {{ \Carbon\CarbonImmutable::parse($occurrence->due_date_resolution['anchor_date'])->format('d/m/Y') }}</p>
                                    @endif
                                    @if(filled($occurrence->due_date_resolution['calculated_at'] ?? null))
                                        <p>Cálculo registrado em: {{ \Carbon\CarbonImmutable::parse($occurrence->due_date_resolution['calculated_at'])->format('d/m/Y H:i') }}</p>
                                    @endif
                                    @foreach(($occurrence->due_date_resolution['calendar_years'] ?? []) as $calendarYear)
                                        <p>
                                            Calendário {{ $calendarYear['year'] }}:
                                            revisão {{ $calendarYear['revision'] ?? '—' }},
                                            cobertura {{ $calendarYear['coverage_status'] ?? '—' }},
                                            governança {{ $calendarYear['governance_status'] ?? '—' }}
                                            @if(filled($calendarYear['source_revision'] ?? null))
                                                · fonte {{ $calendarYear['source_revision'] }}
                                            @endif
                                        </p>
                                    @endforeach
                                    @if(($occurrence->due_date_resolution['skipped_dates'] ?? []) !== [])
                                        <p>Dias desconsiderados:</p>
                                        <ul class="list-disc space-y-0.5 pl-4">
                                            @foreach($occurrence->due_date_resolution['skipped_dates'] as $skippedDate)
                                                <li>{{ \Carbon\CarbonImmutable::parse($skippedDate['date'])->format('d/m/Y') }} — {{ $skippedDate['reason'] }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    <p>Versão da regra: v{{ $occurrence->seriesRule?->version ?? '—' }}</p>
                                </div>
                            </details>
                        @endif
                        @php($calendarAssessment = $calendarAssessments->get($occurrence->id))
                        @if(($calendarAssessment['status'] ?? null) === 'review_required')
                            <p class="mt-1.5 max-w-md rounded-md border border-amber-500/30 bg-amber-950/40 px-2 py-1.5 text-[11px] leading-relaxed text-amber-200">
                                {{ $calendarAssessment['message'] }}
                            </p>
                        @endif
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
                            \App\Models\Obligation::GENERATION_SOURCE_ANCHOR_EVENT => 'Evento registrado',
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

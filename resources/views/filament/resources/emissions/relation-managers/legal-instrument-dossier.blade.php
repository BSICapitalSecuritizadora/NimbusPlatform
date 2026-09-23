@php
    /** @var \App\Models\LegalInstrument $instrument */
@endphp

<div class="bsi-dossier-modal space-y-4 text-sm">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-gray-400">
        <p class="leading-relaxed">
            Documento original, aditamentos e demais instrumentos relacionados, na ordem em que compõem a cadeia documental.
        </p>
        @if ($instrument->documents->isNotEmpty())
            <span class="inline-flex items-center self-start sm:self-auto rounded-full bg-white/[0.04] border border-white/10 px-2.5 py-0.5 text-[11px] font-medium text-gray-300 whitespace-nowrap">
                {{ $instrument->documents->count() }} {{ $instrument->documents->count() === 1 ? 'documento' : 'documentos' }}
            </span>
        @endif
    </div>

    @if ($instrument->documents->isEmpty())
        <div class="rounded-xl border border-dashed border-white/10 bg-white/[0.02] p-8 text-center text-gray-400">
            <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-white/[0.05] text-gray-400 mb-3">
                <x-heroicon-o-document-text class="h-5 w-5" />
            </div>
            <div class="text-sm font-medium text-gray-300">Nenhum documento no dossiê</div>
            <div class="mt-1 text-xs text-gray-500">
                Use <span class="font-medium text-amber-200/90">Anexar documento</span> para começar pelo documento original.
            </div>
        </div>
    @else
        <div class="bsi-dossier-table-wrapper overflow-x-auto max-h-[60vh] overflow-y-auto rounded-lg border border-white/10 bg-[#091b23]/40 shadow-xs">
            <table class="bsi-dossier-table min-w-full divide-y divide-white/[0.08] text-left">
                <thead class="sticky top-0 z-10 bg-[#0e2733] border-b border-white/10 shadow-xs">
                    <tr class="text-[11px] font-semibold uppercase tracking-wider text-gray-400">
                        <th scope="col" class="bsi-dossier-col-document py-2.5 pl-4 pr-3 w-[28%] min-w-[200px]">Documento</th>
                        <th scope="col" class="bsi-dossier-col-papel py-2.5 px-3 w-[16%] min-w-[145px] max-w-[170px] whitespace-nowrap">Papel</th>
                        <th scope="col" class="bsi-dossier-col-data py-2.5 px-3 w-[11%] min-w-[95px] whitespace-nowrap">Data</th>
                        <th scope="col" class="bsi-dossier-col-efeito py-2.5 px-3 w-[20%] min-w-[160px] max-w-[220px]">Efeito</th>
                        <th scope="col" class="bsi-dossier-col-processamento py-2.5 pl-3 pr-4 w-[25%] min-w-[210px]">Processamento</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.08]">
                    @foreach ($instrument->documents as $entry)
                        <tr class="align-top hover:bg-white/[0.02] transition-colors duration-150">
                            {{-- Coluna 1: Documento (hierarquia refinada com título em destaque e metadados secundários) --}}
                            <td class="py-3.5 pl-4 pr-3 align-top">
                                <div class="font-semibold text-white tracking-tight text-[13px] leading-snug">
                                    {{ $entry->title }}
                                </div>
                                @if ($entry->addedBy)
                                    <div class="mt-1 text-xs leading-relaxed text-gray-400">
                                        Anexado por <span class="font-medium text-gray-300">{{ $entry->addedBy->name }}</span>
                                        @if ($entry->created_at)
                                            em <span class="text-gray-300">{{ $entry->created_at->format('d/m/Y H:i') }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>

                            {{-- Coluna 2: Papel (PRIORIDADE: badges não quebram em duas linhas, min-width garantido) --}}
                            <td class="bsi-dossier-col-papel py-3.5 px-3 align-top whitespace-nowrap">
                                @php
                                    $isBase = $entry->role?->isBase() ?? false;
                                    $isAmendment = $entry->role === \App\Enums\LegalInstrumentDocumentRole::Amendment;
                                    $badgeRoleClass = match (true) {
                                        $isBase => 'bsi-dossier-badge-base',
                                        $isAmendment => 'bsi-dossier-badge-amendment',
                                        default => 'bsi-dossier-badge-other',
                                    };
                                @endphp
                                <span class="bsi-dossier-badge {{ $badgeRoleClass }}">
                                    {{ $entry->role_label }}
                                </span>
                            </td>

                            {{-- Coluna 3: Data --}}
                            <td class="bsi-dossier-col-data py-3.5 px-3 align-top text-xs text-gray-300 font-mono whitespace-nowrap">
                                {{ $entry->document_date?->format('d/m/Y') ?? '—' }}
                            </td>

                            {{-- Coluna 4: Efeito (largura ligeiramente reduzida, quebra de linha suave) --}}
                            <td class="bsi-dossier-col-efeito py-3.5 px-3 align-top text-xs leading-relaxed text-gray-400 break-words">
                                {{ $entry->effect_summary ?? '—' }}
                            </td>

                            {{-- Coluna 5: Processamento (3 níveis hierárquicos: Status, Resumo, Tentativas/Erros) --}}
                            <td class="bsi-dossier-col-processamento py-3.5 pl-3 pr-4 align-top">
                                @php
                                    $statusColor = $entry->processing_status?->color();
                                    $statusClasses = match ($statusColor) {
                                        'success' => 'bsi-status-success',
                                        'warning' => 'bsi-status-warning',
                                        'danger' => 'bsi-status-danger',
                                        'info' => 'bsi-status-info',
                                        default => 'bsi-status-default',
                                    };
                                    $dotColor = match ($statusColor) {
                                        'success' => 'bg-emerald-400',
                                        'warning' => 'bg-amber-400',
                                        'danger' => 'bg-rose-400',
                                        'info' => 'bg-sky-400 animate-pulse',
                                        default => 'bg-gray-400',
                                    };
                                @endphp
                                <div>
                                    <span class="bsi-status-badge {{ $statusClasses }}">
                                        <span class="w-1.5 h-1.5 rounded-full mr-1.5 {{ $dotColor }}"></span>
                                        {{ $entry->processing_status?->label() }}
                                    </span>
                                </div>

                                {{-- Resumo de processamento mais escaneável mantendo os textos e contagens exatos --}}
                                @if (filled($entry->message))
                                    @php
                                        $hasParts = str_contains($entry->message, ' · ');
                                        $messageParts = $hasParts
                                            ? explode(' · ', rtrim($entry->message, '.'))
                                            : [$entry->message];
                                    @endphp
                                    <div class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-gray-300 leading-snug">
                                        @foreach ($messageParts as $index => $part)
                                            @if ($index > 0)
                                                <span class="text-gray-500 select-none font-bold">·</span>
                                            @endif
                                            <span>{{ trim($part) }}</span>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Mensagem de erro em caso de falha --}}
                                @if ($entry->processing_status?->canRetry() && filled($entry->error_message))
                                    <div class="mt-1.5 rounded bg-rose-950/40 border border-rose-500/25 p-2 font-mono text-[11px] leading-snug break-words text-rose-200">
                                        {{ $entry->error_message }}
                                    </div>
                                @endif

                                {{-- Tentativas: informação terciária discreta abaixo do resumo --}}
                                @if ($entry->extraction_attempts > 0)
                                    <div class="mt-1 text-[11px] text-gray-500 font-normal">
                                        {{ $entry->extraction_attempts }} tentativa(s)
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@php
    /** @var \App\Models\LegalInstrument $instrument */
@endphp

<div class="space-y-4 text-sm">
    <p class="text-gray-400">
        Documento original, aditamentos e demais instrumentos relacionados, na ordem em que compõem a cadeia documental.
    </p>

    @if ($instrument->documents->isEmpty())
        <div class="rounded-xl border border-dashed border-white/10 bg-white/[0.02] p-4 text-gray-400">
            Nenhum documento no dossiê. Use <span class="font-medium text-white">Anexar documento</span> para começar pelo documento original.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-white/10">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                        <th class="py-2 pr-4">Documento</th>
                        <th class="py-2 pr-4">Papel</th>
                        <th class="py-2 pr-4">Data</th>
                        <th class="py-2 pr-4">Efeito</th>
                        <th class="py-2">Processamento</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @foreach ($instrument->documents as $entry)
                        <tr class="align-top">
                            <td class="py-3 pr-4">
                                <div class="font-medium">{{ $entry->title }}</div>
                                @if ($entry->addedBy)
                                    <div class="text-xs text-gray-500">
                                        Anexado por {{ $entry->addedBy->name }}
                                        @if ($entry->created_at)
                                            em {{ $entry->created_at->format('d/m/Y H:i') }}
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="py-3 pr-4">
                                <span class="rounded-full bg-white/[0.05] px-2 py-0.5 text-xs">
                                    {{ $entry->role_label }}
                                </span>
                            </td>
                            <td class="py-3 pr-4">{{ $entry->document_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="py-3 pr-4 text-xs text-gray-400">
                                {{ $entry->effect_summary ?? '—' }}
                            </td>
                            <td class="py-3">
                                @php
                                    $statusClasses = match ($entry->processing_status?->color()) {
                                        'success' => 'bg-emerald-500/10 text-emerald-200',
                                        'warning' => 'bg-amber-500/10 text-amber-200',
                                        'danger' => 'bg-rose-500/10 text-rose-200',
                                        default => 'bg-white/[0.05] text-gray-300',
                                    };
                                @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $statusClasses }}">
                                    {{ $entry->processing_status?->label() }}
                                </span>
                                @if (filled($entry->message))
                                    <div class="mt-1 text-xs text-gray-500">{{ $entry->message }}</div>
                                @endif
                                @if ($entry->processing_status?->canRetry() && filled($entry->error_message))
                                    <div class="mt-1 font-mono text-xs break-words text-rose-300/80">
                                        {{ $entry->error_message }}
                                    </div>
                                @endif
                                @if ($entry->extraction_attempts > 0)
                                    <div class="mt-1 text-xs text-gray-500">
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

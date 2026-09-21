@php
    use App\Services\MeasurementStageActivityService;

    $record = $getRecord();
    $events = app(MeasurementStageActivityService::class)->for($record);

    $statusBadges = [
        'approved' => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
        'returned' => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
        'rejected' => 'text-red-700 bg-red-50 border-red-200 dark:text-red-400 dark:bg-red-500/10 dark:border-red-500/20',
        'pending' => 'text-sky-700 bg-sky-50 border-sky-200 dark:text-sky-400 dark:bg-sky-500/10 dark:border-sky-500/20',
        'paused' => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
    ];

    $stagePills = [
        1 => 'text-sky-700 bg-sky-50 border-sky-200 dark:text-sky-400 dark:bg-sky-500/10 dark:border-sky-500/20',
        2 => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-400 dark:bg-amber-500/10 dark:border-amber-500/20',
        3 => 'text-indigo-700 bg-indigo-50 border-indigo-200 dark:text-indigo-400 dark:bg-indigo-500/10 dark:border-indigo-500/20',
        4 => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-500/10 dark:border-emerald-500/20',
        5 => 'text-gray-700 bg-gray-50 border-gray-200 dark:text-gray-400 dark:bg-white/5 dark:border-white/10',
    ];

    $fileteBorder = [
        'approved' => 'border-emerald-500/70 dark:border-emerald-500/70',
        'returned' => 'border-[#A06E28] dark:border-bsi-gold-500',
        'rejected' => 'border-red-500/70 dark:border-red-500/70',
        'pending' => 'border-sky-500/70 dark:border-sky-500/70',
    ];

    $labelColors = [
        'approved' => 'text-emerald-800 dark:text-emerald-300',
        'returned' => 'text-[#A06E28] dark:text-bsi-gold-400',
        'rejected' => 'text-red-800 dark:text-red-300',
        'pending' => 'text-sky-800 dark:text-sky-300',
    ];
@endphp

<div class="bsi-reviews-table">
    @if ($events->isEmpty())
        <div class="py-4 text-center text-xs text-gray-400 dark:text-gray-500">
            Nenhuma atividade por etapa registrada ainda.
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200/80 bg-white dark:border-white/10 dark:bg-[#071820]/40">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-gray-200/80 bg-gray-50/75 text-[10px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-400">
                        <th class="px-3.5 py-2.5">Etapa</th>
                        <th class="px-3.5 py-2.5">Responsável</th>
                        <th class="px-3.5 py-2.5 text-center">Status / Decisão</th>
                        <th class="px-3.5 py-2.5 text-right">Última Movimentação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($events as $event)
                        <tr class="transition-colors hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
                            <td class="px-3.5 py-2.5 font-medium">
                                <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[11px] font-medium {{ $stagePills[$event['stage']] ?? $stagePills[5] }}">
                                    {{ $event['stage_label'] }}
                                </span>
                            </td>
                            <td class="px-3.5 py-2.5 font-medium text-gray-900 dark:text-white">
                                {{ $event['reviewer_name'] }}
                            </td>
                            <td class="px-3.5 py-2.5 text-center">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $statusBadges[$event['status_key']] ?? $statusBadges['pending'] }}">
                                    {{ $event['status_label'] }}
                                </span>
                            </td>
                            <td class="px-3.5 py-2.5 text-right font-mono text-[11px] text-gray-500 dark:text-gray-400">
                                {{ $event['reviewed_at'] ? $event['reviewed_at']->format('d/m/Y H:i') : '—' }}
                            </td>
                        </tr>

                        {{-- Observações / Justificativas em segunda camada de largura total --}}
                        @if (filled($event['notes']))
                            <tr class="bg-gray-50/40 dark:bg-white/[0.01]">
                                <td colspan="4" class="px-3.5 pb-2.5 pt-0">
                                    <div class="w-full rounded-md border-l-2 {{ $fileteBorder[$event['note_type']] ?? $fileteBorder['pending'] }} bg-gray-50/90 px-3 py-2 text-[11px] text-gray-600 dark:bg-[#071820]/90 dark:text-gray-300">
                                        <span class="font-semibold {{ $labelColors[$event['note_type']] ?? $labelColors['pending'] }}">
                                            {{ $event['note_label'] }}:
                                        </span>
                                        <span class="break-words leading-relaxed">
                                            {{ $event['notes'] }}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

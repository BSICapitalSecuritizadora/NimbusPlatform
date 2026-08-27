@php
    /** @var \App\Models\Obligation $obligation */
    /** @var bool $canViewEvidence */
    /** @var bool $canViewComments */
    use App\Enums\ObligationDueDateCalculationStatus;
    use App\Models\Obligation;

    $statusColors = [
        'em_dia' => 'success',
        'concluida' => 'success',
        'a_vencer' => 'info',
        'vencida' => 'danger',
        'em_analise' => 'warning',
        'nao_aplicavel' => 'gray',
    ];
    $priorityColors = [
        'critical' => 'danger',
        'high' => 'warning',
        'medium' => 'info',
        'low' => 'gray',
    ];
    $statusLabel = Obligation::STATUS_OPTIONS[$obligation->status] ?? $obligation->status;
    $priorityLabel = Obligation::PRIORITY_OPTIONS[$obligation->priority] ?? $obligation->priority;
    $statusColor = $statusColors[$obligation->status] ?? 'gray';
    $priorityColor = $priorityColors[$obligation->priority] ?? 'gray';

    $sourceLabel = match (true) {
        $obligation->obligation_series_id !== null => 'Série recorrente',
        $obligation->extracted_obligation_id !== null => 'Gerada pelo Termo',
        default => 'Manual',
    };

    $dueDateDisplay = $obligation->due_date_calculation_status === ObligationDueDateCalculationStatus::AwaitingCalendar
        ? 'Aguardando cobertura do calendário'
        : ($obligation->due_date?->format('d/m/Y') ?? 'Sem prazo definido');

    $dashboardData = app(\App\Services\Obligations\ObligationDashboardData::class);
    $documentStatus = $canViewEvidence ? $dashboardData->documentStatusFor($obligation) : null;
    $documentColor = $canViewEvidence ? $dashboardData->documentStatusColorFor($obligation) : 'gray';

    $evidences = $canViewEvidence ? $obligation->evidences()->with(['uploader'])->latest('uploaded_at')->latest('id')->limit(5)->get() : collect();
    $comments = $canViewComments ? $obligation->comments()->with(['author'])->limit(5)->get() : collect();
@endphp

<div class="space-y-6">
    {{-- Header summary --}}
    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $obligation->operational_title }}</h3>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Emissão: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $obligation->emission?->name ?? '—' }}</span>
            @if($obligation->competence_label)
                <span class="mx-1">·</span> Competência: <span class="font-medium">{{ $obligation->competence_label }}</span>
            @endif
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
            <x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge>
            <x-filament::badge :color="$priorityColor">Prioridade: {{ $priorityLabel }}</x-filament::badge>
            @if($canViewEvidence)
                <x-filament::badge :color="$documentColor">{{ $documentStatus }}</x-filament::badge>
            @endif
            <x-filament::badge color="gray">{{ $sourceLabel }}</x-filament::badge>
            @if($obligation->obligation_category)
                <x-filament::badge color="gray">{{ $obligation->obligation_category }}</x-filament::badge>
            @elseif($obligation->obligation_type)
                <x-filament::badge color="gray">{{ $obligation->obligation_type }}</x-filament::badge>
            @endif
        </div>
    </div>

    {{-- Main grid --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Responsável</p>
            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $obligation->responsibleUser?->name ?? 'Sem responsável' }}</p>
            @if($obligation->responsible_area)
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Área: {{ $obligation->responsible_area }}</p>
            @endif
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Vencimento</p>
            <p class="mt-1 text-sm font-medium {{ $obligation->due_date_calculation_status === ObligationDueDateCalculationStatus::AwaitingCalendar ? 'text-warning-600 dark:text-warning-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $dueDateDisplay }}</p>
            @if($obligation->due_date)
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Aging: {{ $dashboardData->agingLabelFor($obligation) ?? '—' }} · Foco: {{ $dashboardData->operationalFocusLabelFor($obligation) }}</p>
            @endif
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Recorrência</p>
            <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $obligation->recurrence ?? 'Única' }}</p>
            @if($obligation->series)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $obligation->series->rule_summary ?? '' }}</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Série: {{ $obligation->series->title }} · {{ $obligation->series->frequency?->label() ?? '' }}</p>
            @endif
        </div>
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Origem & Categoria</p>
            <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $obligation->obligation_category ?? $obligation->obligation_type ?? $sourceLabel }}</p>
            @if($obligation->source_clause)
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Cláusula: {{ $obligation->source_clause }}@if($obligation->source_page) · p. {{ $obligation->source_page }}@endif</p>
            @endif
        </div>
    </div>

    @if(filled($obligation->description))
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Descrição</p>
            <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200 whitespace-pre-line">{{ $obligation->description }}</p>
        </div>
    @endif

    @if(filled($obligation->required_evidence) || filled($obligation->notes))
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @if(filled($obligation->required_evidence))
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Evidência exigida</p>
                    <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200 whitespace-pre-line">{{ $obligation->required_evidence }}</p>
                </div>
            @endif
            @if(filled($obligation->notes))
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Observações</p>
                    <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200 whitespace-pre-line">{{ $obligation->notes }}</p>
                </div>
            @endif
        </div>
    @endif

    @if($canViewEvidence)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Evidências</p>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ (int) ($obligation->evidences_count ?? $evidences->count()) }} anexos · {{ (int) ($obligation->approved_evidences_count ?? 0) }} aprovados</span>
            </div>
            @if($evidences->isEmpty())
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Nenhuma evidência anexada. Apenas evidência aprovada vale como comprovação válida.</p>
            @else
                <ul class="mt-3 divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($evidences as $evidence)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $evidence->original_name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $evidence->status_label }} · {{ $evidence->human_size }} · {{ $evidence->uploaded_at?->format('d/m/Y') ?? '' }} @if($evidence->uploader) · {{ $evidence->uploader->name }}@endif</p>
                            </div>
                            @if(auth()->user()?->can(\App\Enums\AccessPermission::ObligationsDownloadEvidence->value))
                                <a href="{{ route('admin.obligations.evidences.download', $evidence) }}" class="shrink-0 text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400" target="_blank">Baixar</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if(($obligation->evidences_count ?? $evidences->count()) > 5)
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Exibindo 5 mais recentes. Veja todos na página de detalhes.</p>
                @endif
            @endif
        </div>
    @endif

    @if($canViewComments)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Comentários internos recentes</p>
            @if($comments->isEmpty())
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Nenhum comentário interno registrado.</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach($comments as $comment)
                        <li class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                            <p class="text-sm leading-6 text-gray-700 dark:text-gray-200 line-clamp-3">{{ \Illuminate\Support\Str::limit($comment->body, 220) }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $comment->author?->name ?? '—' }} · {{ $comment->created_at?->format('d/m/Y H:i') }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if(filled($obligation->source_excerpt))
        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 dark:border-gray-600 dark:bg-gray-800/30">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Trecho de origem</p>
            <p class="mt-2 text-sm italic leading-6 text-gray-600 dark:text-gray-300">"{{ \Illuminate\Support\Str::limit($obligation->source_excerpt, 400) }}"</p>
        </div>
    @endif
</div>

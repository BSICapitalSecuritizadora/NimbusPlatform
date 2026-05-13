@extends('nimbus.layouts.portal')
@section('title', 'Meus Envios')

@section('content')
@php
    $summarySubmissions = $filteredSubmissions ?? ($scopedSubmissions ?? ($allSubmissions ?? collect()));
    $statusTabs = [
        ['label' => 'Todas', 'value' => null],
        ['label' => 'Pendentes', 'value' => 'pending'],
        ['label' => 'Em análise', 'value' => 'under_review'],
        ['label' => 'Aprovadas', 'value' => 'completed'],
        ['label' => 'Recusadas', 'value' => 'rejected'],
    ];
    $operationLabels = [
        'REGISTRATION' => 'Cadastro',
    ];
    $periodOptions = [
        ['label' => '30 dias', 'value' => '30'],
        ['label' => '90 dias', 'value' => '90'],
        ['label' => '180 dias', 'value' => '180'],
        ['label' => 'Todo período', 'value' => 'all'],
    ];
    $operationOptions = collect($allSubmissions ?? [])
        ->pluck('submission_type')
        ->filter()
        ->unique()
        ->sort()
        ->values();
    $periodLabelMap = collect($periodOptions)->pluck('label', 'value')->all();
    $selectedOperationLabel = $operationFilter
        ? ($operationLabels[$operationFilter] ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', mb_strtolower($operationFilter))))
        : 'Todas';
    $selectedPeriodLabel = $periodLabelMap[$periodFilter] ?? '90 dias';
    $currentPeriodSummary = $periodFilter === 'all' ? 'Todo período' : "Últimos {$periodFilter} dias";
    $buildSubmissionIndexUrl = static function (?string $status, ?string $operation, ?string $period): string {
        return route('nimbus.submissions.index', array_filter([
            'status' => $status,
            'operation' => $operation,
            'period' => $period,
        ], static fn (mixed $value): bool => $value !== null));
    };
@endphp
<div class="mb-8">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6 mb-8">
        <div>
            <div class="font-jetbrains text-[11px] uppercase tracking-[.18em] text-gold-500 mb-3">ACESSO EXTERNO · LISTAGEM</div>
            <h1 class="font-fraunces text-[34px] font-medium text-navy-900 leading-tight mb-2">Meus Envios</h1>
            <p class="font-inter text-[14.5px] text-ink-500">Acompanhe o status e histórico das suas solicitações junto à BSI Capital.</p>
        </div>
        <div class="font-jetbrains text-[11px] uppercase tracking-[.1em] text-ink-400">
            {{ $summarySubmissions->count() }} registros{{ $statusFilter || $operationFilter || $periodFilter !== '90' ? ' filtrados' : '' }} · Período · {{ $currentPeriodSummary }}
        </div>
    </div>

    <!-- Summary Strip -->
    <div class="bg-white border border-ink-200 rounded-[8px] shadow-portal-subtle mb-8">
        <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-ink-100">
            <div class="p-6">
                <div class="font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400 mb-2">Total</div>
                <div class="font-fraunces text-[28px] font-medium text-navy-900 leading-none">{{ sprintf('%02d', $summarySubmissions->count()) }}</div>
            </div>
            <div class="p-6">
                <div class="font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400 mb-2">Pendentes</div>
                <div class="font-fraunces text-[28px] font-medium text-amber-600 leading-none">
                    {{ sprintf('%02d', $summarySubmissions->whereIn('status', [\App\Models\Nimbus\Submission::STATUS_PENDING, \App\Models\Nimbus\Submission::STATUS_NEEDS_CORRECTION])->count()) }}
                </div>
            </div>
            <div class="p-6">
                <div class="font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400 mb-2">Em análise</div>
                <div class="font-fraunces text-[28px] font-medium text-navy-700 leading-none">
                    {{ sprintf('%02d', $summarySubmissions->where('status', \App\Models\Nimbus\Submission::STATUS_UNDER_REVIEW)->count()) }}
                </div>
            </div>
            <div class="p-6">
                <div class="font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-400 mb-2">Aprovadas</div>
                <div class="font-fraunces text-[28px] font-medium text-emerald-600 leading-none">
                    {{ sprintf('%02d', $summarySubmissions->where('status', \App\Models\Nimbus\Submission::STATUS_COMPLETED)->count()) }}
                </div>
            </div>
        </div>
    </div>

    <!-- Filterbar -->
    <div class="filterbar flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-1 bg-ink-50 p-1 rounded-[6px] border border-ink-100 overflow-x-auto no-scrollbar">
            @foreach ($statusTabs as $tab)
                @php
                    $isActive = $statusFilter === $tab['value'] || ($statusFilter === null && $tab['value'] === null);
                    $tabRoute = $buildSubmissionIndexUrl($tab['value'], $operationFilter, $periodFilter);
                @endphp
                <a
                    href="{{ $tabRoute }}"
                    class="px-4 py-1.5 rounded-[4px] font-inter text-[13px] font-medium no-underline transition-colors {{ $isActive ? 'bg-white text-navy-900 shadow-sm border border-ink-100' : 'text-ink-500 hover:text-navy-900' }}"
                >
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
        <div class="flex items-center gap-3">
            <div class="dropdown">
                <button
                    type="button"
                    class="chip flex items-center gap-2 px-3 py-1.5 bg-white border border-ink-200 rounded-[5px] text-ink-700 text-[12px] font-medium"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                >
                    <span class="text-ink-400">Operação:</span> {{ $selectedOperationLabel }}
                    <i class="bi bi-chevron-down text-[10px]"></i>
                </button>
                <ul class="dropdown-menu mt-2 min-w-[220px] rounded-[8px] border border-ink-100 p-2 shadow-lg">
                    <li>
                        <a href="{{ $buildSubmissionIndexUrl($statusFilter, null, $periodFilter) }}" class="dropdown-item rounded-[5px] px-3 py-2 text-[13px] {{ $operationFilter === null ? 'bg-ink-50 text-navy-900' : 'text-ink-700' }}">
                            Todas
                        </a>
                    </li>
                    @foreach ($operationOptions as $operationOption)
                        @php
                            $operationLabel = $operationLabels[$operationOption] ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', mb_strtolower($operationOption)));
                        @endphp
                        <li>
                            <a href="{{ $buildSubmissionIndexUrl($statusFilter, $operationOption, $periodFilter) }}" class="dropdown-item rounded-[5px] px-3 py-2 text-[13px] {{ $operationFilter === $operationOption ? 'bg-ink-50 text-navy-900' : 'text-ink-700' }}">
                                {{ $operationLabel }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="dropdown">
                <button
                    type="button"
                    class="chip flex items-center gap-2 px-3 py-1.5 bg-white border border-ink-200 rounded-[5px] text-ink-700 text-[12px] font-medium"
                    data-bs-toggle="dropdown"
                    aria-expanded="false"
                >
                    <span class="text-ink-400">Período:</span> {{ $selectedPeriodLabel }}
                    <i class="bi bi-chevron-down text-[10px]"></i>
                </button>
                <ul class="dropdown-menu mt-2 min-w-[220px] rounded-[8px] border border-ink-100 p-2 shadow-lg">
                    @foreach ($periodOptions as $periodOption)
                        <li>
                            <a href="{{ $buildSubmissionIndexUrl($statusFilter, $operationFilter, $periodOption['value']) }}" class="dropdown-item rounded-[5px] px-3 py-2 text-[13px] {{ $periodFilter === $periodOption['value'] ? 'bg-ink-50 text-navy-900' : 'text-ink-700' }}">
                                {{ $periodOption['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="bg-white border border-ink-200 rounded-[8px] shadow-portal-subtle overflow-hidden">
        <div class="overflow-x-auto">
            @if ($submissions->count() === 0)
                <div class="py-12 text-center">
                    <div class="mb-4">
                        <i class="bi bi-inbox text-4xl text-ink-200"></i>
                    </div>
                    <h6 class="font-fraunces text-lg text-navy-900 mb-2">Nenhum envio encontrado</h6>
                    <p class="font-inter text-sm text-ink-500 max-w-sm mx-auto">
                        @if (collect($allSubmissions ?? [])->isEmpty())
                            Você ainda não possui envios registrados no sistema.
                        @elseif ($statusFilter || $operationFilter || $periodFilter !== '90')
                            Não há solicitações para os filtros selecionados no momento.
                        @else
                            Não há solicitações registradas nos últimos 90 dias.
                        @endif
                    </p>
                </div>
            @else
                <table class="bsi w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-ink-50 border-b border-ink-200">
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Protocolo</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Operação</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Status</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Responsável</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500">Data</th>
                            <th class="px-7 py-3 font-inter text-[11px] font-semibold uppercase tracking-[.12em] text-ink-500 text-right">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($submissions as $s)
                            @php
                                $status = $s->status;
                                $statusLabel = \App\Models\Nimbus\Submission::statusOptions()[$status] ?? $status;
                                $submissionTypeLabel = $operationLabels[$s->submission_type] ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', mb_strtolower((string) $s->submission_type)));
                                
                                $badgeClass = match($status) {
                                    \App\Models\Nimbus\Submission::STATUS_PENDING => 'pending',
                                    \App\Models\Nimbus\Submission::STATUS_UNDER_REVIEW => 'review',
                                    \App\Models\Nimbus\Submission::STATUS_NEEDS_CORRECTION => 'pending',
                                    \App\Models\Nimbus\Submission::STATUS_COMPLETED => 'approved',
                                    \App\Models\Nimbus\Submission::STATUS_REJECTED => 'rejected',
                                    default => 'draft',
                                };
                            @endphp
                            <tr class="border-b border-ink-100 hover:bg-[#FAFBFD] transition-colors">
                                <td class="px-7 py-[18px]">
                                    <div class="font-jetbrains text-[13px] font-medium text-navy-900 uppercase">#BSI-{{ now()->year }}-{{ sprintf('%04d', $s->id) }}</div>
                                    <div class="font-inter text-[13px] font-medium text-navy-900 mt-0.5">{{ $s->company_name ?? 'Razão Social do Cliente' }}</div>
                                    <div class="font-jetbrains text-[11px] text-ink-400 mt-0.5">{{ $s->company_cnpj ?? '00.000.000/0001-00' }}</div>
                                </td>
                                <td class="px-7 py-[18px]">
                                    <div class="font-inter text-[13px] font-medium text-navy-900">{{ $submissionTypeLabel }}</div>
                                    <div class="font-inter text-[12px] text-ink-500">{{ $s->title ?? 'Solicitação enviada' }}</div>
                                </td>
                                <td class="px-7 py-[18px]">
                                    <div class="badge {{ $badgeClass }}">
                                        <div class="badge-pulse"></div>
                                        {{ $statusLabel }}
                                    </div>
                                </td>
                                <td class="px-7 py-[18px]">
                                    <div class="font-inter text-[13px] font-medium text-navy-900">{{ $s->responsible_name ?? 'Anderson Silva' }}</div>
                                </td>
                                <td class="px-7 py-[18px]">
                                    <div class="font-jetbrains text-[13px] text-navy-900">{{ $s->submitted_at?->format('d/m/Y') ?? '05/05/2026' }}</div>
                                    <div class="font-inter text-[11.5px] text-ink-400">{{ $s->submitted_at?->diffForHumans() ?? 'há 2 dias' }}</div>
                                </td>
                                <td class="px-7 py-[18px] text-right">
                                    <a href="{{ route('nimbus.submissions.show', $s->id) }}" class="row-cta text-[13px]">
                                        <span>Detalhes</span>
                                        <i class="bi bi-chevron-right text-[11px] ms-1"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        
        <!-- Footer -->
        <div class="px-7 py-4 bg-ink-50 border-t border-ink-200 flex items-center justify-between">
            <div class="font-jetbrains text-[11px] uppercase tracking-[.05em] text-ink-400">
                @if ($submissions->total() > 0)
                    Mostrando {{ $submissions->firstItem() }} a {{ $submissions->lastItem() }} de {{ $submissions->total() }} solicitações
                @else
                    Mostrando 0 de 0 solicitações
                @endif
            </div>
            @if ($submissions->hasPages())
                <div>
                    {{ $submissions->onEachSide(1)->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
    .badge {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 5px 11px 5px 9px;
        font: 600 11.5px/1 'Inter', sans-serif;
        letter-spacing: .06em; text-transform: uppercase;
        border-radius: 4px;
        border: 1px solid transparent;
    }
    .badge.pending { background: var(--color-gold-50); color: var(--color-gold-700); border-color: rgba(160,110,40,.18); }
    .badge.review { background: var(--color-navy-50); color: var(--color-navy-700); border-color: rgba(34,66,76,.16); }
    .badge.approved { background: #E6F1EC; color: #1E7A56; border-color: rgba(30,122,86,.18); }
    .badge.rejected { background: #F7E5E8; color: #9B2D3E; border-color: rgba(155,45,62,.18); }
    .badge.draft { background: var(--color-ink-50); color: var(--color-ink-500); border-color: var(--color-ink-200); }
    
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
@endpush

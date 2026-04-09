@extends('site.layout')

@section('title', $emission->name . ' — Detalhes da Emissão — BSI Capital')

@section('content')
<style>
    :root {
        --opea-purple: var(--brand); /* Navy Blue instead of Purple */
        --opea-bg: var(--surface-alt);
    }
    .emission-page {
        background-color: var(--opea-bg);
        color: var(--opea-purple);
        font-family: 'Inter', sans-serif;
    }
    .text-purple { color: var(--opea-purple); }
    .bg-purple-light { background-color: rgba(0, 32, 91, 0.1); }
    
    .breadcrumb-item + .breadcrumb-item::before { content: ""; }
    
    .nav-pills .nav-link {
        color: var(--opea-purple);
        border-radius: 50rem;
        padding: 0.5rem 1.25rem;
        font-weight: 500;
        font-size: 0.9rem;
    }
    .nav-pills .nav-link.active {
        background-color: rgba(0, 32, 91, 0.1);
        color: var(--opea-purple);
    }
    
    .char-label {
        font-size: 0.65rem;
        color: #8e8e8e;
        text-transform: uppercase;
        margin-bottom: 0.25rem;
        display: block;
    }
    .char-value {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--opea-purple);
    }
    
    .card-opea {
        border: 1px solid rgba(0,0,0,0.05);
        border-radius: 8px;
        background: #fff;
    }
</style>

<div class="emission-page pb-5" style="min-height: 100vh;">
    <!-- Top Logo -->
    <div class="py-5 text-center">
        @if($emission->logo_path)
            <img src="{{ Storage::url($emission->logo_path) }}" alt="{{ $emission->name }}" style="max-height: 80px; max-width: 250px; object-fit: contain;">
        @else
            <h2 class="h1 fw-normal text-purple mb-0">Emissão</h2>
        @endif
    </div>

    <div class="container pb-4">
        <!-- Hero Section -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-end mb-4 gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="fs-3 text-purple opacity-75">
                   <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <h1 class="h3 fw-bold mb-0 text-purple">{{ $emission->name }}</h1>
            </div>
            
            <div class="d-flex flex-wrap align-items-center gap-3 small">
                <span class="text-muted opacity-75">ISIN {{ $emission->isin_code ?? 'N/A' }}</span>
                <span class="text-muted opacity-75">IF {{ $emission->if_code }}</span>
                <span class="badge rounded-pill px-3 py-1" style="background-color: #e6f6ec; color: #1e6e44;">
                    {{ $emission->status_label }}
                </span>
            </div>
        </div>

        <!-- Tab Navigation -->
        <ul class="nav nav-pills mb-5 gap-2" id="emissionTabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" href="#caracteristicas">Características</a></li>
            <li class="nav-item"><a class="nav-link" href="#pagamentos">Pagamentos</a></li>
            <li class="nav-item"><a class="nav-link" href="#documentos">Documentos</a></li>
        </ul>

        <!-- Content Sections -->
        <div class="d-grid gap-4">
            
            <!-- Characteristics Card -->
            <div class="card card-opea p-4 shadow-sm" id="caracteristicas">
                <div class="mb-4">
                    <h3 class="h5 fw-bold text-purple mb-0">Características</h3>
                </div>

                <style>
                    @media (min-width: 992px) {
                        .grid-characteristics {
                            display: grid !important;
                            grid-template-columns: repeat(5, 1fr) !important;
                            gap: 1.5rem;
                        }
                        .grid-characteristics .col {
                            width: 100% !important;
                            flex: 0 0 100% !important;
                            max-width: 100% !important;
                        }
                    }
                </style>
                <div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 grid-characteristics g-4 mb-4">
                    <div class="col">
                        <span class="char-label">Série</span>
                        <div class="char-value">{{ $emission->series ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Número da emissão</span>
                        <div class="char-value">{{ $emission->emission_number ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Emissor</span>
                        <div class="char-value">{{ $emission->issuer ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Coordenador Líder</span>
                        <div class="char-value">{{ $emission->lead_coordinator ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Agente Fiduciário</span>
                        <div class="char-value">{{ $emission->trustee_agent ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Data de Emissão</span>
                        <div class="char-value">{{ optional($emission->issue_date)->format('d/m/Y') ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Data de Vencimento</span>
                        <div class="char-value">{{ optional($emission->maturity_date)->format('d/m/Y') ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Remuneração</span>
                        <div class="char-value">{{ $emission->remuneration ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Tipo de Oferta</span>
                        <div class="char-value">{{ $emission->offer_type ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Segmento</span>
                        <div class="char-value">{{ $emission->segment ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Concentração</span>
                        <div class="char-value">{{ $emission->concentration ?? 'N/A' }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Preço de emissão</span>
                        <div class="char-value">R$ {{ number_format($emission->issued_price, 2, ',', '.') }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Quantidade Emitida</span>
                        <div class="char-value">{{ number_format($emission->issued_quantity, 0, ',', '.') }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Quantidade integralizada</span>
                        <div class="char-value">{{ number_format($emission->integralized_quantity, 0, ',', '.') }}</div>
                    </div>
                    <div class="col">
                        <span class="char-label">Volume emitido</span>
                        <div class="char-value">R$ {{ number_format($emission->issued_volume, 0, ',', '.') }}</div>
                    </div>
                </div>
            </div>

            <!-- Pagamentos Card -->
            <div class="card card-opea p-4 shadow-sm" id="pagamentos">
                <h3 class="h5 fw-bold text-purple mb-4">Fluxo de Pagamentos</h3>
                
                <div style="position: relative; height: 350px; width: 100%;" class="mb-4">
                    @if(isset($emission->payments) && $emission->payments->count() > 0)
                        <canvas id="paymentsChart"></canvas>
                    @else
                        <div class="d-flex align-items-center justify-content-center h-100 text-muted bg-light rounded" style="border: 1px dashed var(--border);">
                            Nenhum dado de pagamento registrado até o momento.
                        </div>
                    @endif
                </div>

                <!-- Resumo PU e Integralização -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="d-flex flex-column h-100 p-3 rounded" style="background-color: var(--opea-bg); border: 1px solid rgba(0,0,0,0.05);">
                            @php
                                $lastFiveDays = collect();
                                for($i = 0; $i < 5; $i++) {
                                    $date = \Carbon\Carbon::today()->subDays($i);
                                    $pu = $emission->puHistories()->where('date', '<=', $date->format('Y-m-d'))->orderByDesc('date')->first();
                                    $lastFiveDays->push([
                                        'date' => $date,
                                        'value' => $pu ? $pu->unit_value : null
                                    ]);
                                }
                                $todayPu = $lastFiveDays->first()['value'] ?? $emission->current_pu;
                            @endphp
                            
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="char-label mb-0">PU Atual</span>
                                <span class="fs-5 fw-bold" style="color: var(--gold);">
                                    {{ $todayPu ? 'R$ ' . number_format($todayPu, 6, ',', '.') : '—' }}
                                </span>
                            </div>
                            
                            @if($lastFiveDays->whereNotNull('value')->count() > 0)
                            <div class="mt-2 pt-2 border-top" style="border-color: rgba(0,0,0,0.05) !important;">
                                <span class="char-label mb-2">Histórico (Últimos 5 dias)</span>
                                <div class="d-flex flex-column gap-1">
                                    @foreach($lastFiveDays as $dayPu)
                                    <div class="d-flex justify-content-between small" style="font-size: 0.8rem;">
                                        <span class="text-muted">{{ $dayPu['date']->format('d/m/Y') }}</span>
                                        <span class="fw-medium text-purple">
                                            {{ $dayPu['value'] ? 'R$ ' . number_format($dayPu['value'], 6, ',', '.') : '—' }}
                                        </span>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex flex-column h-100 p-3 rounded" style="background-color: var(--opea-bg); border: 1px solid rgba(0,0,0,0.05);">
                            @php
                                $totalInt = isset($emission->integralizationHistories) && $emission->integralizationHistories()->count() > 0 
                                            ? $emission->integralizationHistories()->sum('quantity') 
                                            : null;
                            @endphp
                            
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="char-label mb-0">Integralização</span>
                                <span class="fs-5 fw-bold text-purple">
                                    {{ $totalInt ? number_format($totalInt, 0, ',', '.') : ($emission->integralization_status ?? '—') }}
                                </span>
                            </div>
                            
                            @if(isset($emission->integralizationHistories) && $emission->integralizationHistories()->count() > 0)
                            <div class="mt-2 pt-2 border-top" style="border-color: rgba(0,0,0,0.05) !important;">
                                <span class="char-label mb-2">Histórico</span>
                                <div class="d-flex flex-column gap-1">
                                    @foreach($emission->integralizationHistories()->orderByDesc('date')->take(5)->get() as $intHistory)
                                    <div class="d-flex justify-content-between small" style="font-size: 0.8rem;">
                                        <span class="text-muted">{{ $intHistory->date->format('d/m/Y') }}</span>
                                        <span class="fw-medium text-purple">{{ number_format($intHistory->quantity, 0, ',', '.') }}</span>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Documents Card -->
            <div id="documentos" class="mt-5">
                <div class="card card-opea p-4 shadow-sm">
                    <h3 class="h5 fw-bold text-purple mb-4">Documentos</h3>
                    
                    @if($emission->documents->count() > 0)
                    @php
                        $allCategories = [
                            'anuncios' => 'Anúncios',
                            'assembleias' => 'Assembleias',
                            'convocacoes_assembleias' => 'Convocações para Assembleias',
                            'demonstracoes_financeiras' => 'Demonstrações Financeiras',
                            'documentos_operacao' => 'Documentos da Operação',
                            'fatos_relevantes' => 'Fatos Relevantes',
                            'relatorios_anuais' => 'Relatórios Anuais',
                        ];
                        $docCategories = $emission->documents->pluck('category')
                            ->filter()
                            ->unique()
                            ->sortBy(function($cat) use ($allCategories) {
                                return strtolower($allCategories[$cat] ?? $cat);
                            });
                    @endphp
                    
                    @if($docCategories->isNotEmpty())
                    <div class="mb-4">
                        <select id="docCategoryFilter" class="form-select border-0 border-bottom rounded-0 text-muted shadow-none" style="padding-left: 0; padding-right: 32px; max-width: 320px; border-color: rgba(0,0,0,0.1) !important; background-position: right 0 center;">
                            <option value="">Todos</option>
                            @foreach($docCategories as $cat)
                                <option value="{{ $cat }}">{{ $allCategories[$cat] ?? ucfirst($cat) }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th class="border-0 text-purple small fw-bold pb-3 text-center" style="font-size: 0.8rem; width: 120px;">Data</th>
                                    <th class="border-0 text-purple small fw-bold pb-3 text-center" style="font-size: 0.8rem;">Título</th>
                                    <th class="border-0 text-purple small fw-bold pb-3 text-center" style="font-size: 0.8rem; width: 80px;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($emission->documents as $index => $doc)
                                <tr class="doc-row" data-category="{{ $doc->category }}" style="{{ $index >= 3 ? 'display: none;' : '' }}">
                                    <td class="text-muted small border-bottom py-3 text-center">{{ optional($doc->published_at)->format('d/m/Y') }}</td>
                                    <td class="border-bottom text-center py-3">
                                        <div class="text-muted small">{{ $doc->title }}</div>
                                        @if($doc->description)
                                        <div class="small opacity-50" style="font-size: 0.75rem;">{{ $doc->description }}</div>
                                        @endif
                                    </td>
                                    <td class="text-center border-bottom py-3">
                                        <a href="{{ Storage::url($doc->file_path) }}" target="_blank" class="text-purple">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        </a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    <div class="text-center py-5 opacity-50">
                        <p class="mb-0">Nenhum documento disponível para esta operação.</p>
                    </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabLinks = document.querySelectorAll('#emissionTabs .nav-link');
    tabLinks.forEach(link => {
        link.addEventListener('click', function() {
            tabLinks.forEach(l => l.classList.remove('active'));
            this.classList.add('active');
        });
    });

    const docFilter = document.getElementById('docCategoryFilter');
    if(docFilter) {
        docFilter.addEventListener('change', function() {
            const val = this.value;
            const rows = document.querySelectorAll('.doc-row');
            
            if (val === '') {
                // If "Todos" is selected, only show the first 3 recent documents
                rows.forEach((row, index) => {
                    row.style.display = index < 3 ? '' : 'none';
                });
            } else {
                // If a specific category is selected, show all matching documents
                rows.forEach(row => {
                    if(row.dataset.category === val) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
        });
    }
});
</script>

@if(isset($emission->payments) && $emission->payments->count() > 0)
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('paymentsChart');
    if(!ctx) return;

    const labels = {!! json_encode($emission->payments->pluck('payment_date')->map(fn($d) => $d->format('d/m/Y'))) !!};
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Prêmio',
                    data: {!! json_encode($emission->payments->pluck('premium_value')) !!},
                    backgroundColor: '#d4af37', // Gold
                    borderRadius: 4,
                    barPercentage: 0.5
                },
                {
                    label: 'Juros',
                    data: {!! json_encode($emission->payments->pluck('interest_value')) !!},
                    backgroundColor: '#5b667a', // Muted/Gray
                    borderRadius: 4,
                    barPercentage: 0.5
                },
                {
                    label: 'Amortização Extraordinária',
                    data: {!! json_encode($emission->payments->pluck('extra_amortization_value')) !!},
                    backgroundColor: '#4e2a4e', // Brand Dark/Purple
                    borderRadius: 4,
                    barPercentage: 0.5
                },
                {
                    label: 'Amortização',
                    data: {!! json_encode($emission->payments->pluck('amortization_value')) !!},
                    backgroundColor: '#00205b', // Brand Navy
                    borderRadius: 4,
                    barPercentage: 0.5
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false }
                },
                y: {
                    stacked: true,
                    border: { display: false },
                    grid: { color: 'rgba(0, 0, 0, 0.05)' },
                    ticks: {
                        color: '#5b667a',
                        callback: function(value) {
                            return 'R$ ' + value.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: { usePointStyle: true, boxWidth: 10, padding: 25, font: { family: 'Inter', size: 13 } }
                },
                tooltip: {
                    backgroundColor: 'rgba(11, 18, 32, 0.9)',
                    titleFont: { family: 'Inter', size: 14 },
                    bodyFont: { family: 'Inter', size: 13 },
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 2 }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            }
        }
    });
});
</script>
@endif
@endpush
@endsection

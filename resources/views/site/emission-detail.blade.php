@extends('site.layout')

@section('title', $emission->name . ' — Detalhes da Emissão — BSI Capital')

@section('content')
<style>
    :root {
        --opea-purple: var(--brand); /* Navy Blue instead of Purple */
        --opea-bg: var(--brand-light);
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="h5 fw-bold text-purple mb-0">Características</h3>
                    <button class="btn btn-link p-0 text-muted"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg></button>
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
                            <span class="char-label mb-1">PU Atual</span>
                            <span class="fs-4 fw-bold" style="color: var(--gold);">
                                {{ $emission->current_pu ? 'R$ ' . number_format($emission->current_pu, 6, ',', '.') : '—' }}
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex flex-column h-100 p-3 rounded" style="background-color: var(--opea-bg); border: 1px solid rgba(0,0,0,0.05);">
                            <span class="char-label mb-1">Integralização</span>
                            <span class="fs-4 fw-bold text-purple">
                                {{ $emission->integralization_status ?? '—' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Documents Card -->
            <div class="card card-opea p-4 shadow-sm" id="documentos">
                <h3 class="h5 fw-bold text-purple mb-4">Documentos da Operação</h3>
                
                @if($emission->documents->count() > 0)
                <div class="table-responsive">
                    <table class="table align-middle">
                        <tbody>
                            @foreach($emission->documents as $doc)
                            <tr>
                                <td width="120" class="text-muted small border-0">{{ optional($doc->published_at)->format('d/m/Y') }}</td>
                                <td class="border-0">
                                    <div class="fw-bold">{{ $doc->title }}</div>
                                    @if($doc->description)
                                    <div class="small text-muted">{{ $doc->description }}</div>
                                    @endif
                                </td>
                                <td width="60" class="text-end border-0">
                                    <a href="{{ Storage::url($doc->file_path) }}" target="_blank" class="text-muted opacity-50">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
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

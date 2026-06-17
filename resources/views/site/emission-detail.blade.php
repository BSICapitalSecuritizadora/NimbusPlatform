@extends('site.layout')

@section('title', $emission->name . ' - Detalhes da Emissão - BSI Capital')

@push('head')
<style>
    .emission-timeline-card {
        position: relative;
        overflow: hidden;
        background: linear-gradient(180deg, color-mix(in srgb, var(--surface) 96%, white 4%), color-mix(in srgb, var(--surface-alt) 94%, var(--brand) 6%));
    }

    .emission-timeline-card::before {
        content: "";
        position: absolute;
        inset: 0 0 auto 0;
        height: 1px;
        background: linear-gradient(90deg, rgba(160, 110, 40, 0), rgba(160, 110, 40, 0.34), rgba(9, 27, 35, 0.08), rgba(160, 110, 40, 0));
    }

    .emission-timeline-point {
        height: 100%;
        padding: 1rem 1.1rem;
        border: 1px solid color-mix(in srgb, var(--brand) 8%, var(--border));
        border-radius: 14px;
        background: color-mix(in srgb, var(--surface) 97%, white 3%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
    }

    .emission-timeline-label {
        margin-bottom: 0.45rem;
        color: var(--muted);
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .emission-timeline-value {
        color: var(--brand);
        font-size: clamp(1.18rem, 1.04rem + 0.34vw, 1.48rem);
        font-weight: 800;
        letter-spacing: -0.03em;
        line-height: 1.15;
    }

    .emission-timeline-status-badge,
    .emission-timeline-progress-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2.2rem;
        padding: 0.45rem 0.9rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-align: center;
    }

    .emission-timeline-progress-pill {
        border: 1px solid color-mix(in srgb, var(--brand) 12%, var(--border));
        background: color-mix(in srgb, var(--surface) 94%, white 6%);
        color: var(--brand);
    }

    .emission-timeline-track-shell {
        position: relative;
        padding-block: 0.45rem;
    }

    .emission-timeline-track {
        position: relative;
        overflow: visible;
        height: 0.5rem;
        border-radius: 999px;
        background: color-mix(in srgb, var(--border) 84%, white);
        box-shadow: inset 0 1px 1px rgba(9, 27, 35, 0.08);
    }

    .emission-timeline-track-fill {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, color-mix(in srgb, #7ca6d8 78%, white), color-mix(in srgb, var(--brand) 76%, #7ca6d8 24%));
        box-shadow: 0 0 0 1px rgba(124, 166, 216, 0.18), 0 3px 10px rgba(9, 27, 35, 0.14);
    }

    .emission-timeline-current-dot {
        position: absolute;
        top: 50%;
        width: 1rem;
        height: 1rem;
        border: 3px solid #fff;
        border-radius: 999px;
        background: var(--brand);
        box-shadow: 0 0 0 4px rgba(9, 27, 35, 0.12), 0 8px 18px rgba(9, 27, 35, 0.18);
        transform: translateY(-50%);
    }

    .emission-timeline-current-dot::after {
        content: "";
        position: absolute;
        inset: -0.38rem;
        border-radius: inherit;
        border: 1px solid rgba(9, 27, 35, 0.14);
    }

    .emission-timeline-meta {
        color: var(--muted);
        font-size: 0.82rem;
        font-weight: 600;
        line-height: 1.55;
    }

    .emission-detail-tabs-container {
        display: inline-flex;
        background: rgba(9,27,35,0.03);
        padding: 0.35rem;
        border-radius: 50rem;
        border: 1px solid rgba(9,27,35,0.06);
        flex-wrap: wrap;
        justify-content: center;
    }
    .emission-detail-tabs-container .nav-link {
        color: #5d687b;
        font-weight: 600;
        font-size: 0.95rem;
        padding: 0.6rem 1.75rem;
        border-radius: 50rem;
        transition: all 0.3s ease;
        border: none;
        background: transparent;
    }
    .emission-detail-tabs-container .nav-link:hover {
        color: var(--brand);
    }
    .emission-detail-tabs-container .nav-link.active {
        color: var(--brand);
        background: #ffffff;
        box-shadow: 0 2px 10px rgba(9,27,35,0.08);
    }

    .liquid-glass-logo {
        background: rgba(255, 255, 255, 0.04);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.1);
        transition: all 0.3s ease;
    }
    .liquid-glass-logo:hover {
        background: rgba(255, 255, 255, 0.06);
        border-color: rgba(255, 255, 255, 0.12);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.15);
    }

    .tech-data-card {
        background: #ffffff;
        border: 1px solid rgba(9,27,35,0.04);
        border-radius: 16px;
        box-shadow: 0 4px 14px rgba(9,27,35,0.02);
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .tech-data-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(9,27,35,0.06);
        border-color: rgba(9,27,35,0.08);
    }
    .tech-data-label {
        font-size: 0.7rem;
        letter-spacing: 0.05em;
        color: #8c98a4;
        font-weight: 700;
        margin-bottom: 0.4rem;
        text-transform: uppercase;
    }
    .tech-data-value {
        font-size: 1.05rem;
        color: var(--brand);
        font-weight: 600;
        line-height: 1.3;
    }
    
    .history-row {
        background: rgba(9,27,35,0.015);
        border: 1px solid rgba(9,27,35,0.03);
        border-radius: 12px;
        transition: all 0.2s ease;
    }
    .history-row:hover {
        background: #ffffff;
        border-color: rgba(9,27,35,0.08);
        box-shadow: 0 4px 12px rgba(9,27,35,0.03);
    }
    
    .badge-premium {
        background: rgba(212,175,55,0.12);
        color: var(--gold);
        border: 1px solid rgba(212,175,55,0.25);
        font-weight: 600;
        font-size: 0.75rem;
        border-radius: 8px;
    }
    
    .document-table-container {
        background: #ffffff;
        border: 1px solid rgba(9,27,35,0.04);
        border-radius: 16px;
        box-shadow: 0 4px 14px rgba(9,27,35,0.02);
        overflow: hidden;
    }
    .document-table-container thead th {
        background: rgba(9,27,35,0.02);
        border-bottom: 1px solid rgba(9,27,35,0.05);
        color: #8c98a4;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 1rem 1.5rem;
        border-top: none;
    }
    .document-table-container tbody td {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid rgba(9,27,35,0.03);
        vertical-align: middle;
    }
    .document-table-container tbody tr:last-child td {
        border-bottom: none;
    }
    .document-table-container tbody tr {
        transition: all 0.2s ease;
    }
    .document-table-container tbody tr:hover {
        background: rgba(9,27,35,0.015);
    }
    
    .doc-action-btn {
        background: rgba(9,27,35,0.03);
        color: var(--brand);
        border: 1px solid transparent;
        font-weight: 600;
        border-radius: 10px;
        padding: 0.4rem 1.2rem;
        transition: all 0.2s ease;
    }
    .doc-action-btn:hover {
        background: #ffffff;
        border-color: rgba(9,27,35,0.1);
        box-shadow: 0 4px 12px rgba(9,27,35,0.05);
        color: var(--brand);
        transform: translateY(-2px);
    }
    
    .doc-filter-select {
        background-color: #ffffff;
        border: 1px solid rgba(9,27,35,0.1);
        border-radius: 12px;
        padding: 0.7rem 1rem;
        font-weight: 500;
        color: var(--brand);
        box-shadow: 0 2px 8px rgba(9,27,35,0.02);
        transition: all 0.2s ease;
    }
    .doc-filter-select:focus {
        border-color: var(--gold);
        box-shadow: 0 0 0 4px rgba(212,175,55,0.15);
    }

    .calculator-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border: 1px solid rgba(9,27,35,0.08);
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(9,27,35,0.05);
    }
    .calc-input-label {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--brand);
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .calc-result-box {
        background: var(--brand);
        color: #ffffff;
        border-radius: 16px;
        padding: 2rem;
        text-align: center;
    }
    .calc-result-label {
        font-size: 0.9rem;
        color: rgba(255,255,255,0.7);
        margin-bottom: 0.25rem;
    }
    .calc-result-value {
        font-size: 1.8rem;
        font-weight: 800;
    }
</style>
@endpush

@section('content')
@php
    $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();

    $statusPalette = match ($emission->status) {
        'active' => ['bg' => 'rgba(34,197,94,0.12)', 'border' => 'rgba(34,197,94,0.22)', 'text' => '#15803d', 'label' => $emission->status_label],
        'closed' => ['bg' => 'rgba(239,68,68,0.12)', 'border' => 'rgba(239,68,68,0.22)', 'text' => '#b91c1c', 'label' => $emission->status_label],
        'default' => ['bg' => 'rgba(239,68,68,0.12)', 'border' => 'rgba(239,68,68,0.22)', 'text' => '#b91c1c', 'label' => $emission->status_label],
        default => ['bg' => 'rgba(245,158,11,0.12)', 'border' => 'rgba(245,158,11,0.22)', 'text' => '#b45309', 'label' => $emission->status_label],
    };

    $summaryCards = [
        ['label' => 'Instrumento', 'value' => $emission->type ?? '—'],
        ['label' => 'Remuneração', 'value' => $emission->formatted_remuneration ?? '—'],
        ['label' => 'Volume da Emissão', 'value' => $emission->issued_volume ? 'R$ ' . number_format((float) $emission->issued_volume, 2, ',', '.') : '—'],
        ['label' => 'Vencimento', 'value' => $emission->maturity_date?->format('d/m/Y') ?? '—'],
    ];

    $timelineStartDate = $emission->issue_date?->copy()->startOfDay();
    $timelineEndDate = $emission->maturity_date?->copy()->startOfDay();
    $timelineCurrentDate = today()->startOfDay();
    $timelineProgressPercentage = 0;
    $timelineTotalDays = null;
    $timelineElapsedDays = null;
    $timelineRemainingDays = null;

    if (($timelineStartDate !== null) && ($timelineEndDate !== null)) {
        if ($timelineEndDate->lessThanOrEqualTo($timelineStartDate)) {
            $timelineProgressPercentage = $timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate) ? 100 : 0;
            $timelineTotalDays = 0;
            $timelineElapsedDays = $timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate) ? 0 : null;
            $timelineRemainingDays = $timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate) ? 0 : 0;
        } elseif ($timelineCurrentDate->lessThanOrEqualTo($timelineStartDate)) {
            $timelineProgressPercentage = 0;
            $timelineTotalDays = $timelineStartDate->diffInDays($timelineEndDate);
            $timelineElapsedDays = 0;
            $timelineRemainingDays = $timelineCurrentDate->diffInDays($timelineEndDate);
        } elseif ($timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate)) {
            $timelineProgressPercentage = 100;
            $timelineTotalDays = $timelineStartDate->diffInDays($timelineEndDate);
            $timelineElapsedDays = $timelineTotalDays;
            $timelineRemainingDays = 0;
        } else {
            $elapsedDays = $timelineStartDate->diffInDays($timelineCurrentDate);
            $totalDays = max(1, $timelineStartDate->diffInDays($timelineEndDate));

            $timelineTotalDays = $totalDays;
            $timelineElapsedDays = $elapsedDays;
            $timelineRemainingDays = $timelineCurrentDate->diffInDays($timelineEndDate);
            $timelineProgressPercentage = (int) round(($elapsedDays / $totalDays) * 100);
        }
    }

    $timelineStatusLabel = $emission->integralization_status ?: ($emission->status_label ?: 'Status não informado');
    $timelineFillMinimumWidth = $timelineProgressPercentage > 0 ? '1.1rem' : '0';
    $timelineProgressLabel = ($timelineTotalDays !== null)
        ? number_format($timelineProgressPercentage, 0, ',', '.') . '% do prazo decorrido'
        : 'Prazo não informado';
    $timelineElapsedLabel = match (true) {
        ($timelineStartDate === null) || ($timelineElapsedDays === null) => 'Data de emissão não informada',
        $timelineCurrentDate->lessThan($timelineStartDate) => 'Emissão ainda não iniciada',
        $timelineElapsedDays === 1 => '1 dia desde a emissão',
        default => number_format($timelineElapsedDays, 0, ',', '.') . ' dias desde a emissão',
    };
    $timelineRemainingLabel = match (true) {
        ($timelineEndDate === null) || ($timelineRemainingDays === null) => 'Vencimento não informado',
        $timelineCurrentDate->greaterThanOrEqualTo($timelineEndDate) => 'Prazo encerrado',
        $timelineRemainingDays === 1 => '1 dia até o vencimento',
        default => number_format($timelineRemainingDays, 0, ',', '.') . ' dias até o vencimento',
    };
    $timelineIndicatorLeft = match (true) {
        $timelineTotalDays === null => null,
        $timelineProgressPercentage <= 0 => '0',
        $timelineProgressPercentage >= 100 => 'calc(100% - 1rem)',
        default => 'calc(' . $timelineProgressPercentage . '% - 0.5rem)',
    };

    $hasPayments = $emission->payments->count() > 0;
    $hasPuHistory = $emission->puHistories->count() > 0;

@endphp

<section class="hero position-relative overflow-hidden" style="padding-top: 5rem; padding-bottom: 4rem; background: linear-gradient(135deg, #020918 0%, #051a3d 100%);">
    <div class="container position-relative">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge px-3 py-2 text-uppercase" style="background: rgba(212,175,55,0.2); color: var(--gold);">Relacionamento com o Mercado</span>
                    @if($emission->type)
                        <span class="badge badge-type-{{ strtolower($emission->type) }} px-3 py-2">{{ $emission->type }}</span>
                    @endif
                    <span class="badge badge-status-{{ $emission->status }} px-3 py-2">
                        {{ $statusPalette['label'] }}
                    </span>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-4 mb-4">
                    @if($emission->logo_path)
                        <div class="liquid-glass-logo d-inline-flex align-items-center justify-content-center px-4 py-3" style="min-height: 86px; min-width: 180px;">
                            <img src="{{ Storage::disk($emission->logo_storage_disk)->url($emission->logo_path) }}" alt="{{ $emission->name }}" style="max-height: 52px; max-width: 180px; object-fit: contain;">
                        </div>
                    @endif
                    <div>
                        <h1 class="display-5 fw-bold mb-2 text-white">{{ $emission->name }}</h1>
                        <div class="d-flex flex-wrap gap-3 small text-white-50">
                            <span>IF {{ $emission->if_code ?? '—' }}</span>
                            <span>ISIN {{ $emission->isin_code ?? '—' }}</span>
                            <span>Emissor: {{ $emission->issuer ?? '—' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="surface-card-dark p-4 p-lg-5 h-100" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);">
                    <div class="section-kicker mb-2">Visão geral</div>
                    <div class="d-flex flex-column gap-3 mt-3">
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50 small">Emissão</span>
                            <span class="fw-semibold text-white">{{ $emission->emission_number ?? '—' }}</span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50 small">Série</span>
                            <span class="fw-semibold text-white">{{ $emission->series ?? '—' }}</span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50 small">Tipo de Oferta</span>
                            <span class="fw-semibold text-white">{{ $emission->offer_type ?? '—' }}</span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="text-white-50 small">Registro CVM</span>
                            <span class="fw-semibold text-white">{{ $emission->registered_with_cvm ?? 'Sim' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="row g-4 mb-5">
            @foreach($summaryCards as $summaryCard)
                <div class="col-sm-6 col-xl-3">
                    <div class="surface-card h-100 p-4 shadow-sm border-0">
                        <div class="section-kicker mb-2">{{ $summaryCard['label'] }}</div>
                        <div class="h4 fw-bold text-brand mb-0">{{ $summaryCard['value'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="surface-card emission-timeline-card p-4 p-lg-5 mb-5 shadow-sm border-0">
            <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-4 mb-4">
                <div class="row g-3 flex-grow-1">
                    <div class="col-md-6">
                        <div class="emission-timeline-point">
                            <div class="emission-timeline-label">Data de Emissão</div>
                            <div class="emission-timeline-value">{{ $emission->issue_date?->format('d/m/Y') ?? '—' }}</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="emission-timeline-point">
                            <div class="emission-timeline-label">Data de Vencimento</div>
                            <div class="emission-timeline-value">{{ $emission->maturity_date?->format('d/m/Y') ?? '—' }}</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-column align-items-xl-end gap-2">
                    <span
                        class="emission-timeline-status-badge"
                        style="background: {{ $statusPalette['bg'] }}; border: 1px solid {{ $statusPalette['border'] }}; color: {{ $statusPalette['text'] }};"
                    >
                        {{ $timelineStatusLabel }}
                    </span>
                    <span class="emission-timeline-progress-pill">{{ $timelineProgressLabel }}</span>
                </div>
            </div>

            <div class="emission-timeline-track-shell mb-3">
                <div
                    class="emission-timeline-track"
                    role="progressbar"
                    aria-label="Progresso da emissão"
                    aria-valuenow="{{ $timelineProgressPercentage }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="emission-timeline-track-fill"
                        style="width: {{ $timelineProgressPercentage }}%; min-width: {{ $timelineFillMinimumWidth }};"
                    ></div>

                    @if($timelineIndicatorLeft !== null)
                        <span class="emission-timeline-current-dot" style="left: {{ $timelineIndicatorLeft }};"></span>
                    @endif
                </div>
            </div>

            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                <div class="emission-timeline-meta">{{ $timelineElapsedLabel }}</div>
                <div class="emission-timeline-meta text-lg-end">{{ $timelineRemainingLabel }}</div>
            </div>
        </div>

        <div class="mb-5 d-flex justify-content-center">
            <ul class="nav nav-pills gap-1 emission-detail-tabs-container" id="emissionTabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#caracteristicas">Características</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#pagamentos">Pagamentos & PU</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#documentos">Documentos</a></li>
                <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#simulador">Simulador de Fluxo</a></li>
                {{-- <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#fluxograma">Fluxograma</a></li> --}}
                {{-- <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#comunicados">Comunicados</a></li> --}}
            </ul>
        </div>

        <div class="tab-content" id="emissionTabsContent">
            {{-- Características --}}
            <div class="tab-pane fade show active" id="caracteristicas" role="tabpanel">
                <div class="surface-card p-4 p-lg-5 mb-4 shadow-sm border-0">
                    <div class="row g-4 align-items-end mb-4">
                        <div class="col-lg-8">
                            <div class="section-kicker mb-2">Ficha Técnica</div>
                            <h2 class="h3 fw-bold text-brand mb-2">Características da Operação</h2>
                            <p class="section-copy mb-0">Detalhamento técnico dos parâmetros estruturais, fiduciários e regulatórios que regem esta emissão.</p>
                        </div>
                    </div>

                    <div class="row g-4">
                        @foreach([
                            'Instrumento' => $emission->type,
                            'Emissor' => $emission->issuer,
                            'Coordenador Líder' => $emission->lead_coordinator,
                            'Agente Fiduciário' => $emission->trustee_agent,
                            'Custodiante / Escriturador' => $emission->registrar,
                            'Devedor' => $emission->debtor,
                            'Remuneração' => $emission->formatted_remuneration,
                            'Periodicidade Juros' => $emission->interest_payment_frequency,
                            'Periodicidade Amortização' => $emission->amortization_frequency,
                            'Segmento' => $emission->segment,
                            'Concentração' => $emission->concentration,
                            'Preço Unitário de Emissão' => $emission->issued_price ? 'R$ ' . number_format((float) $emission->issued_price, 2, ',', '.') : null,
                            'Quantidade Emitida' => $emission->issued_quantity ? number_format((float) $emission->issued_quantity, 0, ',', '.') : null,
                            'Total Emitido' => $emission->issued_volume ? 'R$ ' . number_format((float) $emission->issued_volume, 0, ',', '.') : null,
                            'Código ISIN' => $emission->isin_code,
                        ] as $label => $value)
                            <div class="col-sm-6 col-xl-4">
                                <div class="tech-data-card h-100 p-4">
                                    <div class="tech-data-label">{{ $label }}</div>
                                    <div class="tech-data-value">{{ $value ?: '—' }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Pagamentos & PU --}}
            <div class="tab-pane fade" id="pagamentos" role="tabpanel">
                <div class="surface-card p-4 p-lg-5 mb-4 shadow-sm border-0">
                    <div class="row g-4 align-items-end mb-5">
                        <div class="col-lg-8">
                            <div class="section-kicker mb-2">Performance Financeira</div>
                            <h2 class="h3 fw-bold text-brand mb-2">Histórico de Pagamentos e Preços Unitários</h2>
                            <p class="section-copy mb-0">Acompanhamento da evolução do valor do ativo e dos proventos distribuídos aos investidores.</p>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-lg-12">
                            <div class="tech-data-card p-4" style="min-height: 400px;">
                                <div class="section-kicker mb-4 text-center">Fluxo de Proventos</div>
                                @if($hasPayments)
                                    <div style="position: relative; height: 320px; width: 100%;">
                                        <canvas id="paymentsChart"></canvas>
                                    </div>
                                @else
                                    <div class="d-flex align-items-center justify-content-center rounded-4 border border-brand-subtle text-muted text-center px-4" style="min-height: 320px; border-style: dashed !important;">
                                        Nenhum evento de pagamento registrado para esta operação até o momento.
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="tech-data-card h-100 p-4">
                                @php
                                    $lastFiveDays = collect();
                                    for ($i = 0; $i < 5; $i++) {
                                        $date = \Carbon\Carbon::today()->subDays($i);
                                        $pu = $emission->puHistories()->where('date', '<=', $date->format('Y-m-d'))->orderByDesc('date')->first();
                                        $lastFiveDays->push([
                                            'date' => $date,
                                            'value' => $pu?->unit_value,
                                        ]);
                                    }
                                    $todayPu = $lastFiveDays->first()['value'] ?? $emission->current_pu;
                                @endphp

                                <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
                                    <div>
                                        <div class="section-kicker mb-1">PU Atual</div>
                                        <div class="h4 fw-bold text-brand mb-0">{{ $todayPu ? 'R$ ' . number_format((float) $todayPu, 6, ',', '.') : '—' }}</div>
                                    </div>
                                    <span class="badge badge-premium px-3 py-2">Cotação Diária</span>
                                </div>

                                <div class="d-flex flex-column gap-2">
                                    @foreach($lastFiveDays as $dayPu)
                                        <div class="d-flex justify-content-between gap-3 history-row px-3 py-2">
                                            <span class="small text-muted">{{ $dayPu['date']->format('d/m/Y') }}</span>
                                            <span class="small fw-semibold text-brand">{{ $dayPu['value'] ? 'R$ ' . number_format((float) $dayPu['value'], 6, ',', '.') : '—' }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                @if(! $todayPu)
                                    <div class="mt-4 p-3 bg-light rounded text-center small text-muted">
                                        Histórico de PU ainda não disponível para consulta pública.
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="tech-data-card h-100 p-4">
                                @php
                                    $integralizationHistory = $emission->integralizationHistories()->orderByDesc('date')->take(5)->get();
                                    $totalIntegralization = $emission->integralizationHistories()->sum('quantity');
                                @endphp

                                <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
                                    <div>
                                        <div class="section-kicker mb-1">Integralização</div>
                                        <div class="h4 fw-bold text-brand mb-0">
                                            {{ $totalIntegralization ? number_format((float) $totalIntegralization, 0, ',', '.') : ($emission->integralization_status ?: '—') }}
                                        </div>
                                    </div>
                                    <span class="badge badge-premium px-3 py-2">Quantidade</span>
                                </div>

                                <div class="d-flex flex-column gap-2">
                                    @forelse($integralizationHistory as $history)
                                        <div class="d-flex justify-content-between gap-3 history-row px-3 py-2">
                                            <span class="small text-muted">{{ $history->date->format('d/m/Y') }}</span>
                                            <span class="small fw-semibold text-brand">{{ number_format((float) $history->quantity, 0, ',', '.') }}</span>
                                        </div>
                                    @empty
                                        <div class="history-row px-3 py-4 small text-muted text-center">
                                            Nenhum evento de integralização registrado até o momento.
                                        </div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Simulador de Fluxo --}}
            <div class="tab-pane fade" id="simulador" role="tabpanel">
                <div class="surface-card p-4 p-lg-5 mb-4 shadow-sm border-0">
                    <div class="row g-4 align-items-end mb-4">
                        <div class="col-lg-8">
                            <div class="section-kicker mb-2">Fluxo do Investidor</div>
                            <h2 class="h3 fw-bold text-brand mb-2">Simulador de Resultado Estimado</h2>
                            <p class="section-copy mb-0">Estime o ganho bruto da sua posição considerando a variação do PU e o histórico de eventos financeiros (juros e amortizações) no período selecionado.</p>
                        </div>
                    </div>

                    @if($hasPuHistory)
                        @php
                            $indexer = $emission->remuneration_indexer;
                            $spreadRate = (float) $emission->remuneration_rate;
                            $isIndexed = in_array($indexer, ['CDI', 'IPCA']);
                        @endphp

                        <div class="alert alert-info border-0 shadow-sm rounded-4 p-4 mb-5" style="background: rgba(9,27,35,0.03);">
                            <div class="d-flex gap-3">
                                <i class="bi bi-info-circle-fill text-brand" style="font-size: 1.5rem;"></i>
                                <div class="small text-muted" style="text-align: justify;">
                                    <strong>Nota Técnica:</strong> Este simulador projeta o resultado bruto estimado com base no PU da data selecionada e na remuneração contratual da operação, aplicada sobre o prazo restante até o vencimento. {{ $isIndexed ? 'A taxa do ' . $indexer . ' utilizada é uma estimativa e pode variar.' : '' }} Os valores apresentados não consideram tributação, taxas ou eventos extraordinários.
                                </div>
                            </div>
                        </div>

                        <div class="calculator-card p-4 p-lg-5">
                            <div class="row g-4">
                                <div class="col-lg-7">
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label class="calc-input-label">Data de Compra</label>
                                            <select class="form-select doc-filter-select" id="calc_date_start">
                                                @foreach($emission->puHistories->sortBy('date') as $pu)
                                                    <option value="{{ $pu->unit_value }}" data-date="{{ $pu->date->format('Y-m-d') }}">{{ $pu->date->format('d/m/Y') }} (PU: {{ number_format($pu->unit_value, 6, ',', '.') }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="calc-input-label">Quantidade</label>
                                            <input type="number" class="form-control doc-filter-select" id="calc_quantity" value="100" min="1">
                                        </div>
                                    </div>

                                    <div class="mt-3 p-3 rounded-3" style="background: rgba(9,27,35,0.03);">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="small text-muted">Remuneração da Operação</span>
                                            <span class="small fw-semibold text-brand">{{ $emission->formatted_remuneration ?? '—' }}</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-1">
                                            <span class="small text-muted">Vencimento</span>
                                            <span class="small fw-semibold">{{ $emission->maturity_date?->format('d/m/Y') ?? '—' }}</span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-1">
                                            <span class="small text-muted">Prazo Restante</span>
                                            <span class="small fw-semibold" id="detail_remaining_term">—</span>
                                        </div>
                                    </div>

                                    <div class="mt-4 border-top pt-4">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="small text-muted">Investimento Inicial:</span>
                                            <span class="small fw-semibold" id="detail_invested">R$ 0,00</span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="small text-muted">Taxa Utilizada na Projeção (a.a.):</span>
                                            <span class="small fw-semibold" id="detail_total_rate">0,00%</span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="small text-muted">Valor Bruto Projetado no Vencimento:</span>
                                            <span class="small fw-semibold" id="detail_projected_value">R$ 0,00</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-5">
                                    <div class="calc-result-box h-100 d-flex flex-column justify-content-center">
                                        <div class="mb-4">
                                            <div class="calc-result-label">Resultado Bruto Estimado</div>
                                            <div class="calc-result-value" id="calc_result_bruto">R$ 0,00</div>
                                        </div>
                                        <div>
                                            <div class="calc-result-label">Rentabilidade Projetada</div>
                                            <div class="calc-result-value" id="calc_result_perc" style="font-size: 1.5rem;">0,00%</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 p-3 bg-light rounded-3 text-center small text-muted">
                            Simulação informativa com base na remuneração contratual. Não constitui promessa de rentabilidade futura e não substitui os documentos oficiais do escriturador ou custodiante.
                        </div>
                    @else
                        <div class="p-5 bg-light rounded-4 text-center text-muted border border-dashed">
                            <i class="bi bi-calculator display-4 mb-3 d-block"></i>
                            <p class="mb-0">A simulação de fluxo do investidor ficará disponível quando houver histórico suficiente de PU e eventos de pagamento para esta operação.</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Documentos --}}
            <div class="tab-pane fade" id="documentos" role="tabpanel">
                <div class="surface-card p-4 p-lg-5 mb-4 shadow-sm border-0">
                    <div class="row g-4 align-items-end mb-4">
                        <div class="col-lg-8">
                            <div class="section-kicker mb-2">Repositório Fiduciário</div>
                            <h2 class="h3 fw-bold text-brand mb-2">Documentos da Operação</h2>
                            <p class="section-copy mb-0">Acesso integral a todos os arquivos vinculados a esta emissão.</p>
                        </div>
                        @if($emission->documents->count() > 0)
                            <div class="col-lg-4">
                                <label for="docCategoryFilter" class="form-label tech-data-label">Filtrar por Categoria</label>
                                <select id="docCategoryFilter" class="form-select shadow-none doc-filter-select">
                                    <option value="">Todos os Documentos</option>
                                    @foreach($emission->documents->pluck('category')->unique() as $cat)
                                        <option value="{{ $cat }}">{{ \App\Models\Document::CATEGORY_OPTIONS[$cat] ?? ucfirst($cat) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>

                    @if($emission->documents->count() > 0)
                        <div class="document-table-container">
                            <table class="table align-middle mb-0 border-0">
                                <thead>
                                    <tr>
                                        <th style="width: 160px;">Data</th>
                                        <th>Título</th>
                                        <th class="d-none d-lg-table-cell">Categoria</th>
                                        <th style="width: 120px;" class="text-end">Ação</th>
                                    </tr>
                                </thead>
                                <tbody id="documentsTableBody">
                                    @foreach($emission->documents->sortByDesc('published_at') as $doc)
                                        <tr class="doc-row" data-category="{{ $doc->category }}">
                                            <td class="text-muted">{{ optional($doc->published_at)->format('d/m/Y') ?? '—' }}</td>
                                            <td>
                                                <div class="fw-semibold text-brand">{{ $doc->title }}</div>
                                                <div class="small text-muted d-lg-none">{{ $doc->category_label }}</div>
                                            </td>
                                            <td class="d-none d-lg-table-cell">
                                                <span class="badge badge-premium px-2 py-1 small">{{ $doc->category_label }}</span>
                                            </td>
                                            <td class="text-end">
                                                <a href="{{ Storage::disk($doc->resolved_storage_disk)->url($doc->file_path) }}" target="_blank" class="btn doc-action-btn">Download</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="p-5 bg-light rounded-4 text-center text-muted">
                            Documentos serão disponibilizados conforme publicação oficial da operação.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Fluxograma --}}
            @if(false)
            <div class="tab-pane fade" id="fluxograma" role="tabpanel">
                <div class="surface-card p-4 p-lg-5 mb-4 shadow-sm border-0">
                    <div class="row g-4 align-items-end mb-4">
                        <div class="col-lg-8">
                            <div class="section-kicker mb-2">Estrutura de Crédito</div>
                            <h2 class="h3 fw-bold text-brand mb-2">Fluxograma da Operação</h2>
                            <p class="section-copy mb-0">Representação visual do fluxo de pagamentos, garantias e interações entre os agentes da emissão.</p>
                        </div>
                    </div>

                    <div class="p-5 bg-light rounded-4 text-center border border-dashed">
                        <div class="py-5">
                            <i class="bi bi-diagram-3 display-4 text-muted mb-3 d-block"></i>
                            <p class="text-muted mb-0">O diagrama visual desta operação está sendo processado e será disponibilizado em breve.</p>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- Comunicados --}}
            @if(false)
            <div class="tab-pane fade" id="comunicados" role="tabpanel">
                <div class="surface-card p-4 p-lg-5 mb-4 shadow-sm border-0">
                    <div class="row g-4 align-items-end mb-4">
                        <div class="col-lg-8">
                            <div class="section-kicker mb-2">Transparência Ativa</div>
                            <h2 class="h3 fw-bold text-brand mb-2">Comunicados e Fatos Relevantes</h2>
                            <p class="section-copy mb-0">Informações relevantes ao mercado, avisos e convocações para assembleias.</p>
                        </div>
                    </div>

                    @php
                        $comunicados = $emission->documents->whereIn('category', ['fatos_relevantes', 'anuncios', 'assembleias', 'convocacoes_assembleias']);
                    @endphp

                    @if($comunicados->count() > 0)
                        <div class="document-table-container">
                            <table class="table align-middle mb-0 border-0">
                                <thead>
                                    <tr>
                                        <th style="width: 160px;">Data</th>
                                        <th>Título</th>
                                        <th style="width: 120px;" class="text-end">Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($comunicados->sortByDesc('published_at') as $doc)
                                        <tr>
                                            <td class="text-muted">{{ optional($doc->published_at)->format('d/m/Y') ?? '—' }}</td>
                                            <td>
                                                <div class="fw-semibold text-brand">{{ $doc->title }}</div>
                                                <div class="badge badge-premium px-2 py-1 small">{{ $doc->category_label }}</div>
                                            </td>
                                            <td class="text-end">
                                                <a href="{{ Storage::disk($doc->resolved_storage_disk)->url($doc->file_path) }}" target="_blank" class="btn doc-action-btn">Abrir</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="p-5 bg-light rounded-4 text-center text-muted">
                            Nenhum comunicado ou fato relevante registrado para esta operação.
                        </div>
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>
</section>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script nonce="{{ $cspNonce }}">
document.addEventListener('DOMContentLoaded', function() {
    // Abas
    const tabLinks = document.querySelectorAll('#emissionTabs .nav-link');
    tabLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const tab = new bootstrap.Tab(this);
            tab.show();
            // Update hash without scrolling
            history.pushState(null, null, this.getAttribute('href'));
        });
    });

    // Handle initial hash and redirection for disabled tabs
    const handleHash = () => {
        const hash = window.location.hash;
        if (hash === '#fluxograma' || hash === '#comunicados') {
            history.replaceState(null, null, '#caracteristicas');
            const targetTab = document.querySelector('a[href="#caracteristicas"]');
            if (targetTab) {
                bootstrap.Tab.getInstance(targetTab)?.show() || new bootstrap.Tab(targetTab).show();
            }
        } else if (hash) {
            const targetTab = document.querySelector(`a[href="${hash}"]`);
            if (targetTab) {
                bootstrap.Tab.getInstance(targetTab)?.show() || new bootstrap.Tab(targetTab).show();
            }
        }
    };

    handleHash();
    window.addEventListener('hashchange', handleHash);

    // Filtro de Documentos
    const docFilter = document.getElementById('docCategoryFilter');
    if (docFilter) {
        docFilter.addEventListener('change', function() {
            const cat = this.value;
            document.querySelectorAll('.doc-row').forEach(row => {
                row.style.display = (cat === '' || row.dataset.category === cat) ? '' : 'none';
            });
        });
    }

    // Gráfico de Pagamentos
    const chartElement = document.getElementById('paymentsChart');
    if (chartElement) {
        const labels = {!! json_encode($emission->payments->pluck('payment_date')->map(fn ($date) => $date->format('d/m/Y'))) !!};
        new Chart(chartElement, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Amortização',
                        data: {!! json_encode($emission->payments->pluck('amortization_value')) !!},
                        backgroundColor: '#091b23',
                        borderRadius: 4,
                    },
                    {
                        label: 'Juros',
                        data: {!! json_encode($emission->payments->pluck('interest_value')) !!},
                        backgroundColor: '#a06e28',
                        borderRadius: 4,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { display: false }, grid: { display: false } },
                    x: { grid: { display: false } }
                },
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    // Simulador de Projeção do Investidor
    const calcDateStart = document.getElementById('calc_date_start');
    const calcQty = document.getElementById('calc_quantity');
    const calcBruto = document.getElementById('calc_result_bruto');
    const calcPerc = document.getElementById('calc_result_perc');
    const detailInvested = document.getElementById('detail_invested');
    const detailTotalRate = document.getElementById('detail_total_rate');
    const detailProjectedValue = document.getElementById('detail_projected_value');
    const detailRemainingTerm = document.getElementById('detail_remaining_term');

    const spreadRate = {{ $spreadRate }};
    const maturityDate = '{{ $emission->maturity_date?->format('Y-m-d') }}';

    const currencyFmt = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

    function calculate() {
        if (!calcDateStart || !calcQty || !maturityDate) return;

        const puStart = parseFloat(calcDateStart.value);
        const qty = parseFloat(calcQty.value) || 0;
        const dateStart = calcDateStart.options[calcDateStart.selectedIndex].dataset.date;

        if (isNaN(puStart) || qty <= 0) {
            calcBruto.innerText = 'R$ 0,00';
            calcPerc.innerText = '0,00%';
            return;
        }

        const totalAnnualRate = spreadRate;

        const start = new Date(dateStart + 'T00:00:00');
        const end = new Date(maturityDate + 'T00:00:00');
        const diffMs = end.getTime() - start.getTime();
        const diffDays = Math.max(0, Math.round(diffMs / (1000 * 60 * 60 * 24)));
        const yearsRemaining = diffDays / 365;

        const years = Math.floor(yearsRemaining);
        const months = Math.round((yearsRemaining - years) * 12);
        if (detailRemainingTerm) {
            detailRemainingTerm.innerText = years > 0
                ? years + (years === 1 ? ' ano' : ' anos') + (months > 0 ? ' e ' + months + (months === 1 ? ' mês' : ' meses') : '')
                : months + (months === 1 ? ' mês' : ' meses');
        }

        const investedInitial = puStart * qty;
        const projectedValue = investedInitial * Math.pow(1 + totalAnnualRate / 100, yearsRemaining);
        const profit = projectedValue - investedInitial;
        const variation = (investedInitial > 0) ? (profit / investedInitial) * 100 : 0;

        detailInvested.innerText = currencyFmt.format(investedInitial);
        detailTotalRate.innerText = totalAnnualRate.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '% a.a.';
        detailProjectedValue.innerText = currencyFmt.format(projectedValue);

        calcBruto.innerText = currencyFmt.format(profit);
        calcPerc.innerText = (variation >= 0 ? '+' : '') + variation.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
        calcPerc.style.color = variation >= 0 ? '#ffffff' : '#ffc107';
    }

    if (calcDateStart) {
        calcDateStart.addEventListener('change', calculate);
        calcQty.addEventListener('input', calculate);
        calculate();
    }
});
</script>
@endpush
@endsection

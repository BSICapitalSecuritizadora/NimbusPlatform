@extends('site.layout')
@section('title', 'Emissões — BSI Capital')

@push('head')
<style>
    .emissions-pagination-shell {
        margin-top: 2.5rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1.5rem;
        padding: 1.25rem 2rem;
        border: 1px solid rgba(9,27,35,0.06);
        border-radius: 20px;
        background: #ffffff;
        box-shadow: 0 4px 14px rgba(9,27,35,0.02);
    }

    .emissions-pagination-summary {
        margin: 0;
        color: #8c98a4;
        font-size: 0.92rem;
        line-height: 1.6;
        text-align: center;
    }

    .emissions-pagination-summary strong {
        color: var(--brand);
        font-weight: 700;
    }

    .emissions-pagination-nav {
        width: 100%;
    }

    .emissions-pagination-nav .pagination {
        margin-top: 0;
        gap: 0.5rem;
    }

    .emissions-pagination-nav .page-link {
        border: none;
        background: rgba(9,27,35,0.03);
        color: var(--brand);
        border-radius: 10px !important;
        min-width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        transition: all 0.2s ease;
    }

    .emissions-pagination-nav .page-item.active .page-link {
        background: var(--brand);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(9,27,35,0.2);
    }

    .emissions-pagination-nav .page-item.disabled .page-link {
        background: transparent;
        color: #aeb8c3;
    }

    .emissions-pagination-nav .page-item:not(.active):not(.disabled) .page-link:hover {
        background: rgba(212,175,55,0.1);
        color: var(--gold);
        transform: translateY(-2px);
    }

    @media (min-width: 992px) {
        .emissions-pagination-shell {
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            gap: 1.5rem;
        }

        .emissions-pagination-summary {
            text-align: left;
        }

        .emissions-pagination-nav {
            display: flex;
            justify-content: flex-end;
        }
    }

    .search-input-group {
        background: #ffffff;
        border: 1px solid rgba(9,27,35,0.1);
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(9,27,35,0.02);
    }
    .search-input-group:focus-within {
        border-color: var(--gold) !important;
        box-shadow: 0 0 0 3px rgba(212,175,55,0.15) !important;
    }

    .filter-select {
        background-color: rgba(9,27,35,0.03);
        border-radius: 10px;
        padding: 0.75rem 1rem;
        color: var(--brand);
        font-weight: 500;
        border: 1px solid transparent;
        transition: all 0.2s ease;
    }
    .filter-select:focus {
        background-color: #ffffff;
        border-color: rgba(9,27,35,0.15);
        box-shadow: 0 0 0 3px rgba(9,27,35,0.05);
    }
    .filter-label {
        font-size: 0.7rem;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        font-weight: 700;
        color: #5d687b;
        margin-bottom: 0.5rem;
    }

    .emission-card {
        background: #ffffff;
        border-radius: 20px !important;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        border: 1px solid rgba(9,27,35,0.05) !important;
        box-shadow: 0 4px 14px rgba(9,27,35,0.02) !important;
    }
    .emission-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 30px rgba(9,27,35,0.08) !important;
        border-color: rgba(9,27,35,0.08) !important;
    }
    .emission-card-meta-label {
        font-size: 0.65rem;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        font-weight: 700;
        color: #8c98a4;
        margin-bottom: 0.3rem;
    }
    .emission-card-meta-value {
        font-weight: 600;
        color: var(--brand);
        font-size: 0.9rem;
    }
    .emission-card-btn {
        background: rgba(9,27,35,0.03);
        color: var(--brand);
        font-weight: 600;
        border-radius: 12px;
        padding: 0.7rem 1.2rem;
        transition: all 0.2s ease;
        border: 1px solid transparent;
    }
    .emission-card-btn:hover {
        background: #ffffff;
        color: var(--brand);
        border-color: rgba(9,27,35,0.15);
        box-shadow: 0 4px 12px rgba(9,27,35,0.05);
    }
</style>
@endpush

@section('content')
@php
    $activeFilters = array_filter([
        'Busca' => ($q ?? '') !== '' ? '"'.($q ?? '').'"' : null,
        'Tipo' => ($type ?? '') !== '' ? $type : null,
        'Data de emissão' => ($issue_date_order ?? '') !== '' ? ($issue_date_order === 'desc' ? 'Mais recente para mais antiga' : 'Mais antiga para mais recente') : null,
        'Data de vencimento' => ($maturity_date_order ?? '') !== '' ? ($maturity_date_order === 'desc' ? 'Mais recente para mais antiga' : 'Mais antiga para mais recente') : null,
    ]);
@endphp

<section class="hero position-relative d-flex align-items-center" style="min-height: 34vh;">
    <div class="container">
        <div class="row g-4 align-items-end">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase">Mercado primário</span>
                <h1 class="display-4 fw-bold mb-3">Track Record de Operações</h1>
                <p class="lead mb-0" style="max-width: 760px;">
                    Consulte as emissões e operações estruturadas pela BSI Capital, com informações técnicas, documentos da operação e dados relevantes para acompanhamento no mercado de capitais.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="bg-white rounded-4 shadow-sm p-4 p-lg-5 mb-4" style="border: 1px solid rgba(9,27,35,0.05);">
            <div class="row g-4 align-items-end">
                <div class="col-lg-5">
                    <div class="small text-uppercase fw-bold mb-2" style="color: var(--gold); letter-spacing: 0.15em;">Pesquisa e filtros</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Filtros Dinâmicos e Dados Organizados</h2>
                    <p class="mb-0" style="color: #5d687b;">
                        Consulte emissões e operações estruturadas com filtros por instrumento, emissor e datas relevantes. As informações são apresentadas de forma clara e organizada para apoiar o acompanhamento das operações no mercado de capitais.
                    </p>
                </div>
                <div class="col-lg-7">
                    <form method="GET" class="row g-3">
                        <div class="col-12">
                            <div class="input-group search-input-group">
                                <span class="input-group-text border-0 bg-transparent ps-4" style="color: var(--brand);">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                </span>
                                <input class="form-control border-0 bg-transparent shadow-none px-3 py-3" name="q" value="{{ $q ?? '' }}" placeholder="Busque por nome, emissor ou código IF" style="font-size: 0.95rem; color: var(--brand);">
                                <button class="btn btn-brand px-4 px-md-5 border-0" style="font-weight: 600;">Buscar</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label filter-label">Tipo de emissão</label>
                            <select name="type" class="form-select shadow-none filter-select" onchange="this.form.submit()">
                                <option value="">Todos os tipos</option>
                                <option value="CR" {{ ($type ?? '') === 'CR' ? 'selected' : '' }}>CR</option>
                                <option value="CRA" {{ ($type ?? '') === 'CRA' ? 'selected' : '' }}>CRA</option>
                                <option value="CRI" {{ ($type ?? '') === 'CRI' ? 'selected' : '' }}>CRI</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label filter-label">Data de emissão</label>
                            <select name="issue_date_order" class="form-select shadow-none filter-select" onchange="this.form.submit()">
                                <option value="">Ordenar por...</option>
                                <option value="desc" {{ ($issue_date_order ?? '') === 'desc' ? 'selected' : '' }}>Mais recente &gt; Mais antiga</option>
                                <option value="asc" {{ ($issue_date_order ?? '') === 'asc' ? 'selected' : '' }}>Mais antiga &gt; Mais recente</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label filter-label">Data de vencimento</label>
                            <select name="maturity_date_order" class="form-select shadow-none filter-select" onchange="this.form.submit()">
                                <option value="">Ordenar por...</option>
                                <option value="desc" {{ ($maturity_date_order ?? '') === 'desc' ? 'selected' : '' }}>Mais recente &gt; Mais antiga</option>
                                <option value="asc" {{ ($maturity_date_order ?? '') === 'asc' ? 'selected' : '' }}>Mais antiga &gt; Mais recente</option>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center mb-4">
            <div class="section-copy">
                <strong>{{ $emissions->total() }}</strong> operação(ões) pública(s)
                @if($activeFilters !== [])
                    com filtros ativos para leitura dirigida
                @else
                    disponíveis para consulta pública
                @endif
            </div>

            <div class="d-flex flex-wrap gap-2">
                @if($activeFilters !== [])
                    <a href="{{ route('site.emissions') }}" class="btn btn-outline-brand btn-sm px-4">Limpar filtros</a>
                @endif
                <span class="result-chip">{{ $emissions->currentPage() }} / {{ $emissions->lastPage() }} página(s)</span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            @forelse($activeFilters as $label => $value)
                <span class="result-chip">{{ $label }}: {{ $value }}</span>
            @empty
                <span class="result-chip">Sem filtros ativos</span>
            @endforelse
        </div>

        <div class="row g-4">
            @forelse($emissions as $e)
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 emission-card overflow-hidden">
                        <div style="height: 4px; background: linear-gradient(90deg, var(--brand), var(--gold), var(--brand)); opacity: 0.85;"></div>
                        <div class="card-body p-3 p-lg-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div class="flex-grow-1">
                                    <div class="small text-uppercase fw-bold mb-2" style="color: #8c98a4; letter-spacing: 0.05em;">{{ $e->if_code ?? 'CRI' }}</div>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        @if($e->type)
                                            <span class="badge badge-type-{{ strtolower($e->type) }} px-3 py-1" style="border-radius: 6px;">{{ $e->type }}</span>
                                        @endif
                                        @if($e->status_label)
                                            <span class="badge badge-status-{{ $e->status }} px-3 py-1" style="border-radius: 6px;">
                                                {{ $e->status_label }}
                                            </span>
                                        @endif
                                    </div>
                                    <h3 class="h5 fw-bold text-brand mb-0" style="line-height: 1.4; word-wrap: break-word;">{{ $e->name }}</h3>
                                </div>
                                <div class="d-flex align-items-center justify-content-center flex-shrink-0 p-2" style="width: 58px; height: 58px; border-radius: 12px; background: #ffffff; border: 1px solid rgba(9,27,35,0.05); box-shadow: 0 2px 8px rgba(0,0,0,0.02); color: var(--brand);">
                                    @if($e->logo_path)
                                        <img src="{{ Storage::disk($e->logo_storage_disk)->url($e->logo_path) }}" alt="{{ $e->name }}" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                                    @else
                                        @if($e->type === 'CRI')
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1m4 0h1m-5 4h1m4 0h1M9 16h1m4 0h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path></svg>
                                        @elseif($e->type === 'CRA')
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                                        @else
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                        @endif
                                    @endif
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="emission-card-meta-label">Emissão</div>
                                    <div class="emission-card-meta-value">{{ $e->emission_number ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="emission-card-meta-label">Série</div>
                                    <div class="emission-card-meta-value">{{ $e->series ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="emission-card-meta-label">Data de emissão</div>
                                    <div class="emission-card-meta-value">{{ optional($e->issue_date)->format('d/m/Y') ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="emission-card-meta-label">Vencimento</div>
                                    <div class="emission-card-meta-value">{{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="emission-card-meta-label">Remuneração</div>
                                    <div class="emission-card-meta-value">{{ $e->formatted_remuneration ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="emission-card-meta-label">Emissor</div>
                                    <div class="emission-card-meta-value">{{ $e->issuer ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="emission-card-meta-label">Código ISIN</div>
                                    <div class="emission-card-meta-value">{{ $e->isin_code ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="emission-card-meta-label">Concentração</div>
                                    <div class="emission-card-meta-value">{{ $e->concentration ?? '—' }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 p-3 p-lg-4 pt-0 d-grid">
                            <a href="{{ route('site.emissions.show', $e->if_code) }}" class="btn emission-card-btn w-100 text-center">Ver detalhes da emissão</a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="card p-5 text-center text-muted">
                        <div class="fw-semibold mb-2">Nenhuma operação corresponde aos filtros atuais. Fale com nossa mesa de estruturação para demandas específicas.</div>
                        <div class="small">Revise os critérios selecionados ou limpe a pesquisa para ampliar o universo de consulta.</div>
                    </div>
                </div>
            @endforelse
        </div>

        @if($emissions->hasPages())
            <div class="emissions-pagination-shell">
                <p class="emissions-pagination-summary">
                Exibindo <strong>{{ $emissions->firstItem() }}</strong> a <strong>{{ $emissions->lastItem() }}</strong> de <strong>{{ $emissions->total() }}</strong> operações
                </p>
                <div class="emissions-pagination-nav">
                    {{ $emissions->links('site.ri-pagination') }}
                </div>
            </div>
        @endif
    </div>
</section>
@endsection

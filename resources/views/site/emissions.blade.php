@extends('site.layout')
@section('title', 'Emissões — BSI Capital')

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
                <h1 class="display-4 fw-bold mb-3">Operações estruturadas e coordenadas pela BSI Capital</h1>
                <p class="lead mb-0" style="max-width: 760px;">
                    Consulte as emissões públicas com dados operacionais, tipo de instrumento, status e documentação — organizados para análise direta por investidores e originadores.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="surface-card p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-end">
                <div class="col-lg-5">
                    <div class="section-kicker mb-2">Pesquisa e filtros</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Filtre por instrumento, setor ou emissor</h2>
                    <p class="section-copy mb-0">
                        Busca por nome, tipo de ativo ou critério operacional para identificar e comparar operações com precisão.
                    </p>
                </div>
                <div class="col-lg-7">
                    <form method="GET" class="row g-3">
                        <div class="col-12">
                            <div class="input-group">
                                <span class="input-group-text border-end-0 bg-transparent ps-4">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                </span>
                                <input class="form-control border-start-0" name="q" value="{{ $q ?? '' }}" placeholder="Busque por nome, emissor ou código IF">
                                <button class="btn btn-brand px-4 px-md-5">Buscar</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo de emissão</label>
                            <select name="type" class="form-select" onchange="this.form.submit()">
                                <option value="">Todos os tipos</option>
                                <option value="CR" {{ ($type ?? '') === 'CR' ? 'selected' : '' }}>CR</option>
                                <option value="CRA" {{ ($type ?? '') === 'CRA' ? 'selected' : '' }}>CRA</option>
                                <option value="CRI" {{ ($type ?? '') === 'CRI' ? 'selected' : '' }}>CRI</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Data de emissão</label>
                            <select name="issue_date_order" class="form-select" onchange="this.form.submit()">
                                <option value="">Ordenar por...</option>
                                <option value="desc" {{ ($issue_date_order ?? '') === 'desc' ? 'selected' : '' }}>Mais recente &gt; Mais antiga</option>
                                <option value="asc" {{ ($issue_date_order ?? '') === 'asc' ? 'selected' : '' }}>Mais antiga &gt; Mais recente</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Data de vencimento</label>
                            <select name="maturity_date_order" class="form-select" onchange="this.form.submit()">
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
                    <div class="card h-100 border-0 shadow-sm emission-card overflow-hidden">
                        <div style="height: 4px; background: linear-gradient(90deg, var(--brand), var(--gold), var(--brand));"></div>
                        <div class="card-body p-3 p-lg-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div class="flex-grow-1">
                                    <div class="small text-uppercase text-muted fw-semibold mb-2">{{ $e->if_code ?? 'CRI' }}</div>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        @if($e->type)
                                            <span class="badge badge-soft px-3 py-2">{{ $e->type }}</span>
                                        @endif
                                        @if($e->status_label)
                                            <span class="badge px-3 py-2" style="background: rgba(0,32,91,0.08); color: var(--brand); border: 1px solid rgba(0,32,91,0.12);">
                                                {{ $e->status_label }}
                                            </span>
                                        @endif
                                    </div>
                                    <h3 class="h5 fw-bold text-brand mb-0" style="line-height: 1.45; word-wrap: break-word;">{{ $e->name }}</h3>
                                </div>
                                <div class="d-flex align-items-center justify-content-center flex-shrink-0 p-2" style="width: 64px; height: 64px; border-radius: 14px; background: rgba(0,32,91,0.06); color: var(--brand);">
                                    @if($e->logo_path)
                                        <img src="{{ Storage::url($e->logo_path) }}" alt="{{ $e->name }}" style="max-height: 100%; max-width: 100%; object-fit: contain;">
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

                            <div class="row g-2 small">
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Emissão</div>
                                    <div class="fw-semibold">{{ $e->emission_number ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Série</div>
                                    <div class="fw-semibold">{{ $e->series ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Data de emissão</div>
                                    <div class="fw-semibold">{{ optional($e->issue_date)->format('d/m/Y') ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Vencimento</div>
                                    <div class="fw-semibold">{{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}</div>
                                </div>
                                <div class="col-12">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Remuneração</div>
                                    <div class="fw-semibold">{{ $e->remuneration ?? '—' }}</div>
                                </div>
                                <div class="col-12">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Emissor</div>
                                    <div class="fw-semibold">{{ $e->issuer ?? '—' }}</div>
                                </div>
                                <div class="col-12">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Código ISIN</div>
                                    <div class="fw-semibold">{{ $e->isin_code ?? '—' }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 p-3 p-lg-4 pt-0 d-grid">
                            <a href="{{ route('site.emissions.show', $e->if_code) }}" class="btn btn-outline-brand btn-sm w-100">Ver detalhes</a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="card p-5 text-center text-muted">
                        <div class="fw-semibold mb-2">Nenhuma operação corresponde aos filtros atuais.</div>
                        <div class="small">Revise os critérios selecionados ou limpe a pesquisa para ampliar o universo de consulta.</div>
                    </div>
                </div>
            @endforelse
        </div>

        @if($emissions->hasPages())
            <div class="mt-4 text-center small text-muted">
                Exibindo <strong>{{ $emissions->firstItem() }}</strong> a <strong>{{ $emissions->lastItem() }}</strong> de <strong>{{ $emissions->total() }}</strong> operações
            </div>
            <div class="mt-3">
                {{ $emissions->links() }}
            </div>
        @endif
    </div>
</section>
@endsection

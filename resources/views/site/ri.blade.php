@extends('site.layout')
@section('title', 'Relações com Investidores — BSI Capital')

@push('head')
<style>
    .ri-pagination-shell {
        margin-top: 2rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.25rem;
        border: 1px solid color-mix(in srgb, var(--brand) 10%, var(--border));
        border-radius: 22px;
        background: color-mix(in srgb, var(--surface) 95%, var(--brand) 5%);
        box-shadow: var(--shadow-soft);
    }

    .ri-pagination-summary {
        margin: 0;
        color: var(--muted);
        font-size: 0.92rem;
        line-height: 1.6;
        text-align: center;
    }

    .ri-pagination-summary strong {
        color: var(--brand);
    }

    .ri-pagination-nav {
        width: 100%;
    }

    .ri-pagination-nav .pagination {
        margin-top: 0;
    }

    @media (min-width: 992px) {
        .ri-pagination-shell {
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            gap: 1.5rem;
        }

        .ri-pagination-summary {
            text-align: left;
        }

        .ri-pagination-nav {
            display: flex;
            justify-content: flex-end;
        }
    }

    .filter-pill {
        border-radius: 50rem;
        padding: 0.55rem 1.25rem;
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .filter-pill.active {
        background: var(--brand);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(9,27,35,0.15);
    }
    .filter-pill.inactive {
        background: rgba(9,27,35,0.04);
        color: #5d687b;
        border-color: rgba(9,27,35,0.08);
    }
    .filter-pill.inactive:hover {
        background: rgba(9,27,35,0.08);
        color: var(--brand);
        transform: translateY(-1px);
    }

    .search-input-group {
        background: transparent;
        border: none;
        border-bottom: 1px solid rgba(9,27,35,0.1);
        border-radius: 0;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    .search-input-group:focus-within {
        border-color: var(--gold);
        box-shadow: none;
    }

    /* Mobile horizontal scroll for filters */
    .filter-scroll-wrapper {
        margin: 0 -1.5rem;
        padding: 0 1.5rem;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none; /* Firefox */
    }
    .filter-scroll-wrapper::-webkit-scrollbar {
        display: none; /* Chrome/Safari/Edge */
    }
    .filter-pills-container {
        flex-wrap: nowrap;
        padding-bottom: 0.5rem; /* space for active shadow */
    }
    
    @media (min-width: 992px) {
        .filter-scroll-wrapper {
            margin: 0;
            padding: 0;
            overflow-x: visible;
        }
        .filter-pills-container {
            flex-wrap: wrap;
            padding-bottom: 0;
        }
    }
</style>
@endpush

@section('content')
@php
    $activeFilters = array_filter([
        'Categoria' => $category ? ($categories[$category] ?? $category) : null,
        'Busca' => $q !== '' ? '"'.$q.'"' : null,
    ]);
@endphp

<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('{{ asset('images/relatorios.png') }}') center/cover; mix-blend-mode: luminosity;"></div>
    <div class="container position-relative z-1">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">Relações com <span style="color: var(--gold);">Investidores</span></h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 760px;">
                    Central pública de documentos, comunicados e informações institucionais da BSI Capital, organizada para apoiar investidores, agentes da operação e demais stakeholders no acompanhamento das emissões e da companhia.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ config('services.portal.url') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Acessar Portal do Investidor
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.contact') }}" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Falar com RI
                    </a>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="surface-card-dark p-4">
                    <div class="small text-uppercase text-white-50 fw-semibold mb-2">Repositório Institucional</div>
                    <div class="d-flex justify-content-between align-items-end mb-3">
                        <div>
                            <div class="fs-2 fw-bold text-white">{{ $docs->total() }}</div>
                            <div class="small text-white-50">documentos disponíveis</div>
                        </div>
                        <div class="badge badge-soft px-3 py-2">{{ count($categories) }} categorias</div>
                    </div>
                    <div class="small text-white-50">Fatos relevantes, atas de assembleias, demonstrações financeiras e documentos societários — histórico documental da BSI Capital.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5" style="background: rgba(9,27,35,0.02);">
    <div class="container py-lg-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-brand">O que você encontra nesta central</h2>
        </div>
        <div class="row g-3">
            @php
                $features = [
                    'Comunicados e fatos relevantes',
                    'Anúncios de início e encerramento',
                    'Atas e convocações de assembleias',
                    'Demonstrações financeiras',
                    'Relatórios anuais',
                    'Documentos das operações',
                    'Documentos societários',
                    'Publicações institucionais'
                ];
            @endphp
            @foreach($features as $feature)
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-0 shadow-sm p-4 text-center d-flex align-items-center justify-content-center" style="border-radius: 16px; background: #ffffff;">
                        <span class="fw-semibold" style="color: var(--brand); font-size: 0.95rem; line-height: 1.4;">{{ $feature }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="bg-white p-4 p-lg-5 mb-4" style="border: 1px solid rgba(9,27,35,0.05);">
            <div class="row g-4 align-items-end">
                <div class="col-lg-7">
                    <div class="small text-uppercase fw-bold mb-2" style="color: var(--gold); letter-spacing: 0.15em;">Consulta pública</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Repositório de Documentos Públicos</h2>
                    <p class="mb-0" style="color: #5d687b;">
                        Consulte documentos por categoria, emissão (CRI, CRA, CR), data ou palavra-chave, com acesso estruturado às publicações públicas da nossa securitizadora.
                    </p>
                </div>
                <div class="col-lg-5">
                    <form method="GET" id="riForm">
                        <div class="input-group search-input-group">
                            <input
                                type="text"
                                class="form-control border-0 bg-transparent shadow-none px-4 py-3"
                                name="q"
                                value="{{ $q }}"
                                placeholder="Pesquisar documentos e comunicados..."
                                style="font-size: 0.95rem; color: var(--brand);"
                            >
                            <button type="submit" class="input-group-text border-0 bg-transparent px-4" style="color: var(--brand);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </button>
                        </div>
                        @if($category)
                            <input type="hidden" name="category" value="{{ $category }}">
                        @endif
                    </form>
                </div>
            </div>

            <div class="mt-4 pt-4 border-top" style="border-color: rgba(9,27,35,0.06) !important;">
                <div class="filter-scroll-wrapper">
                    <div class="d-flex gap-2 filter-pills-container">
                        <a href="{{ route('site.ri', array_filter(['q' => $q])) }}" class="filter-pill flex-shrink-0 {{ !$category ? 'active' : 'inactive' }}">Todos</a>
                        @foreach($categories as $key => $label)
                            <a href="{{ route('site.ri', array_filter(['category' => $key, 'q' => $q])) }}" class="filter-pill flex-shrink-0 {{ $category === $key ? 'active' : 'inactive' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center mb-4">
            <div class="section-copy">
                <strong>{{ $docs->total() }}</strong> documentos disponíveis
                @if($category)
                    na categoria <strong>{{ $categories[$category] ?? $category }}</strong>
                @endif
                @if($q !== '')
                    para a busca <strong>"{{ $q }}"</strong>
                @endif
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if($activeFilters !== [])
                    <a href="{{ route('site.ri') }}" class="btn btn-outline-brand btn-sm px-4">Limpar filtros</a>
                @endif
                <span class="result-chip" style="border-radius: 0px;">Página {{ $docs->currentPage() }} de {{ $docs->lastPage() }}</span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            @forelse($activeFilters as $label => $value)
                <span class="result-chip">{{ $label }}: {{ $value }}</span>
            @empty
                <span class="result-chip">Sem filtros ativos</span>
            @endforelse
        </div>

        <div class="d-flex flex-column gap-3">
            @forelse($docs as $d)
                <div class="card border-0 shadow-sm overflow-hidden">
                    <div class="row g-0 align-items-center">
                        <div class="col">
                            <div class="p-3 p-lg-4">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; border-radius: 14px; background: rgba(0, 32, 91, 0.06); color: var(--brand);">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h3 class="h5 fw-bold text-brand mb-2">{{ $d->title }}</h3>
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                            <span class="badge badge-soft px-3 py-2">{{ $categories[$d->category] ?? ($d->category ?? '—') }}</span>
                                            @foreach($d->emissions as $emission)
                                                <span class="badge px-3 py-2" style="background: rgba(212,175,55,0.1); color: var(--gold); border: 1px solid rgba(212,175,55,0.2);">{{ $emission->name }}</span>
                                            @endforeach
                                        </div>
                                        <div class="d-flex flex-wrap gap-3 small text-muted">
                                            <span>{{ optional($d->{$dateField})->format('d/m/Y') ?? '—' }}</span>
                                            @if($d->file_size)
                                                <span>{{ $d->file_size >= 1048576 ? number_format($d->file_size / 1048576, 1) . ' MB' : number_format($d->file_size / 1024, 0) . ' KB' }}</span>
                                            @endif
                                            <span>Documento público</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-auto px-3 px-lg-4 pb-3 pb-lg-0 d-grid">
                            <a href="{{ Storage::disk($d->resolved_storage_disk)->url($d->file_path) }}" target="_blank" class="btn btn-brand btn-sm px-4 d-block text-center" download>
                                Abrir documento
                            </a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="card p-5 text-center text-muted" style="border: 1px solid rgba(9,27,35,0.05); background: #fdfdfd; border-radius: 0px;">
                    <svg class="mb-3 mx-auto" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="color: var(--gold);">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <div class="fw-semibold mb-2" style="color: var(--brand); font-size: 1.1rem;">Nenhum documento foi localizado para os critérios aplicados.</div>
                    <div class="small mb-4">Caso não encontre o que procura, nossa equipe de RI está à disposição para auxiliá-lo.</div>
                    <div class="d-flex justify-content-center">
                        <a href="{{ route('site.contact') }}" class="btn btn-outline-brand btn-sm px-4">Solicitar documento específico</a>
                    </div>
                </div>
            @endforelse
        </div>

        @if($docs->hasPages())
            <div class="ri-pagination-shell">
                <p class="ri-pagination-summary">
                    Exibindo <strong>{{ $docs->firstItem() }}</strong> a <strong>{{ $docs->lastItem() }}</strong> de <strong>{{ $docs->total() }}</strong> documentos
                </p>
                <div class="ri-pagination-nav">
                    {{ $docs->links('site.ri-pagination') }}
                </div>
            </div>
        @endif
    </div>
</section>

@endsection

@extends('site.layout')
@section('title', 'Relações com Investidores — BSI Capital')

@section('content')
@php
    $activeFilters = array_filter([
        'Categoria' => $category ? ($categories[$category] ?? $category) : null,
        'Busca' => $q !== '' ? '"'.$q.'"' : null,
    ]);
@endphp

<section class="hero position-relative d-flex align-items-center" style="min-height: 38vh;">
    <div class="container position-relative">
        <div class="row g-4 align-items-end">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase">Institucional</span>
                <h1 class="display-4 fw-bold mb-3">Relações com <span style="color: var(--gold);">Investidores</span></h1>
                <p class="lead mb-0" style="max-width: 760px;">
                    Consulte documentos públicos, comunicados e publicações institucionais organizados para apoiar o acompanhamento transparente da BSI Capital por investidores e demais públicos interessados.
                </p>
            </div>
            <div class="col-lg-4">
                <div class="surface-card-dark p-4">
                    <div class="small text-uppercase text-white-50 fw-semibold mb-2">Visão rápida</div>
                    <div class="d-flex justify-content-between align-items-end mb-2">
                        <div>
                            <div class="fs-2 fw-bold text-white">{{ $docs->total() }}</div>
                            <div class="small text-white-50">documento(s) disponível(is)</div>
                        </div>
                        <div class="badge badge-soft px-3 py-2">{{ count($categories) }} categorias</div>
                    </div>
                    <div class="small text-white-50">Busca, filtros e histórico em uma leitura mais clara e consistente com o restante da plataforma.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="surface-card p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-end">
                <div class="col-lg-7">
                    <div class="section-kicker mb-2">Consulta pública</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Documentos públicos organizados para consulta rápida</h2>
                    <p class="section-copy mb-0">
                        Pesquise comunicados e documentos institucionais por palavra-chave ou categoria, com uma navegação mais objetiva e compatível com o contexto de RI.
                    </p>
                </div>
                <div class="col-lg-5">
                    <form method="GET" id="riForm">
                        <div class="input-group">
                            <input
                                type="text"
                                class="form-control border-end-0"
                                name="q"
                                value="{{ $q }}"
                                placeholder="Pesquisar documentos e comunicados..."
                            >
                            <button type="submit" class="input-group-text border-start-0 bg-transparent px-3">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </button>
                        </div>
                        @if($category)
                            <input type="hidden" name="category" value="{{ $category }}">
                        @endif
                    </form>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <a href="{{ route('site.ri', array_filter(['q' => $q])) }}" class="btn {{ !$category ? 'btn-brand' : 'btn-outline-brand' }} btn-sm px-4">Todos</a>
                @foreach($categories as $key => $label)
                    <a href="{{ route('site.ri', array_filter(['category' => $key, 'q' => $q])) }}" class="btn {{ $category === $key ? 'btn-brand' : 'btn-outline-brand' }} btn-sm px-4">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center mb-4">
            <div class="section-copy">
                <strong>{{ $docs->total() }}</strong> documento(s) disponível(is)
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
                <span class="result-chip">{{ $docs->currentPage() }} / {{ $docs->lastPage() }} página(s)</span>
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
                                Baixar
                            </a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="card p-5 text-center border-0 shadow-sm">
                    <div class="fw-semibold text-muted mb-2">Nenhum documento foi localizado.</div>
                    <div class="small text-muted">Revise os filtros aplicados ou tente uma nova busca.</div>
                </div>
            @endforelse
        </div>

        @if($docs->hasPages())
            <div class="mt-5 text-center small text-muted">
                Exibindo <strong>{{ $docs->firstItem() }}</strong> a <strong>{{ $docs->lastItem() }}</strong> de <strong>{{ $docs->total() }}</strong> documentos
            </div>
            {{ $docs->links() }}
        @endif
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="card border-0 overflow-hidden" style="background: linear-gradient(135deg, var(--brand-strong), #0b1f4f);">
            <div class="row g-0 align-items-center">
                <div class="col-lg-8">
                    <div class="p-4 p-lg-5">
                        <div class="section-kicker mb-2">Canal de contato com investidores</div>
                        <h2 class="h3 fw-bold text-white mb-3">Precisa de apoio sobre documentos públicos ou comunicados?</h2>
                        <p class="text-white-50 mb-0">
                            Entre em contato com nossa equipe para esclarecimentos sobre publicações, informações institucionais e temas de relacionamento com investidores.
                        </p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-4 p-lg-5">
                        <a href="{{ route('site.contact') }}" class="btn btn-light btn-lg">Fale com RI</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

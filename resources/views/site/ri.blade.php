@extends('site.layout')
@section('title','Relações com Investidores — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 35vh; overflow: hidden; background: #001233;">
    <div class="container position-relative z-1">
        <div class="row">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Institucional</span>
                <h1 class="display-4 fw-bold mb-3" style="color: #ffffff; letter-spacing: -0.02em;">
                    Relação com <span style="color: var(--gold);">Investidores</span>
                </h1>
                <p class="lead mb-0" style="color: #a5b4fc; max-width: 80%;">
                    Acesse demonstrações financeiras, fatos relevantes, atas de assembleias e demais documentos públicos da BSI Capital.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Search + Filters + Documents -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container">

        <!-- Search Bar -->
        <form method="GET" class="mb-4" id="riForm">
            <div class="position-relative" style="max-width: 700px;">
                <input
                    type="text"
                    class="form-control border-0 shadow-sm ps-4 pe-5 py-3"
                    name="q"
                    value="{{ $q }}"
                    placeholder="Pesquisar arquivo..."
                    style="border-radius: 50px; font-size: 1rem; background: #fff;"
                >
                <button type="submit" class="btn position-absolute top-50 translate-middle-y end-0 me-2 p-0 border-0 bg-transparent" style="color: var(--brand);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </button>
                @if($category)
                    <input type="hidden" name="category" value="{{ $category }}">
                @endif
            </div>
        </form>

        <!-- Category Pills -->
        <div class="d-flex flex-wrap gap-2 mb-5">
            <a href="{{ route('site.ri', array_filter(['q' => $q])) }}"
               class="btn rounded-pill px-4 py-2 fw-medium {{ !$category ? 'btn-brand text-white' : 'btn-outline-secondary' }}"
               style="font-size: 0.9rem; {{ !$category ? '' : 'border-color: #d1d5db; color: #4b5563;' }}">
                Todos
            </a>
            @foreach($categories as $key => $label)
                <a href="{{ route('site.ri', array_filter(['category' => $key, 'q' => $q])) }}"
                   class="btn rounded-pill px-4 py-2 fw-medium {{ $category === $key ? 'btn-brand text-white' : 'btn-outline-secondary' }}"
                   style="font-size: 0.9rem; {{ $category === $key ? '' : 'border-color: #d1d5db; color: #4b5563;' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <!-- Results Count -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="text-muted small">
                <strong>{{ $docs->total() }}</strong> documento(s) encontrado(s)
                @if($category) para <strong>{{ $categories[$category] ?? $category }}</strong> @endif
                @if($q !== '') contendo <strong>"{{ $q }}"</strong> @endif
            </div>
        </div>

        <!-- Document Cards -->
        <div class="d-flex flex-column gap-3">
            @forelse($docs as $d)
                <div class="card p-0 border-0 shadow-sm card-hover overflow-hidden" style="border-radius: 16px; transition: all 0.3s ease;">
                    <div class="row g-0 align-items-center">
                        <div class="col">
                            <div class="p-4">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3" style="width: 48px; height: 48px; background: rgba(0,32,91,0.06); color: var(--brand);">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h3 class="h6 fw-bold mb-1" style="color: var(--brand);">{{ $d->title }}</h3>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="badge rounded-pill px-3 py-1" style="background: rgba(0,32,91,0.08); color: var(--brand); font-size: 0.75rem; font-weight: 600;">
                                                {{ $categories[$d->category] ?? ($d->category ?? '—') }}
                                            </span>
                                            @foreach($d->emissions as $emission)
                                                <span class="badge rounded-pill px-3 py-1" style="background: rgba(212,175,55,0.1); color: var(--gold); border: 1px solid rgba(212,175,55,0.2); font-size: 0.75rem; font-weight: 600;">
                                                    {{ $emission->name }}
                                                </span>
                                            @endforeach
                                            <span class="text-muted" style="font-size: 0.8rem;">
                                                {{ optional($d->{$dateField})->format('d/m/Y') ?? '—' }}
                                            </span>
                                            @if($d->file_size)
                                                <span class="text-muted" style="font-size: 0.8rem;">
                                                    · {{ $d->file_size >= 1048576 ? number_format($d->file_size / 1048576, 1) . ' MB' : number_format($d->file_size / 1024, 0) . ' KB' }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto pe-4">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge rounded-pill px-3 py-2 d-none d-md-inline-block" style="background: rgba(34,197,94,0.08); color: #16a34a; font-weight: 600; font-size: 0.75rem;">Documento Público</span>
                                <a href="{{ Storage::disk($d->resolved_storage_disk)->url($d->file_path) }}" target="_blank" class="btn btn-sm btn-brand rounded-pill px-4 d-inline-flex align-items-center gap-2" download>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                    Baixar
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="card p-5 text-center border-0 shadow-sm" style="border-radius: 20px;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5" class="mx-auto mb-3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                    <div class="text-muted mb-1 fw-medium">Nenhum documento encontrado.</div>
                    <small class="text-muted">Tente ajustar seus filtros de busca.</small>
                </div>
            @endforelse
        </div>

        <!-- Pagination -->
        @if($docs->hasPages())
        <div class="mt-4 d-flex justify-content-center">
            {{ $docs->links() }}
        </div>
        @endif
    </div>
</section>

<!-- CTA -->
<section class="py-5" style="background: linear-gradient(135deg, #001233 0%, #0b1220 100%);">
    <div class="container py-4 text-center">
        <h2 class="h4 fw-bold mb-3" style="color: #ffffff;">Estamos à Sua Disposição</h2>
        <p class="mx-auto mb-4" style="max-width: 500px; color: #a5b4fc;">Dúvidas sobre nossos documentos ou operações? Nosso time de R.I. está pronto para atender você.</p>
        <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg">
            Fale com R.I.
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
    </div>
</section>
@endsection
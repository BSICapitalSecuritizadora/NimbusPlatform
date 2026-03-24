@extends('site.layout')

@section('title', 'Trabalhe Conosco — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 40vh; overflow: hidden; background: #001233;">
    <div class="container position-relative z-1 text-center text-lg-start">
        <div class="row">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Carreiras</span>
                <h1 class="display-4 fw-bold mb-3" style="color: #ffffff; letter-spacing: -0.02em;">
                    Trabalhe <span style="color: var(--gold);">Conosco</span>
                </h1>
                <p class="lead mb-0" style="color: #a5b4fc; max-width: 80%;">
                    Venha fazer parte de uma equipe que valoriza a excelência, o comprometimento e a inovação no mercado de securitização.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Vacancies List -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-lg-5">
        <div class="row mb-5">
            <div class="col-12 text-center text-lg-start">
                <h2 class="h3 fw-bold mb-2" style="color: var(--brand);">Oportunidades em Aberto</h2>
                <div style="width: 50px; height: 3px; background: var(--gold); border-radius: 2px;" class="mx-auto mx-lg-0"></div>
            </div>
        </div>

        <div class="row g-4">
            @forelse($vacancies as $vacancy)
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 border-0 shadow-sm rounded-4 hover-lift" style="transition: all 0.3s ease;">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <span class="badge rounded-pill bg-light text-brand small px-3 py-2 fw-medium border border-opacity-10 border-brand">{{ $vacancy->department ?? 'Geral' }}</span>
                                <span class="text-muted small">{{ $vacancy->type }}</span>
                            </div>
                            <h3 class="h5 fw-bold mb-3" style="color: var(--brand);">{{ $vacancy->title }}</h3>
                            <div class="d-flex align-items-center gap-2 text-muted small mb-4">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                {{ $vacancy->location }}
                            </div>
                            <div class="mt-auto">
                                <a href="{{ route('site.vacancies.show', $vacancy->slug) }}" class="btn btn-outline-brand w-100 rounded-3 fw-bold">Ver Detalhes</a>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12 text-center py-5">
                    <div class="mb-3">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ced4da" stroke-width="1.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    </div>
                    <p class="text-muted fs-5">No momento não temos vagas abertas. Acompanhe nossas redes para futuras oportunidades.</p>
                </div>
            @endforelse
        </div>
    </div>
</section>

<style>
    .hover-lift:hover {
        transform: translateY(-8px);
        box-shadow: 0 1rem 3rem rgba(0,32,91,0.12) !important;
    }
</style>
@endsection

@extends('site.layout')

@section('title', 'Trabalhe Conosco — BSI Capital')

@section('content')
<section class="hero position-relative d-flex align-items-center" style="min-height: 40vh;">
    <div class="container position-relative">
        <div class="row g-4 align-items-end">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase">Carreiras</span>
                <h1 class="display-4 fw-bold mb-3">Integre o time da <span style="color: var(--gold);">BSI Capital</span></h1>
                <p class="lead mb-0" style="max-width: 760px;">
                    Atuamos na estruturação de operações complexas e de alto impacto no mercado de capitais. Buscamos profissionais orientados a resultados técnicos, com domínio regulatório e foco em execução fiduciária.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="row g-4 align-items-end mb-5">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Ambiente de Operações</div>
                <h2 class="h2 fw-bold text-brand mb-3">Exposição técnica e meritocracia no mercado de securitização</h2>
                <p class="section-copy mb-0">
                    Nossa estrutura de talentos prioriza o domínio das complexidades do crédito estruturado. Oferecemos um ambiente de alto nível técnico, focado em execução e crescimento pautado por resultados reais.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="section-divider ms-lg-auto"></div>
            </div>
        </div>

        <div class="row g-4">
            @forelse($vacancies as $vacancy)
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 border-0 shadow-sm card-hover">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-3 gap-3">
                                <span class="badge badge-soft px-3 py-2">{{ $vacancy->department ?? 'Geral' }}</span>
                                <span class="small text-muted fw-semibold">{{ $vacancy->type }}</span>
                            </div>
                            <h3 class="h4 fw-bold text-brand mb-3">{{ $vacancy->title }}</h3>
                            <div class="d-flex align-items-center gap-2 text-muted small mb-4">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                {{ $vacancy->location }}
                            </div>
                            <div class="mt-auto">
                                <a href="{{ route('site.vacancies.show', $vacancy->slug) }}" class="btn btn-outline-brand w-100">Conhecer Oportunidade</a>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="card p-5 text-center border-0 shadow-sm">
                        <div class="mb-3">
                            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#ced4da" stroke-width="1.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                        </div>
                        <div class="fw-semibold text-muted mb-2">Nossas vagas são abertas sob demanda técnica pontual.</div>
                        <div class="small text-muted mb-4">Caso não visualize uma posição aberta para seu perfil, nosso Banco de Talentos está sempre ativo.</div>
                        <div class="d-flex justify-content-center">
                            <a href="{{ route('site.contact') }}" class="btn btn-outline-brand btn-sm px-4">Cadastrar no Banco de Talentos</a>
                        </div>
                    </div>
                </div>
            @endforelse
        </div>
    </div>
</section>
@endsection

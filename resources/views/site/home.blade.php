@extends('site.layout')
@section('title', 'BSI Capital Securitizadora')

@section('content')
<section class="hero position-relative overflow-hidden">
    <video autoplay loop muted playsinline class="position-absolute w-100 h-100 object-fit-cover" style="top: 0; left: 0; z-index: 0; opacity: 0.18; pointer-events: none;">
        <source src="{{ asset('videos/logo-animacao-bsi.mp4') }}" type="video/mp4">
    </video>

    <div class="container py-4 position-relative">
        <div class="row align-items-center g-5">
            <div class="col-xl-7">
                <div class="kicker mb-3">Securitização • Mercado de Capitais • Crédito Estruturado</div>
                <h1 class="display-3 fw-bold mb-4">
                    Atuação completa da estruturação ao encerramento da operação.
                </h1>
                <p class="lead mb-4" style="max-width: 720px;">
                    Soluções completas em CRI, CRA e CR, com segurança, tecnologia e diligência da estruturação à liquidação final.
                </p>

                <div class="d-grid d-sm-flex gap-3 mb-4">
                    <a href="{{ route('proposal.create') }}" class="btn btn-hero-primary btn-lg px-5">Submeter Operação para Análise</a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-hero-secondary btn-lg px-5">Ver Emissões</a>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Histórico</div>
                            <div class="hero-metric-value fw-bold text-white" style="font-size: 1.5rem">Desde 2009</div>
                            <div class="small text-white-50">Sólida trajetória no mercado de securitização e crédito estruturado.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Escala</div>
                            <div class="hero-metric-value fw-bold text-white" style="font-size: 1.5rem">R$ 1,4 Bilhão</div>
                            <div class="small text-white-50">Volume emitido e sob gestão em diversas classes de ativos.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Expertise</div>
                            <div class="hero-metric-value fw-bold text-white" style="font-size: 1.5rem">Crédito Privado</div>
                            <div class="small text-white-50">Especialistas na estruturação de CRI, CRA e CR com soluções sob medida para o mercado de capitais.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="surface-card-dark p-4 p-lg-5">
                    <div class="kicker mb-3">ATUAÇÃO PONTA A PONTA</div>
                    <h2 class="h3 fw-bold mb-3 text-white">Da estruturação à gestão fiduciária</h2>
                    <p class="text-white-50 mb-4" style="text-align: justify;">
                        Conduzimos operações de CRI, CRA e CR com acompanhamento integral, controle documental, diligência técnica e governança em todas as etapas — da originação à liquidação final.
                    </p>

                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">1</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Estruturação e Modelagem</div>
                                <div class="small text-white-50" style="text-align: justify;">Desenho da operação, modelagem financeira, garantias e estrutura jurídico-regulatória.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">2</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Monitoramento e Governança</div>
                                <div class="small text-white-50" style="text-align: justify;">Acompanhamento de covenants, garantias, indicadores e eventos relevantes da operação.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">3</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Transparência e Controle</div>
                                <div class="small text-white-50" style="text-align: justify;">Relatórios, trilha de auditoria e fluxo estruturado de informações para investidores e partes envolvidas.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<section class="py-5">
    <div class="container py-4">
        <div class="row align-items-end g-4 mb-5">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Atuação setorial</div>
                <h2 class="display-6 fw-bold mb-3 text-brand">Estruturas sob medida para cada setor e operação</h2>
                <p class="section-copy mb-0">
                    A BSI Capital estrutura operações sob medida, considerando as características do ativo, a dinâmica do setor e os requisitos de cada emissão.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="section-divider ms-lg-auto"></div>
            </div>
        </div>

        @php
            $industries = [
                ['Imobiliário', 'CRI e crédito estruturado lastreados em ativos e recebíveis imobiliários, com foco em governança, segurança documental e acompanhamento da carteira.', asset('images/imobiliario.jpg'), '/imobiliario/cri-real-estate'],
                ['Agronegócio', 'CRA e operações estruturadas para o agronegócio, com modelagem aderente ao ciclo do setor, às garantias e à geração de caixa da atividade.', asset('images/agronegocio.jpg'), '/agronegocio/cra'],
                ['Infra & Empresas', 'Debêntures, notas comerciais e recebíveis empresariais estruturados para financiar crescimento, investimento e reorganização financeira.', asset('images/infra-empresas.jpg'), '/infra-empresas/cr-futuro'],
            ];
        @endphp

        <div class="row g-4">
            @foreach($industries as [$industryTitle, $desc, $img, $link])
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 overflow-hidden position-relative border-0 shadow-sm card-hover" style="min-height: 420px;">
                        <img src="{{ $img }}" class="position-absolute w-100 h-100 object-fit-cover" alt="{{ $industryTitle }}">
                        <div class="position-absolute w-100 h-100" style="background: linear-gradient(180deg, rgba(2, 9, 24, 0.05) 0%, rgba(0, 18, 51, 0.82) 74%, rgba(0, 18, 51, 0.96) 100%);"></div>
                        <div class="position-relative h-100 d-flex flex-column justify-content-end p-4 text-white">
                            <h3 class="h4 fw-bold mb-3 text-white">{{ $industryTitle }}</h3>
                            <p class="mb-4 text-white-50">{{ $desc }}</p>
                            <div>
                                <a href="{{ $link }}" class="btn btn-light px-4">Explorar Solução</a>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>






<section class="py-5" style="background: color-mix(in srgb, var(--surface) 94%, var(--brand) 6%); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Missão e Valores</div>
                <p class="fw-semibold mb-3" style="font-size: 1.05rem; color: var(--text); line-height: 1.6;">
                    Viabilizar o acesso ao mercado de capitais por meio de estruturas de crédito robustas, atuando como o elo de segurança e governança entre quem produz e quem investe.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="result-chip">Governança corporativa</span>
                    <span class="result-chip">Governança documental</span>
                    <span class="result-chip">Diligência fiduciária</span>
                    <span class="result-chip">Parcerias sólidas</span>
                </div>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="{{ route('site.about') }}" class="btn btn-outline-brand px-4">Conheça a BSI</a>
            </div>
        </div>
    </div>
</section>

<style>
.btn-hero-primary {
    background: var(--brand);
    border: 1px solid var(--gold);
    color: var(--surface);
    box-shadow: 0 4px 20px rgba(160, 110, 40, 0.3);
    transition: all 0.3s ease;
}

.btn-hero-primary:hover,
.btn-hero-primary:focus,
.btn-hero-primary:active {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--brand-strong);
    box-shadow: 0 6px 25px rgba(160, 110, 40, 0.45);
    transform: translateY(-2px);
}

.btn-hero-secondary {
    background: rgba(9, 27, 35, 0.25);
    backdrop-filter: blur(6px);
    border: 1px solid rgba(160, 110, 40, 0.5);
    color: var(--surface);
    transition: all 0.3s ease;
}

.btn-hero-secondary:hover,
.btn-hero-secondary:focus,
.btn-hero-secondary:active {
    background: var(--gold);
    border-color: var(--gold);
    color: var(--brand-strong);
    box-shadow: 0 4px 15px rgba(160, 110, 40, 0.3);
    transform: translateY(-2px);
}

.home-institutional-cta {
    background: linear-gradient(135deg, #091B23 0%, #0B2029 55%, #091B23 100%);
    border-top: 1px solid rgba(160, 110, 40, 0.35);
    border-bottom: 1px solid rgba(160, 110, 40, 0.35);
}

.home-institutional-cta__eyebrow {
    color: #A06E28;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    font-weight: 700;
    font-size: 0.78rem;
}

.home-institutional-cta__title {
    color: #E6E4E4;
    letter-spacing: -0.02em;
}

.home-institutional-cta__text {
    color: rgba(230, 228, 228, 0.72);
}

.home-institutional-cta__button {
    background: #E6E4E4;
    color: #091B23;
    border: 1px solid #E6E4E4;
    transition: all 0.3s ease;
    border-radius: 4px; /* Slight rounding, not fully pill or sharp */
}

.home-institutional-cta__button:hover {
    background: #A06E28;
    border-color: #A06E28;
    color: #091B23;
}

.home-institutional-cta__link {
    color: rgba(230, 228, 228, 0.70);
    transition: all 0.3s ease;
}

.home-institutional-cta__link:hover {
    color: #A06E28;
}
</style>

<section class="home-institutional-cta py-5 mt-4">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="py-4 py-lg-5">
                    <div class="home-institutional-cta__eyebrow mb-2">Relacionamento institucional</div>
                    <h2 class="h2 fw-bold home-institutional-cta__title mb-3">Fale com a BSI Capital</h2>
                    <p class="home-institutional-cta__text mb-0" style="max-width: 640px; font-size: 1.1rem; line-height: 1.6;">
                        Traga sua operação para análise. Nossa equipe avalia a viabilidade, a estrutura e os caminhos para emissão com segurança, governança e eficiência.
                    </p>
                </div>
            </div>
            <div class="col-lg-4 text-lg-end text-start">
                <div class="py-4 py-lg-5 d-flex flex-column gap-3 align-items-start align-items-lg-end">
                    <a href="{{ route('proposal.create') }}" class="btn home-institutional-cta__button text-uppercase fw-bold px-4 py-3">
                        Solicitar Análise de Estruturação
                    </a>
                    <a href="{{ route('site.contact') }}" class="home-institutional-cta__link text-decoration-none" style="padding: 0.25rem 0; font-weight: 500;">
                        Falar com um especialista →
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

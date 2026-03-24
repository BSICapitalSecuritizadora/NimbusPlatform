@extends('site.layout')
@section('title','Sobre — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: #001233;">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.08; background: url('{{ asset('images/compliance.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Somos a <span style="color: var(--gold);">BSI Capital</span> <br>Securitizadora S/A
                </h1>
                <p class="lead mb-4" style="color: #a5b4fc; max-width: 90%;">
                    Desde 2009 conectando empresas ao mercado de capitais. Companhia aberta registrada na CVM, focada na captação e estruturação de operações financeiras com agilidade, segurança e inovação.
                </p>
                <p class="mb-5" style="color: #8892b0; max-width: 85%;">
                    Criada por profissionais de vasta experiência no mercado financeiro, a BSI Capital se posiciona como uma ponte estratégica entre investidores e empresas, oferecendo soluções integradas sob um mesmo teto.
                </p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg">
                    Fale Conosco
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Contadores -->
<section style="background: #0b1220; border-top: 1px solid rgba(212,175,55,0.15);">
    <div class="container py-5">
        <div class="row text-center g-4">
            <div class="col-6 col-md-3">
                <div class="display-5 fw-bold" style="color: var(--gold);">2009</div>
                <div class="text-uppercase mt-2" style="color: #8892b0; font-size: 0.8rem; letter-spacing: 0.1em;">Ano de Fundação</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="display-5 fw-bold" style="color: var(--gold);">+R$ 1 Bi</div>
                <div class="text-uppercase mt-2" style="color: #8892b0; font-size: 0.8rem; letter-spacing: 0.1em;">Em Operações</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="display-5 fw-bold" style="color: var(--gold);">CVM</div>
                <div class="text-uppercase mt-2" style="color: #8892b0; font-size: 0.8rem; letter-spacing: 0.1em;">Registrada</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="display-5 fw-bold" style="color: var(--gold);">ANBIMA</div>
                <div class="text-uppercase mt-2" style="color: #8892b0; font-size: 0.8rem; letter-spacing: 0.1em;">Autorregulação</div>
            </div>
        </div>
    </div>
</section>

<!-- Missão, Visão, Valores -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Missão, Visão & Valores</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Os pilares que nos guiam desde o primeiro dia.</p>
        </div>

        <div class="row g-4">
            <!-- Missão -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Missão</h3>
                    <p class="text-muted mb-0">Estruturar operações com agilidade e eficiência, sendo referência na excelência de produtos e serviços financeiros do mercado de capitais.</p>
                </div>
            </div>

            <!-- Visão -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Visão</h3>
                    <p class="text-muted mb-0">Estar entre os principais players do mercado, buscando sempre a liderança e qualidade na prestação de serviços para nossos clientes.</p>
                </div>
            </div>

            <!-- Valores -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 60px; height: 60px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Valores</h3>
                    <p class="text-muted mb-0">Eficácia, agilidade e responsabilidade. Valorizamos a simplicidade, honestidade e transparência em todas as relações.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Cultura: Ética, Inovação, Foco no Cliente -->
<section class="py-5" style="background: #0b1220;">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold mb-3" style="color: #ffffff;">Nossa Cultura</h2>
            <p class="mx-auto" style="max-width: 600px; color: #a5b4fc;">Os princípios que moldam cada operação e cada relacionamento.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.04); border-radius: 16px;">
                    <div class="mb-3">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #fff; font-size: 1.1rem;">Ética</h4>
                    <p class="mb-0" style="color: #8892b0; font-size: 0.95rem;">Trabalhamos com caráter e de forma ética para com nossos clientes, pensando no bem comum e na satisfação de ambas as partes.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.04); border-radius: 16px;">
                    <div class="mb-3">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #fff; font-size: 1.1rem;">Inovação</h4>
                    <p class="mb-0" style="color: #8892b0; font-size: 0.95rem;">Com o objetivo de obter resultados crescentes, sempre almejamos soluções inovadoras com saber e conhecimento para nossos clientes.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.04); border-radius: 16px;">
                    <div class="mb-3">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #fff; font-size: 1.1rem;">Foco no Cliente</h4>
                    <p class="mb-0" style="color: #8892b0; font-size: 0.95rem;">Excelência no atendimento com soluções eficazes e inovadoras, mantendo sempre uma grande parceria e proximidade.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Nossos Pilares -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Nossos Pilares</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Uma abordagem integrada que sustenta cada operação com inteligência e rigor.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-center" style="transition: .3s;">
                    <div class="mb-3 mx-auto d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="font-size: 1rem; color: #0b1220;">Planejamento Estratégico</h4>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Definição de objetivos e metas com visão de longo prazo.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-center" style="transition: .3s;">
                    <div class="mb-3 mx-auto d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="font-size: 1rem; color: #0b1220;">Estudo de Viabilidade</h4>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Análise criteriosa de cada operação antes da estruturação.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-center" style="transition: .3s;">
                    <div class="mb-3 mx-auto d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="font-size: 1rem; color: #0b1220;">Variáveis Econômicas</h4>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Monitoramento de cenários macro e microeconômicos.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-center" style="transition: .3s;">
                    <div class="mb-3 mx-auto d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 56px; height: 56px; background: rgba(0,32,91,0.08); color: var(--brand);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="font-size: 1rem; color: #0b1220;">Risco & Oportunidade</h4>
                    <p class="text-muted mb-0" style="font-size: 0.9rem;">Avaliação criteriosa de riscos e identificação de oportunidades.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Credenciais / Selos -->
<section class="py-5" style="background: linear-gradient(135deg, #001233 0%, #0b1220 100%);">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold mb-3" style="color: #ffffff;">Credenciais & Reconhecimentos</h2>
            <p class="mx-auto" style="max-width: 550px; color: #a5b4fc;">Nosso compromisso com a excelência é reconhecido pelos principais órgãos reguladores do mercado.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <div class="card h-100 p-5 border-0 text-center" style="background: rgba(255,255,255,0.04); border-radius: 20px; border: 1px solid rgba(212,175,55,0.15) !important;">
                    <div class="mb-4">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: var(--gold); font-size: 1.2rem;">CVM — Comissão de Valores Mobiliários</h4>
                    <p class="mb-0" style="color: #8892b0;">Companhia aberta devidamente registrada e em conformidade com todas as instruções normativas da CVM.</p>
                </div>
            </div>

            <div class="col-md-5">
                <div class="card h-100 p-5 border-0 text-center" style="background: rgba(255,255,255,0.04); border-radius: 20px; border: 1px solid rgba(212,175,55,0.15) !important;">
                    <div class="mb-4">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: var(--gold); font-size: 1.2rem;">ANBIMA — Autorregulação</h4>
                    <p class="mb-0" style="color: #8892b0;">Adesão ao código de autorregulação ANBIMA para securitizadoras, atestando nosso compromisso com as melhores práticas de mercado.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Final -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5 text-center">
        <h2 class="h3 fw-bold text-dark mb-3">Vamos Construir Juntos?</h2>
        <p class="text-muted mx-auto mb-5" style="max-width: 550px;">Entre em contato com nossa equipe e descubra como a BSI Capital pode estruturar a melhor solução financeira para o seu negócio.</p>
        <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg">
            Fale com Nosso Time
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
    </div>
</section>
@endsection
@extends('site.layout')

@section('title', 'Estruturação de Operações | BSI Capital')

@section('uses_flux', '1')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/originacao2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Estruturação</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Estruturação de <br><span style="color: var(--gold);">Operações</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Analisamos necessidades financeiras e desenhamos a estrutura mais eficiente para sua operação. Nossa abordagem integra análise de risco, fluxo de caixa e governança em todas as etapas.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Conversar sobre uma estrutura
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/originacao.jpg') }}" class="img-fluid" alt="Estruturação de Operações" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle text-primary" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Modelagem customizada</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Análise de Viabilidade</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Visão Geral -->
<section class="py-5" style="background-color: #ffffff;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Visão Geral do Serviço</span>
                <h2 class="h3 fw-bold text-dark mb-4">Inteligência técnica para viabilizar negócios complexos</h2>
                <p class="text-muted mb-4 lead">
                    A estruturação de operações é o alicerce de qualquer captação sustentável. O serviço prestado pela BSI Capital vai além do desenho financeiro: atuamos na conexão entre a necessidade de capital do emissor e as exigências regulatórias, comerciais e operacionais.
                </p>
                <p class="text-muted mb-4">
                    Assessoramos desde a análise inicial das garantias e do perfil de risco até a definição das partes envolvidas, alinhando a arquitetura da operação à dinâmica de fluxo de caixa do projeto.
                </p>
            </div>
            <div class="col-lg-6">
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Modelagem</h3>
                            <p class="small text-muted mb-0">Desenho da estrutura de garantias e fluxo financeiro adequado à realidade do ativo.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Governança</h3>
                            <p class="small text-muted mb-0">Definição clara das obrigações e processos de monitoramento para mitigar riscos.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Público-Alvo Section -->
<section class="py-5 bg-light border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Público-Alvo</span>
            <h2 class="h3 fw-bold text-dark mb-3">Para quem a estruturação é indicada</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nosso escopo de atuação abrange perfis variados, sempre focando em operações que apresentem maturidade e qualidade creditícia suficientes para acesso ao mercado.</p>
        </div>
        <div class="row g-4 justify-content-center">
            @foreach([
                ['Empresas e Originadores', 'Buscando alternativas eficientes de crédito e que possuam lastro qualificado.'],
                ['Setor Imobiliário', 'Loteadoras, incorporadoras e detentores de recebíveis imobiliários.'],
                ['Agronegócio', 'Cooperativas, produtores e indústrias com fluxos do ciclo produtivo.'],
                ['Infraestrutura', 'Projetos e empresas de médio a grande porte necessitando expansão.']
            ] as $target)
            <div class="col-md-6 col-lg-3">
                <div class="d-flex flex-column p-4 rounded-4 h-100" style="background: #ffffff; border: 1px solid var(--border);">
                    <div class="bg-gold p-2 rounded-circle mb-3 align-self-start" style="width: 12px; height: 12px;"></div>
                    <h4 class="h6 fw-bold mb-2">{{ $target[0] }}</h4>
                    <p class="text-muted small mb-0">{{ $target[1] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

<!-- Etapas / Fluxo -->
<section class="py-5">
    <div class="container py-5">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Metodologia</span>
            <h2 class="h3 fw-bold text-dark">Como a BSI Capital atua na Estruturação</h2>
        </div>

        <div class="row g-0 position-relative">
            <div class="d-none d-lg-block position-absolute start-0 w-100 border-top border-2" style="top: 29px; border-color: rgba(212,175,55, 0.3) !important; z-index: 1;"></div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">1</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Diagnóstico</h3>
                <p class="small text-muted mb-0">Avaliação da viabilidade técnica, lastro e perfil de crédito.</p>
            </div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">2</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Análise</h3>
                <p class="small text-muted mb-0">Modelagem do fluxo de recebimentos, stress test e pacote de garantias.</p>
            </div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">3</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Estruturação</h3>
                <p class="small text-muted mb-0">Elaboração técnica da operação e formatação jurídica e financeira.</p>
            </div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">4</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Coordenação</h3>
                <p class="small text-muted mb-0">Gestão das partes envolvidas até a emissão e acompanhamento.</p>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios / Diferenciais -->
<section class="py-5 bg-white border-top border-bottom">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diferenciais BSI</span>
                <h2 class="h3 fw-bold text-dark mb-4">Rigor técnico e independência</h2>
                <p class="text-muted mb-4">
                    Nossa modelagem não segue fórmulas pré-fabricadas. Adequamos a arquitetura da operação à realidade de geração de caixa do projeto, mitigando riscos estruturais e agregando governança.
                </p>
            </div>
            <div class="col-lg-7">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="d-flex gap-3">
                            <div class="text-gold flex-shrink-0">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold">Independência</h4>
                                <p class="small text-muted">Estruturamos as operações pensando na sustentabilidade do negócio e proteção aos investidores.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex gap-3">
                            <div class="text-gold flex-shrink-0">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold">Proximidade</h4>
                                <p class="small text-muted">Acompanhamento próximo, garantindo que o emissor compreenda cada etapa do processo e exigências.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Final -->
<section class="py-5 position-relative" style="background: var(--brand-strong); overflow: hidden;">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('{{ asset('images/originacao.jpg') }}') center/cover;"></div>
    <div class="container py-5 position-relative z-1 text-center">
        <h2 class="h3 fw-bold text-white mb-3">Deseja estruturar sua operação?</h2>
        <p class="text-light mb-4 mx-auto" style="max-width: 600px;">
            Submeta sua proposta para que nossa equipe técnica analise a estrutura e o perfil do ativo.
        </p>
        <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
            Falar com a equipe técnica
        </a>
    </div>
</section>

@endsection

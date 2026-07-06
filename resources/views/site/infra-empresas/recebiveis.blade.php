@extends('site.layout')

@section('title', 'Recebíveis Empresariais | BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/recebiveis_empresas2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Infra & Empresas</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Antecipação de <br><span style="color: var(--gold);">Recebíveis</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Transforme duplicatas e contratos em liquidez imediata com uma esteira digital robusta. Reforce seu capital de giro sem consumir limites bancários tradicionais.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Receber Estudo
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver Emissões
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/recebiveis_empresas.jpg') }}" class="img-fluid" alt="Recebíveis Corporativos" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Esteira Digital</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Liquidez Fora do Balanço</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Liquidez estruturada para expansão empresarial</h2>
            <p class="text-muted mx-auto" style="max-width: 640px;">Estruturamos soluções para vendas B2B, contratos recorrentes e direitos creditórios, utilizando o instrumento ideal para o perfil do seu lastro.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Captação Off-Balance</h3>
                    <p class="text-muted mb-0">Melhore seus índices financeiros com a securitização fora do balanço. Libere limites bancários para investimentos em CAPEX enquanto nós financiamos seu giro.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Estrutura de garantias</h3>
                    <p class="text-muted mb-0">Cessão fiduciária combinada com contas vinculadas e controles de performance. Reforçamos a segurança do título sem engessar a operação.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Esteiras Revolving</h3>
                    <p class="text-muted mb-0">Programas recorrentes que permitem novas antecipações automáticas sobre a mesma base operacional, garantindo previsibilidade total ao caixa.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Perfil de Atuação e Tíquetes -->
<section class="py-5" style="background-color: #f8f9fa;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diretrizes de Crédito</span>
                <h2 class="h3 fw-bold text-dark mb-4">Perfil das Carteiras Estruturadas</h2>
                <p class="text-muted mb-4">Focamos em empresas do Middle Market e Corporate que possuem carteiras de recebíveis pulverizadas ou contratos de longo prazo.</p>

                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">R$ 10MM a R$ 200MM</div>
                            <div class="small text-muted">Tíquete médio para estruturação de veículos exclusivos.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Receita > R$ 50MM/ano</div>
                            <div class="small text-muted">Perfil ideal para otimização de custo de capital.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Pulverização</div>
                            <div class="small text-muted">Monitoramento de múltiplos sacados via tecnologia.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">SaaS e Indústria</div>
                            <div class="small text-muted">Expertise em fluxos recorrentes e duplicatas mercantis.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg overflow-hidden" style="border-radius: 24px;">
                    <div class="card-body p-5" style="background: var(--brand);">
                        <h4 class="text-white fw-bold mb-4">Por que securitizar conosco?</h4>
                        <div class="d-flex flex-column gap-3">
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Redução de custo comparado ao cheque especial/giro bancário.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Agilidade na aprovação de novos sacados via score digital.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Gestão fiduciária que protege o emissor e o investidor.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Isenção de IOF nas operações de securitização.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Monitoramento da carteira pós-cessão -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Gestão ativa da carteira</h2>
                <p class="text-muted mb-4 lead">
                    Nossa entrega não para no fechamento. Gerimos a inadimplência e a concentração de devedores em acompanhamento contínuo, garantindo que o lastro permaneça saudável durante todo o programa.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento contínuo da saúde dos devedores e critérios de substituição.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Gatilhos automáticos de reforço de lastro e reservas de liquidez.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Transparência total via dashboards periódicos de performance e lastro.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/recebiveis_empresas.jpg') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Casos de Uso e Setores Elegíveis -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Liquidez Sob Medida</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Veja como diferentes setores utilizam a securitização para otimizar seus ciclos de caixa.</p>
        </div>

        <div class="row g-4">
            <!-- Indústria -->
            <div class="col-lg-4">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 20V9l4-2 4 2 4-2 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z"/><path d="M7 22v-5"/><path d="M17 22v-5"/><path d="M2 14h20"/></svg>
                    </div>
                    <h4 class="h5 fw-bold mb-3">Indústria B2B</h4>
                    <p class="small text-muted mb-0">Antecipação de duplicatas mercantil de vendas para grandes redes, garantindo capital para compra de matéria-prima sem juros bancários elevados.</p>
                </div>
            </div>
            <!-- Tecnologia/SaaS -->
            <div class="col-lg-4">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    </div>
                    <h4 class="h5 fw-bold mb-3">Tecnologia (SaaS)</h4>
                    <p class="small text-muted mb-0">Securitização de fluxos de contratos de assinatura (MRR), antecipando o valor anual para investimento em P&D e expansão de mercado.</p>
                </div>
            </div>
            <!-- Concessões -->
            <div class="col-lg-4">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <h4 class="h5 fw-bold mb-3">Concessões e PPPs</h4>
                    <p class="small text-muted mb-0">Monetização de receitas tarifárias futuras em projetos de infraestrutura, saneamento ou iluminação pública, antecipando o retorno do CAPEX.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="py-5 bg-white">
    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-4">
                <div class="pe-lg-4">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Agilidade e Inteligência</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Diferenciais Competitivos</h2>
                    <p class="text-muted mb-4">Esclarecemos os principais pontos sobre estruturação corporativa frente ao crédito bancário tradicional.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Consultar Estruturação</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqRecebiveis">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Qual a diferença para o desconto bancário comum?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqRecebiveis">
                            <div class="accordion-body px-0 text-muted">
                                A securitização é uma operação off-balance. Ela não consome seu limite de crédito no banco, não possui incidência de IOF e melhora seus índices de liquidez imediata no balanço.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Quando escolher entre um FIDC e um CR corporativo?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqRecebiveis">
                            <div class="accordion-body px-0 text-muted">
                                O FIDC é ideal para operações recorrentes e fluxos constantes (esteira digital). O CR é indicado para captações pontuais de maior volume ou lastreadas em contratos de longo prazo.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Qual a agilidade na liberação de novos recursos?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqRecebiveis">
                            <div class="accordion-body px-0 text-muted">
                                Após a estruturação inicial da esteira digital, a liberação de novos lotes ocorre em D+0 ou D+1 via API, permitindo uma gestão dinâmica do fluxo de caixa.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. A securitização exige garantias reais extras?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqRecebiveis">
                            <div class="accordion-body px-0 text-muted">
                                A garantia principal é o próprio recebível. Dependendo do rating, estruturamos reforços como sobrecolateralização, sem a necessidade de hipotecas de imóveis da empresa.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Outros segmentos Infra & Empresas -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Outros Segmentos de Infra & Empresas</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Estruturamos soluções de crédito para empresas e projetos de infraestrutura em diferentes estágios.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.infra.cr') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">CR</h3>
                    <p class="text-muted mb-3">O novo instrumento de securitização para infraestrutura e corporações, conectando novos setores ao mercado de capitais.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.infra.estruturacao') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="9" x2="20" y2="9"></line><line x1="4" y1="15" x2="20" y2="15"></line><line x1="10" y1="3" x2="8" y2="21"></line><line x1="16" y1="3" x2="14" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Estruturação sob Medida</h3>
                    <p class="text-muted mb-3">Modelagem personalizada para operações complexas que exigem arquitetura de crédito customizada.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>
        </div>
    </div>
</section>

@push('head')
<style>
    @keyframes float {
        0% { transform: translateY(0px); }
        50% { transform: translateY(-10px); }
        100% { transform: translateY(0px); }
    }

    .custom-accordion .accordion-button:not(.collapsed) {
        box-shadow: none;
        color: var(--brand);
    }

    .custom-accordion .accordion-button:focus {
        box-shadow: none;
    }

    .custom-accordion .accordion-button::after {
        background-size: 1rem;
    }

    .card-hover:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important;
    }
</style>
@endpush
@endsection

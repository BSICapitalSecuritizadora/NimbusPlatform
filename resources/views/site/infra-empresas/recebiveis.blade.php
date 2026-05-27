@extends('site.layout')

@section('title', 'Recebíveis — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/recebiveis_empresas.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Infra & Empresas</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Antecipação de <br><span style="color: var(--gold);">Recebíveis</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Converta carteiras de duplicatas e contratos em liquidez imediata através de uma esteira digital ágil, reforçando seu capital de giro sem consumir limites bancários.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Receber Estudo de Antecipação
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/recebiveis_empresas.png') }}" class="img-fluid" alt="Recebíveis Corporativos" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Cessão fiduciária</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Liquidez sem banco</div>
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
            <p class="text-muted mx-auto" style="max-width: 640px;">Elegíveis para vendas B2B, contratos de serviço recorrente, mensalidades, receitas de concessão e direitos creditórios — estruturamos via CR ou instrumento adequado ao perfil do emissor e do lastro.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Captação eficiente</h3>
                    <p class="text-muted mb-0">Securitização fora do balanço, sem consumir limite bancário e sem covenants restritivos — melhorando indicadores financeiros e preservando flexibilidade para outras operações da companhia.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Estrutura de garantias</h3>
                    <p class="text-muted mb-0">Cessão fiduciária, contas vinculadas, controles de performance e mecanismos de reforço podem ser combinados conforme a qualidade e a maturidade da carteira.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Programas recorrentes</h3>
                    <p class="text-muted mb-0">Estruturas revolving permitem novas captações sobre a mesma base operacional, com maior previsibilidade e eficiência na utilização da carteira.</p>
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
                <h2 class="h3 fw-bold text-dark mb-4">Da Originação ao Monitoramento Contínuo da Carteira</h2>
                <p class="text-muted mb-4 lead">
                    Após a cessão, a carteira precisa ser acompanhada — inadimplência, concentração de devedores e substituição de recebíveis afetam diretamente a integridade do lastro. Gerimos esse processo de forma ativa, estruturado via CR ou instrumento compatível com o setor e o perfil do emissor.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento contínuo da inadimplência, concentração de devedores e critérios de substituição de recebíveis cedidos.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Ativação de gatilhos de reforço — overcollateral e reserva de liquidez — quando indicadores de performance da carteira se deterioram.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Relatórios periódicos para investidores com posição da carteira, evolução do lastro e conformidade com os covenants da escritura.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/recebiveis_empresas.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Casos de Uso e Setores Elegíveis -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Casos de Uso: Liquidez Sob Medida</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Veja como diferentes setores utilizam a securitização para otimizar seus ciclos de caixa.</p>
        </div>

        <div class="row g-4">
            <!-- Indústria -->
            <div class="col-lg-4">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 20V9l4-2 4 2 4-2 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z"/><path d="M7 22v-5"/><path d="M17 22v-5"/><path d="M2 14h20"/></svg>
                    </div>
                    <h4 class="h5 fw-bold mb-3">Indústria e Comércio B2B</h4>
                    <p class="small text-muted mb-0">Antecipação de duplicatas mercantil de vendas a prazo para grandes redes de varejo ou distribuidores, garantindo capital para compra de insumos e matéria-prima.</p>
                </div>
            </div>
            <!-- Tecnologia/SaaS -->
            <div class="col-lg-4">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    </div>
                    <h4 class="h5 fw-bold mb-3">Serviços e Tecnologia (SaaS)</h4>
                    <p class="small text-muted mb-0">Securitização de fluxos recorrentes de contratos de assinatura ou manutenção de software, antecipando o valor anual dos contratos para investimento em P&D.</p>
                </div>
            </div>
            <!-- Concessões -->
            <div class="col-lg-4">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <h4 class="h5 fw-bold mb-3">Concessões e Parcerias Públicas</h4>
                    <p class="small text-muted mb-0">Monetização de receitas tarifárias futuras ou contraprestações públicas em projetos de infraestrutura, saneamento ou iluminação pública.</p>
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
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Consultoria Financeira</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Agilidade e Inteligência de Crédito</h2>
                    <p class="text-muted mb-4">Esclarecemos os principais diferenciais da securitização corporativa frente ao crédito bancário.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Falar com Estruturação</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqRecebiveis">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Qual a diferença entre securitização corporativa e desconto bancário?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqRecebiveis">
                            <div class="accordion-body px-0 text-muted">
                                Diferente do desconto bancário, a securitização é uma operação de mercado de capitais que ocorre "fora do balanço" (off-balance). Ela não consome seu limite de crédito no banco, não possui incidência de IOF sobre a operação e melhora seus índices de liquidez imediata.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Quando escolher entre um FIDC e um CR para minha empresa?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqRecebiveis">
                            <div class="accordion-body px-0 text-muted">
                                O FIDC (Fundo de Investimento em Direitos Creditórios) é ideal para operações recorrentes e fluxos de caixa constantes (esteira digital). Já o CR (Certificado de Recebíveis) é mais indicado para captações pontuais de maior volume ou projetos com lastro em contratos de longo prazo (infraestrutura/CAPEX).
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Qual a agilidade na liberação de recursos após a primeira estruturação?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqRecebiveis">
                            <div class="accordion-body px-0 text-muted">
                                Após a montagem da estrutura mestre (esteira digital), a liberação de novos lotes de recebíveis ocorre de forma quase instantânea via API ou portal de custódia. O processo de estruturação inicial leva, em média, de 30 a 60 dias, dependendo da complexidade do lastro.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. A securitização exige garantias reais da empresa?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqRecebiveis">
                            <div class="accordion-body px-0 text-muted">
                                Geralmente, a garantia principal é o próprio recebível (cessão fiduciária). Em alguns casos, dependendo do rating da empresa e da qualidade dos devedores, podem ser estruturados reforços de crédito como sobrecolateralização (excesso de lastro) ou aval dos sócios, sem a necessidade de hipotecas de imóveis.
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
            <p class="text-muted mx-auto" style="max-width: 560px;">Estruturamos soluções de crédito para empresas e projetos de infraestrutura em diferentes estágios e necessidades de capital.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.infra.cr') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">CR</h3>
                    <p class="text-muted mb-3">O novo instrumento de securitização para infraestrutura e grandes corporações, conectando setores como saúde, educação e telecomunicações ao mercado de capitais.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.infra.estruturacao') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="9" x2="20" y2="9"></line><line x1="4" y1="15" x2="20" y2="15"></line><line x1="10" y1="3" x2="8" y2="21"></line><line x1="16" y1="3" x2="14" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Estruturação sob Medida</h3>
                    <p class="text-muted mb-3">Modelagem financeira e jurídica personalizada para operações complexas que exigem arquitetura de crédito além dos instrumentos padronizados.</p>
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

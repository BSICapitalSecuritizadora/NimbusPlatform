@extends('site.layout')

@section('title', 'Estruturação sob Medida — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/estruturacao_projetos.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Infra & Empresas</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Estruturação <br><span style="color: var(--gold);">sob Medida</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Engenharia financeira para ativos atípicos e teses de investimento complexas. Convertemos contratos complexos em estruturas de capital eficientes com segurança fiduciária.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Avaliar Viabilidade de Ativos Atípicos
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
                        <img src="{{ asset('images/estruturacao_projetos.png') }}" class="img-fluid" alt="Estruturação sob Medida" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Modelagem sob medida</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Da tese ao fechamento</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Estruturação alinhada à sua operação</h2>
            <p class="text-muted mx-auto" style="max-width: 640px;">Cada empresa é única. Criamos a combinação ideal de prazo, garantias e fluxo de amortização — coordenando a modelagem financeira, a arquitetura jurídica e a conformidade regulatória em uma única estrutura coesa.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Assessoria de Ponta a Ponta</h3>
                    <p class="text-muted mb-0">Coordenamos o ecossistema completo da operação: definição da tese financeira, coordenação jurídica, análise de crédito, arquitetura de garantias e fechamento — em uma única estrutura coesa.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Instrumentos Estratégicos</h3>
                    <p class="text-muted mb-0">Estruturamos operações via Debêntures, Notas Comerciais e veículos híbridos, definindo o instrumento mais adequado com base na capacidade de pagamento, no perfil dos ativos e no apetite de risco da companhia.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Execução Regulatória</h3>
                    <p class="text-muted mb-0">Analisamos a viabilidade regulatória junto à CVM e à ANBIMA, estruturamos as condições da oferta, coordenamos o registro e garantimos conformidade técnica desde a modelagem até o encerramento.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Suporte técnico pós-closing -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Suporte Técnico Ativo Além do Fechamento</h2>
                <p class="text-muted mb-4 lead">
                    Uma estrutura bem construída não termina no closing — ela precisa ser mantida. Monitoramos o cumprimento dos covenants, acompanhamos a evolução regulatória e garantimos que a operação reflita, ao longo de toda a sua vigência, os parâmetros técnicos originalmente negociados.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento do cumprimento de condições precedentes, covenants financeiros e eventos de default ao longo da vigência da escritura.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Atualização da estrutura diante de novos normativos da CVM e da ANBIMA, com adaptação imediata dos instrumentos em operação.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Relatórios periódicos para investidores com posição das garantias, evolução do rating e conformidade com os parâmetros originalmente estruturados.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/estruturacao_projetos.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Matriz de Ativos e Soluções Tailor-Made -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Matriz de Ativos Atípicos</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Estruturamos operações onde o crédito tradicional não alcança, utilizando modelagem avançada para colaterais complexos.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-3 col-md-6">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0 card-hover">
                    <div class="text-brand fw-bold mb-3 fs-5">BTS & Sale-Leaseback</div>
                    <p class="small text-muted mb-0">Securitização de contratos de aluguel atípicos de longo prazo para galpões logísticos e plantas industriais.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0 card-hover">
                    <div class="text-brand fw-bold mb-3 fs-5">Ativos Judiciais</div>
                    <p class="small text-muted mb-0">Antecipação estratégica de precatórios e direitos creditórios homologados com modelagem probabilística.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0 card-hover">
                    <div class="text-brand fw-bold mb-3 fs-5">Recebíveis de Saúde</div>
                    <p class="small text-muted mb-0">Monetização de fluxos de planos de saúde, SUS e convênios para hospitais e redes de medicina diagnóstica.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0 card-hover">
                    <div class="text-brand fw-bold mb-3 fs-5">Telecom & Infra</div>
                    <p class="small text-muted mb-0">Estruturação de recebíveis de compartilhamento de torres, fibra óptica e infraestrutura de rede.</p>
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
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Engenharia Fiduciária</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Arquitetura de Crédito Complexa</h2>
                    <p class="text-muted mb-4">Esclarecemos os aspectos técnicos da modelagem e execução de operações sob medida.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Consultar Tese de Estruturação</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqEstruturacao">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Qual o diferencial da modelagem financeira da BSI para ativos atípicos?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqEstruturacao">
                            <div class="accordion-body px-0 text-muted">
                                Utilizamos modelagem estocástica e simulações de estresse (Stress Test) para precificar ativos com fluxos de caixa incertos, como ativos judiciais ou contratos de performance. Isso garante que a estrutura tenha colchões de liquidez adequados para suportar cenários adversos sem comprometer o pagamento aos investidores.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Qual o prazo médio (Time-to-Market) para uma estruturação sob medida?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqEstruturacao">
                            <div class="accordion-body px-0 text-muted">
                                Devido à complexidade jurídica e regulatória, o ciclo completo — da definição da tese ao fechamento — leva em média de 60 a 90 dias. Esse prazo inclui a auditoria do lastro, o registro nos órgãos competentes (CVM/ANBIMA) e a coordenação da distribuição junto aos investidores.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Como é tratada a complexidade jurídica e fiscal em veículos híbridos?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqEstruturacao">
                            <div class="accordion-body px-0 text-muted">
                                Atuamos em conjunto com consultores tributários para garantir a eficiência fiscal da operação, explorando regimes como o Patrimônio de Afetação e a segregação fiduciária. Nos veículos híbridos (ex: CR + Debênture), a BSI coordena a harmonização dos contratos para que as garantias sejam compartilhadas ou segregadas conforme o apetite de cada tranche.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. A BSI estrutura operações para empresas em Special Situations?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqEstruturacao">
                            <div class="accordion-body px-0 text-muted">
                                Sim. Temos expertise em modelar créditos para empresas em processos de recuperação ou reestruturação de dívida, utilizando ativos segregados para gerar liquidez nova (*fresh money*) através de instrumentos securitizados que isolam o risco do ativo do risco da empresa mãe.
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
                <a href="{{ route('site.infra.recebiveis') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Recebíveis Empresariais</h3>
                    <p class="text-muted mb-3">Securitização de recebíveis comerciais e contratos de longo prazo para empresas que buscam capital fora do sistema bancário tradicional.</p>
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

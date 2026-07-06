@extends('site.layout')

@section('title', 'Funding Estruturado para Infraestrutura Agro | BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/projetos_agro2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Mercado de Capitais</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Funding Estruturado para <br><span style="color: var(--gold);">Infraestrutura Agro</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%; line-height: 1.6;">
                    Estruturamos operações para produtores, agroindústrias e empresas do agronegócio que buscam viabilizar projetos de armazenagem, irrigação, energia e modernização produtiva via mercado de capitais, com governança, garantias reais e acompanhamento técnico da execução.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Apresentar projeto agro
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}?type=CRA" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Consultar operações agro
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/projetos_agro.jpg') }}" class="img-fluid" alt="Projetos do Agronegócio" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">CAPEX Rural</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Ativos de Longo Prazo</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- Gargalos e Desafios de CAPEX -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-4">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Desafios de Investimento</span>
            <h2 class="h3 fw-bold text-dark mb-3">Soluções de Funding para Ativos de Longa Maturação</h2>
            <p class="text-muted mx-auto" style="max-width: 650px;">A implementação de ativos estruturantes exige carência customizada e amortizações blindadas contra descasamentos de obras.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Alto Investimento Inicial</h4>
                    <p class="text-muted small mb-0">Estruturamos securitizações agropecuárias para absorver o elevado CAPEX inicial exigido para armazenagem e modernização sem drenar a liquidez operacional imediata.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Limitação de Linhas Oficiais</h4>
                    <p class="text-muted small mb-0">Atuamos de forma complementar ou substitutiva às linhas bancárias e oficiais, acessando funding fiduciário desbancarizado com agilidade nas tranches de liberação.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Modelagem de Garantias</h4>
                    <p class="text-muted small mb-0">Reunimos expertise técnica para organizar colaterais complexos, penhor de benfeitorias e alienações rurais com segurança jurídica institucional para os stakeholders.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Para quem a estrutura é indicada -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Elegibilidade de Projetos</span>
            <h2 class="h3 fw-bold text-dark mb-3">Alinhamento Comercial: Para quem a estrutura é indicada</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossas soluções atendem a empresas e grupos integrados com metas consolidadas de expansão patrimonial.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 border h-100 shadow-sm">
                    <h4 class="h6 fw-bold text-brand text-uppercase mb-2">Produtores & Agroindústrias</h4>
                    <p class="text-muted small mb-0">Grandes produtores rurais e agroindústrias em processo de verticalização ou expansão de capacidade de escoamento.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 border h-100 shadow-sm">
                    <h4 class="h6 fw-bold text-brand text-uppercase mb-2">Operadores e Armazenagem</h4>
                    <p class="text-muted small mb-0">Operadores logísticos rurais e empresas agrícolas com projetos estruturados para silos, graneleiros e terminais.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 border h-100 shadow-sm">
                    <h4 class="h6 fw-bold text-brand text-uppercase mb-2">Projetos de Irrigação & Transição Solar</h4>
                    <p class="text-muted small mb-0">Grupos corporativos focados em implementar pivôs centrais de irrigação ou matrizes de energia renovável no campo.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios Section -->
<section class="py-5 bg-white">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diferenciais de Escala</span>
            <h2 class="h3 fw-bold text-dark mb-3">Capital para Expansão e Verticalização</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Desenvolvemos o funding via mercado de capitais necessário para viabilizar a autonomia física e operacional dos ativos agroindustriais.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover bg-light" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-sm" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="10" width="18" height="11" rx="2" ry="2"></rect><path d="M3 10l9-7 9 7"></path><line x1="12" y1="10" x2="12" y2="21"></line><line x1="7" y1="10" x2="7" y2="21"></line><line x1="17" y1="10" x2="17" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Infraestrutura Produtiva</h3>
                    <p class="text-muted mb-0">Reduza a dependência de terceiros e controle sua comercialização. Estruturação para silos e terminais com fluxos aderentes à safra.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover bg-light" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-sm" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon><line x1="8" y1="2" x2="8" y2="18"></line><line x1="16" y1="6" x2="16" y2="22"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Expansão de Áreas</h3>
                    <p class="text-muted mb-0">Otimização de capital de longo prazo para reorganização do balanço e consolidação patrimonial, permitindo saltos estruturados de escala.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover bg-light" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-sm" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Energia e Sustentabilidade</h3>
                    <p class="text-muted mb-0">Soluções fiduciárias para usinas fotovoltaicas e irrigação, alinhando rentabilidade aos padrões ESG por meio de potenciais CRAs Verdes.</p>
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
                <h2 class="h3 fw-bold text-dark mb-4">Parâmetros para Projetos Estruturados</h2>
                <p class="text-muted mb-4">Atuamos como securitizadora e estruturadora via mercado de capitais de forma complementar e qualificada, potencializando as garantias reais rurais constituídas.</p>

                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Análise de Viabilidade</div>
                            <div class="small text-muted">Modelagem de volumetria compatível com o perfil do projeto e as condições de mercado.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Carência Estruturada</div>
                            <div class="small text-muted">Estruturas sujeitas à análise técnica de prazos de maturação do ativo.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Garantias Reais</div>
                            <div class="small text-muted">Alienações fiduciárias baseadas em terra, recebíveis e benfeitorias.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Ativos de CAPEX</div>
                            <div class="small text-muted">Apoio a projetos industriais rurais Greenfield e Brownfield.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg overflow-hidden" style="border-radius: 24px;">
                    <div class="card-body p-5" style="background: var(--brand);">
                        <h4 class="text-white fw-bold mb-4">Escopo de Ativos de Infraestrutura</h4>
                        <div class="d-flex flex-column gap-3 text-white small">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Unidades de Armazenagem (Silos e Complexos Graneleiros).</span>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Usinas de Biogás, Biomassa e Geração Fotovoltaica.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Sistemas de Irrigação (Pivôs Centrais e Automação de Fluxo).</span>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Plataformas Logísticas e Terminais de Transbordo Rural.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Monitoramento de projetos de longo prazo -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Governança Técnica</span>
                <h2 class="h3 fw-bold text-dark mb-4">Acompanhamento Técnico na Ponta</h2>
                <p class="text-muted mb-4 lead">
                    A higidez jurídica e fiduciária de um projeto de longa maturação depende do rigor no controle da execução física. Monitoramos marcos reais para garantir segurança a emissores e investidores.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Validação de marcos físicos por engenharia independente vinculada à liberação de tranches.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento contínuo das garantias reais rurais, colaterais e benfeitorias executadas.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Reportes periódicos comparando a evolução física da obra com o plano de viabilidade original.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/projetos_agro.jpg') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
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
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Consultoria Técnica</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Aspectos Estratégicos do Funding para Infraestrutura Agro</h2>
                    <p class="text-muted mb-4">Esclarecemos os principais mecanismos fiduciários de securitização e controle de obras para projetos de longo prazo no agronegócio.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Consultar especialistas</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqProjetos">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Como são estruturados os prazos de carência para projetos de CAPEX?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqProjetos">
                            <div class="accordion-body px-0 text-muted">
                                Para projetos estruturados (armazenagem, irrigação, usinas), avaliamos a inclusão de carências compatíveis com o ciclo de implantação física do ativo, buscando que o serviço da dívida alinhe-se ao início da fase operacional.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Como funciona a modelagem de garantias reais corporativas?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqProjetos">
                            <div class="accordion-body px-0 text-muted">
                                Estruturamos o lastro fiduciário com base em alienações de imóveis rurais, penhor de benfeitorias, recebíveis futuros ou travas de commodities, conferindo sobrecolateralização à operação de securitização.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Qual o mecanismo de controle de tranches físicas de obra?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqProjetos">
                            <div class="accordion-body px-0 text-muted">
                                Os desembolsos são segmentados em tranches fiduciárias condicionadas à evolução do canteiro. Uma empresa de engenharia independente audita os marcos antes da liberação dos fundos estruturados.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. A securitização pode englobar projetos sustentáveis de biogás ou solar?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqProjetos">
                            <div class="accordion-body px-0 text-muted">
                                Sim. Ativos de transição energética possuem forte previsibilidade de recebíveis e fluxo de caixa de longo prazo, qualificando a operação para estruturas dedicadas de CRA Verde no mercado de capitais.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Outros segmentos do agronegócio -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Outros Segmentos do Agronegócio</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Atuamos em toda a cadeia produtiva com estruturas adaptadas ao perfil de cada negócio.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.agronegocio.cra') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">CRA e Agronegócio</h3>
                    <p class="text-muted mb-3">Soluções de CRA com lastros auditáveis em CPR e CDCA, com foco em liquidez de curto e médio prazo.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Acessar soluções de CRA →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.agronegocio.cooperativas') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Cooperativas</h3>
                    <p class="text-muted mb-3">Estruturas adaptadas ao modelo associativo, com cessão fiduciária de recebíveis e preservação do Ato Cooperativo.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Acessar soluções para cooperativas →</span>
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

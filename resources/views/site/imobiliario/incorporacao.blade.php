@extends('site.layout')

@section('title', 'Funding Estruturado para Incorporação Imobiliária | BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/incorporacao.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Mercado de Capitais</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Funding Estruturado para <br><span style="color: var(--gold);">Incorporação Imobiliária</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%; line-height: 1.6;">
                    Estruturamos operações para incorporadoras que buscam transformar projetos, recebíveis e ativos imobiliários em funding via mercado de capitais, com governança, controle de lastro e monitoramento ativo.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Submeter operação para avaliação
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}?type=CRI" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Portfólio de CRI
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/incorporacao.png') }}" class="img-fluid" alt="Incorporação Imobiliária" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><line x1="9" y1="22" x2="9" y2="12"/><line x1="15" y1="22" x2="15" y2="12"/></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Patrimônio de Afetação</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Segregação garantida</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@include('site.partials.imobiliario-stats')

<!-- Desafios e Dores Section -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-4">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Desafios de Liquidez</span>
            <h2 class="h3 fw-bold text-dark mb-3">Soluções para os Gargalos da Incorporação</h2>
            <p class="text-muted mx-auto" style="max-width: 650px;">O desenvolvimento imobiliário exige estruturas financeiras inteligentes para superar os limites do crédito bancário tradicional.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Descascamento de Fluxo de Caixa</h4>
                    <p class="text-muted small mb-0">Mitigamos a assimetria temporal entre a intensidade dos desembolsos do canteiro de obras e a velocidade de entrada dos recebíveis imobiliários de longo prazo.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Dependência de Linhas Tradicionais</h4>
                    <p class="text-muted small mb-0">Substituímos a rigidez burocrática, os prazos curtos e a alta exigência de garantias corporativas dos bancos por soluções flexíveis de securitização imobiliária.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Capital para Expansão e Terrenos</h4>
                    <p class="text-muted small mb-0">Estruturamos funding para a aquisição de terrenos estruturantes e despesas pré-lançamento, permitindo que a incorporadora preserve seu equity para novos projetos.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diferenciais Estruturais</span>
            <h2 class="h3 fw-bold text-dark mb-3">Ciclo de Crédito Inteligente</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Do lançamento à entrega das chaves, modelamos o financiamento ideal para garantir a execução do cronograma físico-financeiro.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Alavancagem de Equity</h3>
                    <p class="text-muted mb-0">Otimizamos o aporte de capital próprio e impulsionamos o ROE do empreendimento. Nossa modelagem sob medida permite escalar lançamentos sem drenar o caixa da holding.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">CRI para Incorporação</h3>
                    <p class="text-muted mb-0">Estruturação de CRI de produção com tranches liberadas sob demanda técnica, sincronizando o fluxo de caixa corporativo à evolução real no canteiro de obras.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Governança do Lastro</h3>
                    <p class="text-muted mb-0">Monitoramento ativo e controle documental rigoroso do Patrimônio de Afetação. Oferecemos segurança jurídica robusta para emissores, investidores e stakeholders.</p>
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
                <h2 class="h3 fw-bold text-dark mb-4">Alinhamento Estratégico: Para quem a estrutura é indicada</h2>
                <p class="text-muted mb-4">Focamos em parcerias estruturadas com desenvolvedores imobiliários e incorporadoras que possuem track record comprovado e projetos de forte viabilidade.</p>
                
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">R$ 15MM a R$ 150MM</div>
                            <div class="small text-muted">Tíquete médio por operação de securitização imobiliária.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Rating Mínimo B+</div>
                            <div class="small text-muted">Análise de crédito corporativo baseada em governança e solidez.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">VGV > R$ 40MM</div>
                            <div class="small text-muted">Perfil ideal para incorporações residenciais ou comerciais.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">LTV até 70%</div>
                            <div class="small text-muted">Loan-to-Value ajustado de forma sustentável à fase do empreendimento.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg overflow-hidden" style="border-radius: 24px;">
                    <div class="card-body p-5" style="background: var(--brand);">
                        <h4 class="text-white fw-bold mb-4">Público-Alvo Qualificado</h4>
                        <div class="d-flex flex-column gap-3">
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Incorporadoras e desenvolvedores imobiliários de médio/grande porte.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Empresas com projetos residenciais, comerciais ou multipropriedade.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Originadores com carteiras de recebíveis imobiliários performados ou futuros.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Estruturas corporativas focadas em governança e blindagem patrimonial.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Estrutura da Solução / Esteira -->
<section class="py-5 bg-white border-top">
    <div class="container py-4">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Modelagem Técnica</span>
            <h2 class="h3 fw-bold text-dark mb-3">As Fases do Funding Imobiliário</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Desenvolvemos uma jornada transparente e segura do diagnóstico inicial ao monitoramento do lastro imobiliário.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-3">
                <div class="p-3 border-top border-4 border-gold h-100">
                    <div class="fw-bold text-brand h6 text-uppercase mb-2">01. Análise & Viabilidade</div>
                    <p class="text-muted small mb-0">Avaliação do projeto imobiliário, orçamento, track record do incorporador e estudo técnico dos recebíveis futuros.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 border-top border-4 border-gold h-100">
                    <div class="fw-bold text-brand h6 text-uppercase mb-2">02. Estruturação</div>
                    <p class="text-muted small mb-0">Organização documental, definição das garantias, modelagem financeira do LTV e instituição do Patrimônio de Afetação.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 border-top border-4 border-gold h-100">
                    <div class="fw-bold text-brand h6 text-uppercase mb-2">03. Emissão & Captação</div>
                    <p class="text-muted small mb-0">Emissão do instrumento fiduciário aplicável (CRI) e colocação focada junto a investidores do mercado de capitais.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="p-3 border-top border-4 border-gold h-100">
                    <div class="fw-bold text-brand h6 text-uppercase mb-2">04. Gestão Ativa</div>
                    <p class="text-muted small mb-0">Acompanhamento contínuo dos fluxos, validação de lastro e conciliação financeira de parcelas e contratos.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Controle operacional pós-fechamento -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Monitoramento Ativo</span>
                <h2 class="h3 fw-bold text-dark mb-4">Gestão Operacional de Alta Performance</h2>
                <p class="text-muted mb-4 lead">
                    Nossa entrega estende-se por todo o ciclo do empreendimento. Atuamos com inteligência tecnológica para garantir que o fluxo financeiro atenda às necessidades de emissores e investidores.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento do Patrimônio de Afetação e conformidade estrita com o RET.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Liberação estruturada de recursos vinculada a marcos reais de avanço físico.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Transparência total com reporte rigoroso de lastro imobiliário e posição de contas.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/incorporacao.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Mecânica de Liberação de Recursos (S-Curve Visual) -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-6">
                <h2 class="h3 fw-bold text-dark mb-4">Fluxo de Desembolso Sincronizado (Curva S)</h2>
                <p class="text-muted mb-5">Em operações de produção, a liberação de recursos segue a Curva S de evolução física da obra, assegurando que o capital esteja disponível com aderência ao cronograma físico-financeiro do canteiro.</p>
                
                <div class="position-relative p-4 bg-white rounded-4 border shadow-sm mb-4">
                    <svg viewBox="0 0 400 200" class="w-100 h-auto mb-3" style="max-height: 250px;">
                        <!-- Grid Lines -->
                        <line x1="0" y1="180" x2="400" y2="180" stroke="#eee" stroke-width="1"/>
                        <line x1="0" y1="140" x2="400" y2="140" stroke="#eee" stroke-width="1"/>
                        <line x1="0" y1="100" x2="400" y2="100" stroke="#eee" stroke-width="1"/>
                        <line x1="0" y1="60" x2="400" y2="60" stroke="#eee" stroke-width="1"/>
                        <line x1="0" y1="20" x2="400" y2="20" stroke="#eee" stroke-width="1"/>
                        
                        <!-- S-Curve Path (Physical Evolution) -->
                        <path d="M 0 180 Q 100 180 200 100 T 400 20" fill="none" stroke="var(--gold)" stroke-width="4" stroke-linecap="round"/>
                        
                        <!-- Disbursement Bars (Tranches) -->
                        <rect x="20" y="160" width="20" height="20" fill="var(--brand)" opacity="0.4"/>
                        <rect x="80" y="150" width="20" height="30" fill="var(--brand)" opacity="0.6"/>
                        <rect x="140" y="120" width="20" height="60" fill="var(--brand)" opacity="0.8"/>
                        <rect x="200" y="80" width="20" height="100" fill="var(--brand)"/>
                        <rect x="260" y="60" width="20" height="120" fill="var(--brand)"/>
                        <rect x="320" y="40" width="20" height="140" fill="var(--brand)" opacity="0.8"/>
                        
                        <text x="5" y="15" font-size="10" fill="#999">100% Obra</text>
                        <text x="350" y="195" font-size="10" fill="#999">Tempo</text>
                    </svg>
                    
                    <div class="d-flex justify-content-between small text-muted px-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="d-inline-block" style="width: 12px; height: 3px; background: var(--gold);"></span> Curva de Evolução Física
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="d-inline-block" style="width: 12px; height: 12px; background: var(--brand);"></span> Liberação de Tranches
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="d-flex flex-column h-100 justify-content-center">
                    <div class="d-flex gap-4 mb-4">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-weight: bold;">1</div>
                        <div>
                            <h5 class="fw-bold mb-2">Medição Físico-Financeira</h5>
                            <p class="text-muted small">A validação técnica do avanço da obra ocorre via empresa de engenharia independente antes de cada desembolso programado.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-4 mb-4">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-weight: bold;">2</div>
                        <div>
                            <h5 class="fw-bold mb-2">Preservação de Lastro Imobiliário</h5>
                            <p class="text-muted small">Asseguramos que o montante liberado mantenha correlação protetiva com o VGV remanescente e com as garantias reais constituídas.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-4">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; font-weight: bold;">3</div>
                        <div>
                            <h5 class="fw-bold mb-2">Segurança para Stakeholders</h5>
                            <p class="text-muted small">O monitoramento ativo mitiga riscos operacionais, impede desvios e corrobora para a conclusão previsível do ativo imobiliário.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Leadership Quote -->
<section class="py-5" style="background: var(--bg);">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card border-0 shadow-sm p-4 p-md-5 position-relative overflow-hidden" style="border-radius: 30px; background: white;">
                    <div class="position-absolute top-0 end-0 p-4" style="opacity: 0.05; pointer-events: none;">
                        <svg width="120" height="120" viewBox="0 0 24 24" fill="var(--brand)"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
                    </div>
                    <div class="row align-items-center g-4 position-relative z-1">
                        <div class="col-md-12">
                            <blockquote class="blockquote mb-0 text-center">
                                <p class="fs-4 fw-medium text-dark mb-4 italic" style="line-height: 1.6; font-style: italic;">
                                    "O segredo de um financiamento para incorporação via mercado de capitais não está apenas nas garantias, mas na execução. Entendemos que o sucesso da operação depende do sincronismo entre o cronograma de obra e o fluxo de caixa da SPE. Nosso papel é garantir estabilidade e governança corporativa durante todo o ciclo do projeto."
                                </p>
                                <footer class="blockquote-footer mt-2">
                                    <span class="fw-bold text-brand fs-5 d-block">Diretoria de Real Estate</span>
                                    <cite title="BSI Capital" class="small text-muted text-uppercase fw-bold" style="letter-spacing: 0.1em;">BSI Capital Securitizadora</cite>
                                </footer>
                            </blockquote>
                        </div>
                    </div>
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
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Diretrizes Estratégicas</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Aspectos Estratégicos do Funding Imobiliário</h2>
                    <p class="text-muted mb-4">Esclarecemos os principais mecanismos de mitigação de risco, controle de lastro e estruturação para o setor de incorporação.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Consultar especialista em Real Estate</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqIncorporacao">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Qual a importância da Governança de SPE na operação?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqIncorporacao">
                            <div class="accordion-body px-0 text-muted">
                                A Sociedade de Propósito Específico (SPE) garante que os recursos e riscos do projeto fiquem isolados de outros negócios da incorporadora. A BSI atua no monitoramento dessa governança, assegurando que o caixa da SPE seja utilizado exclusivamente para o empreendimento lastreado, protegendo o fluxo de pagamento do CRI.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Como mitigamos os riscos de engenharia e atrasos?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqIncorporacao">
                            <div class="accordion-body px-0 text-muted">
                                Além do acompanhamento por empresa de engenharia independente, estruturamos fundos de obras e exigimos seguros de performance. Em caso de desvio crítico, os gatilhos de controle (covenants) permitem a intervenção na governança financeira para priorizar a conclusão física do projeto.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. O CRI pode ser usado para lançamentos na planta (Unperformed)?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqIncorporacao">
                            <div class="accordion-body px-0 text-muted">
                                Sim. No modelo de "Produção", o CRI antecipa o VGV que ainda será gerado. É a estrutura ideal para acelerar o canteiro de obras logo após o lançamento, sem depender exclusivamente da velocidade de vendas inicial para financiar a infraestrutura principal.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. Qual a vantagem do Regime Especial de Tributação (RET) para o CRI?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqIncorporacao">
                            <div class="accordion-body px-0 text-muted">
                                O RET permite uma carga tributária reduzida e unificada (geralmente 4% sobre a receita bruta). Para o CRI, isso significa maior margem de caixa dentro da SPE para honrar o serviço da dívida e garantir que os impostos sejam pagos de forma segregada e transparente.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Outros segmentos imobiliários -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Outros Segmentos do Imobiliário</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Atuamos em diferentes frentes do mercado imobiliário com estruturas adaptadas à natureza de cada ativo.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.imobiliario.cri') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1m4 0h1m-5 4h1m4 0h1M9 16h1m4 0h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">CRI e Real Estate</h3>
                    <p class="text-muted mb-3">Securitização de recebíveis imobiliários com segurança jurídica, monitoramento rigoroso do lastro e governança ativa da carteira.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Solicitar análise de estruturação →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.imobiliario.loteamentos') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Loteamentos</h3>
                    <p class="text-muted mb-3">Securitização de recebíveis de loteamentos urbanos e fechados com lastro em contratos de promessa de compra e venda.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Apresentar projeto de loteamento →</span>
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

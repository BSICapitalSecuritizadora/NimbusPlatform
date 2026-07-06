@extends('site.layout')

@section('title', 'CRA e Securitização para o Agronegócio | BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/cra_agronegocio2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Mercado de Capitais</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    CRA e Securitização <br><span style="color: var(--gold);">para o Agronegócio</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%; line-height: 1.6;">
                    Estruturamos CRAs para agroindústrias, cooperativas, produtores e originadores que buscam funding via mercado de capitais, com governança do lastro, aderência ao ciclo de safra e monitoramento ativo da operação.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Solicitar análise de CRA
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}?type=CRA" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Consultar emissões agro
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/cra_agronegocio.jpg') }}" class="img-fluid" alt="CRA - Certificados de Recebíveis do Agronegócio" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Safra</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Liquidez no tempo certo</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- Público-Alvo Section -->
<section class="py-5" style="background-color: #ffffff;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Público-Alvo</span>
                <h2 class="h3 fw-bold text-dark mb-4">Para quem é o Certificado de Recebíveis do Agronegócio?</h2>
                <p class="text-muted mb-4 lead">
                    O CRA é o instrumento ideal para empresas e produtores do setor agro que buscam funding estruturado no mercado de capitais, com fluxos aderentes às sazonalidades produtivas rurais.
                </p>
                <div class="d-flex flex-column gap-3">
                    @foreach([
                        'Agroindústrias, usinas sucroenergéticas e cooperativas de produção.',
                        'Grandes produtores rurais com necessidade de infraestrutura ou expansão.',
                        'Distribuidores de insumos agrícolas e exportadores de commodities.',
                        'Empresas com recebíveis originados na cadeia do agronegócio.',
                        'Grupos econômicos buscando diversificação de funding corporativo.'
                    ] as $item)
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-gold p-1 rounded-circle" style="width: 8px; height: 8px; flex-shrink: 0;"></div>
                            <span class="text-dark fw-medium">{{ $item }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="col-lg-7">
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Sazonalidade e Caixa</h3>
                            <p class="small text-muted mb-0">Fluxos de pagamento alinhados à efetiva comercialização da safra, evitando pressões de caixa fora do período de liquidez.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Dependência Bancária</h3>
                            <p class="small text-muted mb-0">Acesse liquidez complementar via mercado de capitais para financiar custeio e expansão sem comprometer limites bancários tradicionais.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Mitigação de Riscos</h3>
                            <p class="small text-muted mb-0">Proteção contra intempéries climáticas e volatilidade de preços com modelagem inteligente e monitoramento rigoroso de garantias reais.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Eficiência Regulatória</h3>
                            <p class="small text-muted mb-0">Atuação em rigorosa conformidade com o CMN 5.118, assegurando a elegibilidade corporativa para captação de recursos no setor agro.</p>
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
            <h2 class="h3 fw-bold text-dark mb-3">Inteligência técnica aplicada ao Agro</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Estruturamos e gerimos operações de crédito respeitando as janelas de safra e os marcos reais de produção no campo.</p>
        </div>

        <div class="row g-4">
            <!-- Diferencial 1 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Gestão de CPRs e Colaterais</h3>
                    <p class="text-muted mb-0">Gestão rigorosa de Cédulas de Produto Rural (CPR), penhor de safra e recebíveis, assegurando o controle fiduciário do lastro agrícola.</p>
                </div>
            </div>

            <!-- Diferencial 2 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Diligência e Sensoriamento</h3>
                    <p class="text-muted mb-0">Acompanhamento contínuo dos covenants financeiros integrado ao monitoramento geoespacial do desenvolvimento vegetativo das áreas produtivas.</p>
                </div>
            </div>

            <!-- Diferencial 3 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Diversificação de Funding</h3>
                    <p class="text-muted mb-0">Acesso qualificado a investidores no mercado de capitais, viabilizando prazos estendidos, muitas vezes indisponíveis nas linhas bancárias tradicionais.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Process Flow Section -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Como funciona o fluxo de Securitização do CRA</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Uma jornada estruturada para transformar os recebíveis e o potencial produtivo rural em liquidez qualificada e governança transparente.</p>
        </div>

        <div class="row g-4 flow-container position-relative">
            <!-- Step 1 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Originação</h4>
                    <p class="small text-muted mb-0">Emissor agrícola, cooperativa ou agroindústria demanda funding estruturado ou antecipação de recebíveis do setor rural.</p>
                </div>
            </div>
            <!-- Step 2 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Estruturação</h4>
                    <p class="small text-muted mb-0">Securitizadora modela o fluxo acoplado à safra, organiza a emissão de CPRs, efetiva o registro na CVM e formaliza garantias.</p>
                </div>
            </div>
            <!-- Step 3 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Distribuição</h4>
                    <p class="small text-muted mb-0">Emissão do título no mercado e captação de recursos junto aos investidores institucionais ou pessoas físicas (com isenção de IR).</p>
                </div>
            </div>
            <!-- Step 4 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Monitoramento</h4>
                    <p class="small text-muted mb-0">Acompanhamento contínuo do ciclo agrícola, fiscalização das lavouras (sensoriamento) e repasse de proventos.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Culturas Atendidas -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Escopo Produtivo</span>
            <h2 class="h3 fw-bold text-dark mb-2">Presença em toda a cadeia de valor</h2>
            <p class="text-muted mx-auto" style="max-width: 650px;">Atuamos em diferentes segmentos do agronegócio, considerando a natureza do ativo, a dinâmica de safra, as garantias e os covenants aplicáveis a cada operação.</p>
        </div>
        <div class="row g-4 justify-content-center">
            @php
                $culturas = [
                    [
                        'icon' => '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/></svg>',
                        'title' => 'Grãos',
                        'desc' => 'Soja, milho e trigo com foco em custeio, CPR e recebíveis agrícolas.'
                    ],
                    [
                        'icon' => '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>',
                        'title' => 'Sucroenergético',
                        'desc' => 'Recebíveis, fornecedores e fluxos vinculados à cadeia sucroenergética.'
                    ],
                    [
                        'icon' => '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"><path d="M4 4v16"/><path d="M20 4v16"/><path d="M4 10h16"/><path d="M4 16h16"/></svg>',
                        'title' => 'Pecuária',
                        'desc' => 'Operações com recebíveis, garantias e fluxos associados à cadeia pecuária.'
                    ],
                    [
                        'icon' => '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/></svg>',
                        'title' => 'Café',
                        'desc' => 'Estruturas para cooperativas, produtores e exportadores da cadeia cafeeira.'
                    ],
                    [
                        'icon' => '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"><circle cx="12" cy="13" r="8"/><path d="M12 5a4 4 0 0 1 4-4 4 4 0 0 1-4 4z"/></svg>',
                        'title' => 'Citricultura',
                        'desc' => 'Financiamento estruturado para pomares, indústria de sucos e recebíveis do setor.'
                    ],
                    [
                        'icon' => '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"><path d="m8 14 4-4 4 4"/><path d="m4 10 8-8 8 8"/><path d="M12 22V14"/></svg>',
                        'title' => 'Silvicultura',
                        'desc' => 'Crédito de longo prazo para florestas plantadas, manejo e ativos florestais.'
                    ],
                ];
            @endphp
            @foreach($culturas as $cultura)
            <div class="col-12 col-md-6 col-lg-4 text-center">
                <div class="p-4 p-lg-5 agro-chain-card h-100 d-flex flex-column align-items-center justify-content-center">
                    <div class="agro-chain-icon">
                        {!! $cultura['icon'] !!}
                    </div>
                    <h4 class="h6 fw-bold mb-3" style="color: #091B23;">{{ $cultura['title'] }}</h4>
                    <p class="small mb-0" style="color: rgba(9, 27, 35, 0.70); line-height: 1.5;">{{ $cultura['desc'] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

<!-- Additional Context or Stats (Gestão Digital) -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <div class="mb-4">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Gestão do Lastro</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Gestão Ativa do Ciclo de Safra</h2>
                </div>
                <p class="text-muted mb-4 lead">
                    No CRA, o lastro evolui em simbiose com o campo. Monitoramos cada etapa técnica, garantindo que os eventos de colheita e liquidação financeira sejam consolidados com transparência.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-5">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Auditoria de conformidade documental e liquidação de CPRs em tempo real.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Sensoriamento remoto e acompanhamento geoespacial para mitigar riscos de quebra.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Reportes periódicos no <strong>Portal do Investidor</strong> com a evolução do lastro e covenants.</span>
                    </li>
                </ul>
                <div class="d-flex gap-3">
                    <a href="{{ route('investor.login') }}" class="btn btn-brand px-4 py-2">Acessar Portal</a>
                </div>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div class="position-relative">
                    <div style="background: url('{{ asset('images/cra_agronegocio.jpg') }}') center/cover; height: 450px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); filter: grayscale(20%); mix-blend-mode: multiply;"></div>
                    <div class="position-absolute bg-white p-4 rounded-4 shadow-lg border" style="bottom: 30px; right: -20px; max-width: 280px;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="bg-success bg-opacity-10 p-2 rounded-circle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline><polyline points="16 7 22 7 22 13"></polyline></svg>
                            </div>
                            <div class="fw-bold small">Adequação Normativa</div>
                        </div>
                        <div class="text-muted smaller">Rigorosa observância às normas do CMN 5.118 e rastreabilidade fiduciária.</div>
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
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Dúvidas Estratégicas</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Inteligência Financeira no Campo</h2>
                    <p class="text-muted mb-4">Apresentamos respostas estratégicas acerca dos mecanismos corporativos de securitização e controle de riscos de CRA.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Consultar especialista em estruturação</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqCRA">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Quais instrumentos compõem o lastro de um CRA?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqCRA">
                            <div class="accordion-body px-0 text-muted">
                                O lastro fiduciário pode ser integrado por Cédulas de Produto Rural (CPR), Certificados de Direitos Creditórios do Agronegócio (CDCA), contratos comerciais de compra e venda e duplicatas comerciais originadas de transações legítimas do agronegócio.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Como funciona o CRA Verde?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqCRA">
                            <div class="accordion-body px-0 text-muted">
                                O CRA Verde associa a captação financeira a projetos agrícolas auditados com impacto socioambiental rastreável. Esse mecanismo qualifica o papel junto a fundos de investimento com mandatos rígidos de critérios ESG.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Qual a atuação preventiva em cenários de quebra de safra?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqCRA">
                            <div class="accordion-body px-0 text-muted">
                                Realizamos monitoramento preventivo geoespacial. Havendo desvios críticos, os agentes avaliam tempestivamente o redimensionamento de colaterais ou o acionamento de garantias líquidas para mitigar o impacto no fluxo fiduciário.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. Quem se enquadra para emissões de CRA pós CMN 5.118?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqCRA">
                            <div class="accordion-body px-0 text-muted">
                                As diretrizes vigentes alinharam os critérios de elegibilidade de lastro a emissores cuja atividade e destinação dos fundos guardem relação essencial e direta com a cadeia produtiva rural do agronegócio.
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
                <a href="{{ route('site.agronegocio.cooperativas') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Cooperativas</h3>
                    <p class="text-muted mb-3">Estruturas adaptadas ao modelo cooperativista e às particularidades do associativismo rural.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Acessar soluções para cooperativas →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.agronegocio.projetos') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Projetos Agro</h3>
                    <p class="text-muted mb-3">Financiamento para expansão rural, armazenagem e logística com lastro em recebíveis.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Conhecer estruturas de projetos →</span>
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

    /* Agro Chain Cards Premium Style */
    .agro-chain-card {
        background: #ffffff;
        border: 1px solid rgba(9, 27, 35, 0.10);
        border-radius: 18px;
        box-shadow: 0 12px 28px rgba(9, 27, 35, 0.05);
        transition: border-color 180ms ease, transform 180ms ease, box-shadow 180ms ease, background 180ms ease;
    }

    .agro-chain-card:hover {
        border-color: rgba(160, 110, 40, 0.40);
        transform: translateY(-3px);
        box-shadow: 0 16px 32px rgba(9, 27, 35, 0.08);
    }

    .agro-chain-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 52px;
        height: 52px;
        margin-bottom: 20px;
        border-radius: 999px;
        color: #A06E28;
        background: rgba(160, 110, 40, 0.08);
        border: 1px solid rgba(160, 110, 40, 0.18);
        transition: background 180ms ease, color 180ms ease;
    }

    .agro-chain-card:hover .agro-chain-icon {
        background: #A06E28;
        color: #ffffff;
    }

    .agro-chain-icon svg {
        width: 24px;
        height: 24px;
        stroke: currentColor;
        fill: none;
    }
</style>
@endpush
@endsection

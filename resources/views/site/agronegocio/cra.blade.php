@extends('site.layout')

@section('title', 'CRA e Securitização para o Agronegócio | BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/cra_agronegocio.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

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
                        <img src="{{ asset('images/cra_agronegocio.png') }}" class="img-fluid" alt="CRA - Certificados de Recebíveis do Agronegócio" style="width: 100%; height: 500px; object-fit: cover;">
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

<!-- BSI no Campo (Social Proof) -->
<section class="py-5" style="background: linear-gradient(135deg, var(--brand-strong), var(--brand));">
    <div class="container py-4">
        <div class="row g-4 text-center">
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">R$ 1.5Bi+</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Emissões Agro</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">120+</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">CPRs Monitoradas</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">10</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Principais Culturas</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">24h</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Monitoramento Geográfico</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Gargalos e Desafios Resolvidos -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-4">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Desafios de Capital</span>
            <h2 class="h3 fw-bold text-dark mb-3">Eficiência Financeira nas Cadeias do Agro</h2>
            <p class="text-muted mx-auto" style="max-width: 650px;">A dinâmica do agronegócio exige soluções que integrem o mercado de capitais diretamente às sazonalidades produtivas rurais.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Sazonalidade e Caixa</h4>
                    <p class="text-muted small mb-0">Solucionamos o descasamento temporal crônico entre o ciclo de insumos/plantio e a efetiva comercialização da safra, estruturando amortizações customizadas.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Dependência Bancária</h4>
                    <p class="text-muted small mb-0">Acessamos liquidez complementar e desbancarizada via mercado de capitais para financiar custeio, expansão e infraestrutura logística sem travar limites operacionais tradicionais.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Mitigação de Riscos</h4>
                    <p class="text-muted small mb-0">Mitigamos os impactos causados por riscos climáticos e volatilidade de preços de commodities com modelagem inteligente e monitoramento dinâmico de garantias reais.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Para quem o CRA é indicado -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Elegibilidade Corporativa</span>
            <h2 class="h3 fw-bold text-dark mb-3">Alinhamento Comercial: Para quem a estrutura é indicada</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossas estruturas de CRA atendem aos principais players corporativos integrados ao agronegócio nacional.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 border h-100 shadow-sm">
                    <h4 class="h6 fw-bold text-brand text-uppercase mb-2">Agroindústrias & Cooperativas</h4>
                    <p class="text-muted small mb-0">Indústrias de processamento, usinas sucroenergéticas e cooperativas de produção que demandam funding de escala.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 border h-100 shadow-sm">
                    <h4 class="h6 fw-bold text-brand text-uppercase mb-2">Grandes Produtores Rurais</h4>
                    <p class="text-muted small mb-0">Produtores rurais com escala comercial e track record produtivo que visam alavancar investimentos em infraestrutura.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 border h-100 shadow-sm">
                    <h4 class="h6 fw-bold text-brand text-uppercase mb-2">Cadeia de Insumos & Exportadores</h4>
                    <p class="text-muted small mb-0">Distribuidores de insumos agrícolas e exportadores de commodities que detêm carteiras estruturadas de CPRs.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Culturas Atendidas -->
<section class="py-5 bg-white">
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

<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diferenciais Estruturais</span>
            <h2 class="h3 fw-bold text-dark mb-3">Inteligência Técnica Aplicada ao Agro</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Estruturamos operações de crédito respeitando as janelas de safra e os marcos reais de produção do campo.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Ciclo de Safra</h3>
                    <p class="text-muted mb-0">Fluxos de pagamento alinhados à comercialização da cultura, evitando pressões de caixa fora do período de liquidez.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Diversificação de Funding</h3>
                    <p class="text-muted mb-0">Acesso qualificado a canais de desbancarização financeira. O mercado de capitais viabiliza prazos longos para projetos de expansão rural.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Controle de CPR</h3>
                    <p class="text-muted mb-0">Gestão rigorosa de Cédulas de Produto Rural e colaterais agrícolas. asseguramos que o lastro fiduciário esteja protegido e monitorado.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Diretrizes Gerais de Crédito -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Parâmetros Operacionais</span>
                <h2 class="h3 fw-bold text-dark mb-4">Diretrizes para Estruturação</h2>
                <p class="text-muted mb-4">Atendemos agroindústrias, cooperativas e emissores corporativos com estruturas desenhadas sob medida para cada tese de crédito.</p>
                
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Volumetria Estruturada</div>
                            <div class="small text-muted">Operações com volumetria adaptada à tese de investimento da empresa.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Prazos customizados</div>
                            <div class="small text-muted">Estruturas de longo prazo para investimento, armazenagem e logística.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Colaterais Agrícolas</div>
                            <div class="small text-muted">CPR-F/F, penhor de safra, estoques e garantias reais.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Análise Multicritério</div>
                            <div class="small text-muted">Avaliação focada na governança e solidez comercial corporativa.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg overflow-hidden" style="border-radius: 24px;">
                    <div class="card-body p-5" style="background: var(--brand);">
                        <h4 class="text-white fw-bold mb-4">Lastro e Mitigação Fiduciária</h4>
                        <p class="text-white opacity-75 small mb-4">Atuamos no mapeamento de fluxos fiduciários integrados para conferir máxima robustez aos emissores.</p>
                        <div class="d-flex flex-column gap-3">
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span class="small">Isolamento patrimonial via regime de afetação aplicável.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span class="small">Monitoramento de derivativos e travas de commodities, se aplicável.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Monitoramento pós-fechamento -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Gestão do Lastro</span>
                <h2 class="h3 fw-bold text-dark mb-4">Gestão Ativa do Ciclo de Safra</h2>
                <p class="text-muted mb-4 lead">
                    No CRA, o lastro evolui em simbiose com o campo. Monitoramos cada etapa técnica, garantindo que os eventos de colheita e liquidação financeira sejam consolidados com transparência.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Verificação regular da conformidade documental e liquidação de CPRs.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acompanhamento ativo de covenants comerciais contra oscilações severas.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Reportes periódicos detalhando a evolução do lastro e posição de colaterais.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/cra_agronegocio.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
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
                                    "No agronegócio, estruturação financeira qualificada baseia-se na sincronização temporal. Entendemos que um título corporativo deve estar acoplado à sazonalidade e à dinâmica operacional do campo. Nosso papel é aproximar o mercado de capitais das grandes cadeias agroindustriais com governança fiduciária rígida."
                                </p>
                                <footer class="blockquote-footer mt-2">
                                    <span class="fw-bold text-brand fs-5 d-block">Diretoria de Agronegócio</span>
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

<!-- Monitoramento e Tecnologia de Campo / Regulatório -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Garantia Tecnológica</span>
                <h2 class="h3 fw-bold text-dark mb-4">Inteligência Geográfica no Monitoramento</h2>
                <p class="text-muted mb-4">Mitigamos riscos mitigáveis com ferramentas técnicas que validam as condições reais e o desenvolvimento do lastro fiduciário.</p>
                
                <div class="d-flex flex-column gap-4">
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h7"/><path d="M16 5V3"/><path d="M8 5V3"/><path d="M3 9h18"/><circle cx="18" cy="18" r="3"/><path d="m18 15.5-2 2.5 2 2.5 2-2.5-2-2.5z"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Sensoriamento Remoto</h5>
                            <p class="small text-muted mb-0">Acompanhamento geoespacial do desenvolvimento vegetativo das áreas produtivas coligadas.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Auditoria de Lastro</h5>
                            <p class="small text-muted mb-0">Roteiro técnico e documental para checagem de posições de estoques e armazéns.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="position-relative p-4 bg-white shadow-sm rounded-4 border">
                    <div class="text-brand fw-bold small text-uppercase mb-3" style="letter-spacing: 0.05em;">Adequação Normativa</div>
                    <h3 class="h4 fw-bold mb-3">CMN 5.118 e a Elegibilidade do Lastro</h3>
                    <p class="text-muted small">Atuamos em estrita observância às normativas do Conselho Monetário Nacional (CMN), aplicando os critérios vigentes relativos às restrições de lastro e elegibilidade de emissores corporativos no âmbito dos Certificados de Recebíveis do Agronegócio (CRA).</p>
                    <div style="height: 2px; width: 60px; background: var(--gold);" class="my-3"></div>
                    <p class="small text-muted mb-0 font-italic">*Oferecemos suporte consultivo na análise de aderência de cadeias de recebíveis agroindustriais.</p>
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
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Diretrizes de Inteligência</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Inteligência Financeira no Campo</h2>
                    <p class="text-muted mb-4">Apresentamos respostas estratégicas acerca dos mecanismos corporativos de securitização e controle de riscos de CRA.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Consultar Estruturação</a>
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

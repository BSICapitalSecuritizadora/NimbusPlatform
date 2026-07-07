@extends('site.layout')

@section('title', 'Funding Estruturado para Cooperativas do Agronegócio | BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/cooperativas_agro2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Mercado de Capitais</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Funding Estruturado para <br><span style="color: var(--gold);">Cooperativas do Agro</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%; line-height: 1.6;">
                    Estruturamos operações para cooperativas que buscam transformar CPRs, CDCAs e recebíveis do agronegócio em funding via mercado de capitais, com governança do lastro, respeito à dinâmica associativa e monitoramento ativo da carteira.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Solicitar análise para cooperativa
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
                        <img src="{{ asset('images/cooperativas_agro.jpg') }}" class="img-fluid" alt="Cooperativas do Agronegócio" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Cooperados</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Fomento à base</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- Desafios Financeiros Resolvidos -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-4">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Desafios Associativos</span>
            <h2 class="h3 fw-bold text-dark mb-3">Eficiência na Estrutura de Capital Cooperativo</h2>
            <p class="text-muted mx-auto" style="max-width: 650px;">O modelo cooperativista possui particularidades fiduciárias que demandam inteligência na originação e controle de recebíveis.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Fomento da Base e Safra</h4>
                    <p class="text-muted small mb-0">Gerenciamos o descasamento natural entre os adiantamentos de insumos aos cooperados e a posterior entrega física, otimizando o fluxo de caixa fiduciário.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Lastro de Alta Pulverização</h4>
                    <p class="text-muted small mb-0">Estruturamos a conciliação técnica de milhares de recebíveis individuais, organizando contratos, CPRs e CDCAs pulverizados em uma estrutura unificada para o mercado.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-light h-100 border">
                    <h4 class="h5 fw-bold text-dark mb-3">Governança para Rating</h4>
                    <p class="text-muted small mb-0">Contribuímos para a preparação informacional rigorosa das cooperativas frente a agências de classificação de risco de mercado, auditorias e investidores institucionais.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Para quem a estrutura é indicada -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Público-Alvo Qualificado</span>
            <h2 class="h3 fw-bold text-dark mb-3">Alinhamento Comercial: Para quem a estrutura é indicada</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossa modelagem atende à arquitetura de cooperativas de médio e grande porte integradas às cadeias produtivas.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 border h-100 shadow-sm">
                    <h4 class="h6 fw-bold text-brand text-uppercase mb-2">Cooperativas Agropecuárias & Centrais</h4>
                    <p class="text-muted small mb-0">Cooperativas de produção de café, grãos, leite e carnes, bem como suas centrais com balanços consolidados e auditados.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 border h-100 shadow-sm">
                    <h4 class="h6 fw-bold text-brand text-uppercase mb-2">Detentoras de Recebíveis</h4>
                    <p class="text-muted small mb-0">Cooperativas agrícolas com carteiras pulverizadas de associados e volumes robustos de CPRs e CDCAs elegíveis.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 border h-100 shadow-sm">
                    <h4 class="h6 fw-bold text-brand text-uppercase mb-2">Agroindústrias Cooperativistas</h4>
                    <p class="text-muted small mb-0">Usinas, plantas de beneficiamento e indústrias de laticínios ligadas ao modelo cooperativo que buscam diversificar fontes de funding.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios Section -->
<section class="py-5 bg-white">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diferenciais Técnicos</span>
            <h2 class="h3 fw-bold text-dark mb-3">Sincronia Operacional e Associativa</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossas estruturas respeitam a dinâmica das cooperativas, integrando-se aos ciclos de produção e pagamento sem afetar a relação com o associado.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover bg-light" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-sm" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Liquidez Complementar</h3>
                    <p class="text-muted mb-0">Antecipação de recebíveis comerciais para sustentar o giro operacional da cooperativa e garantir o suporte necessário aos cooperados durante a safra.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover bg-light" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-sm" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Eficiência na Estrutura de Capital</h3>
                    <p class="text-muted mb-0">Acesso qualificado ao mercado de capitais para diversificar fontes de funding, estruturando captações com prazos aderentes ao planejamento de longo prazo.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover bg-light" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-white rounded-circle shadow-sm" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Mitigação Fiduciária</h3>
                    <p class="text-muted mb-0">Modelagem técnica com atenção à segregação dos fluxos fiduciários, apoiando a organização transparente do lastro comercial.</p>
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
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diretrizes Fiduciárias</span>
                <h2 class="h3 fw-bold text-dark mb-4">Perfil das Operações para Cooperativas</h2>
                <p class="text-muted mb-4">Desenvolvemos soluções direcionadas a operações de médio e grande porte, conforme análise técnica de viabilidade e governança corporativa.</p>

                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Volumetria sob Medida</div>
                            <div class="small text-muted">Estruturas dimensionadas de acordo com as necessidades de balanço corporativo.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Ato Cooperativo</div>
                            <div class="small text-muted">Atenção fiduciária à natureza jurídica instituída pela Lei 5.764.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Monitoramento CPR</div>
                            <div class="small text-muted">Tecnologia aplicada ao controle de recebíveis com base pulverizada.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Suporte Informacional</div>
                            <div class="small text-muted">Contribuição para a preparação técnica de dados junto a agentes de mercado.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg overflow-hidden" style="border-radius: 24px;">
                    <div class="card-body p-5" style="background: var(--brand);">
                        <h4 class="text-white fw-bold mb-4">Gestão de Concentração e Risco</h4>
                        <p class="text-white opacity-75 small mb-3">Atuamos de forma analítica no mapeamento das exposições das cooperativas para robustecer as estruturas fiduciárias.</p>
                        <div class="d-flex flex-column gap-2 text-white small">
                            <span>• Análise de concentração por cultura e geografia produtiva.</span>
                            <span>• Validação de colaterais agrícolas e direitos creditórios do agronegócio.</span>
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
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Controle de Fluxos</span>
                <h2 class="h3 fw-bold text-dark mb-4">Gestão de Lastro Pulverizado</h2>
                <p class="text-muted mb-4 lead">
                    O funding para cooperativas requer atenção minuciosa à pulverização. Monitoramos fluxos de recebíveis associados de forma sistemática, atestando conformidade estrutural.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Verificação de fluxos com atenção técnica à distinção entre ato cooperativo e comercial.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Controle de concentração de risco por cultura, região e perfil fiduciário consolidado.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Reporte operacional periódico alinhado às diretrizes técnicas de transparência de mercado.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/cooperativas_agro.jpg') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Ciclo de Liquidez do Cooperado / Regulatório -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Fomento de Base</span>
                <h2 class="h3 fw-bold text-dark mb-4">Eficiência no Fluxo Associativo</h2>
                <p class="text-muted mb-4">A securitização funciona como um motor de liquidez qualificado que conecta a força da produção ao mercado de capitais.</p>

                <div class="d-flex flex-column gap-4">
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Captação Estruturada via CRA ou CDCA</h5>
                            <p class="small text-muted mb-0">Acesso a canais de desbancarização fiduciária, diversificando as fontes de financiamento de longo prazo.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Fortalecimento Institucional</h5>
                            <p class="small text-muted mb-0">Contribui para que a cooperativa estruture pagamentos de safra e fornecimento de insumos com maior previsibilidade de caixa.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="position-relative p-4 bg-white shadow-sm rounded-4 border">
                    <div class="text-brand fw-bold small text-uppercase mb-3" style="letter-spacing: 0.05em;">Segurança Fiduciária</div>
                    <h3 class="h4 fw-bold mb-3">Atenção ao Marco do Ato Cooperativo</h3>
                    <p class="text-muted small">Nossa modelagem técnica busca estruturar a operação com atenção à preservação da natureza do Ato Cooperativo (Lei 5.764/71), observadas as validações jurídicas e regulatórias aplicáveis. Apoiamos a organização do lastro baseado em CPRs e CDCAs para manter a conformidade fiscal e a segurança da base associativa.</p>
                    <div style="height: 2px; width: 60px; background: var(--gold);" class="my-4"></div>
                    <p class="small text-muted mb-0 font-italic">*Alinhamento fiduciário com as diretrizes regulatórias vigentes da CVM.</p>
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
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Governança</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Aspectos Estratégicos do Funding Cooperativista</h2>
                    <p class="text-muted mb-4">Esclarecemos os pontos críticos sobre estruturação financeira, controle de lastro pulverizado e governança corporativa aplicáveis ao modelo cooperativista.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Consultoria Agro</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqCooperativa">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Como atuamos em relação à segurança fiscal do Ato Cooperativo?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqCooperativa">
                            <div class="accordion-body px-0 text-muted">
                                Desenvolvemos mitigações fiduciárias na modelagem do lastro comercial para buscar que a cessão dos créditos ocorra em estrita aderência aos marcos normativos, respeitando o fluxo fiduciário entre a cooperativa e sua base de associados.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Qual o papel do CDCA na securitização?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqCooperativa">
                            <div class="accordion-body px-0 text-muted">
                                O CDCA é um instrumento emitido pela cooperativa que lastreia a emissão do CRA. Ele unifica e confere elegibilidade fiduciária a múltiplos direitos creditórios e CPRs da base associativa, otimizando o acesso ao mercado de capitais.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Como lidamos com a pulverização do lastro?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqCooperativa">
                            <div class="accordion-body px-0 text-muted">
                                Mapeamos tecnicamente as matrizes de risco por cultura, perfil geográfico e track record produtivo dos associados. Isso confere robustez informacional para análise junto a investidores institucionais.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. A BSI auxilia no processo de governança para Rating?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqCooperativa">
                            <div class="accordion-body px-0 text-muted">
                                Sim. Atuamos de forma consultiva na organização informacional e transparência de dados exigidas por agências de classificação de risco e auditorias externas, auxiliando na busca por maior eficiência na estrutura de capital.
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
                    <p class="text-muted mb-3">Soluções de CRA com lastros auditáveis em CPR, CDCA e CDA/WA e conformidade ao ciclo biológico.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Acessar soluções de CRA →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.agronegocio.projetos') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Projetos Agro</h3>
                    <p class="text-muted mb-3">Financiamento estruturado para projetos de expansão rural, armazenagem e logística com lastro em recebíveis.</p>
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
</style>
@endpush
@endsection

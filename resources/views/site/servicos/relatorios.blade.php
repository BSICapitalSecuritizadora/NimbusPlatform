@extends('site.layout')

@section('title', 'Relatórios Customizados | BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/relatorios2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Gestão</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Relatórios Customizados</span> <br>e Gerenciais
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Consolidamos informações sobre lastro, garantias, fluxo de caixa, pagamentos, covenants e eventos relevantes para apoiar investidores, agentes fiduciários e demais stakeholders no acompanhamento contínuo das operações.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Solicitar relatório ou suporte fiduciário
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões estruturadas
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/relatorios.jpg') }}" class="img-fluid" alt="Relatórios Gerenciais" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Informação de qualidade</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Controle ponta a ponta</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Para quem os relatórios são indicados -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Para quem os relatórios são indicados</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Estruturamos as informações da emissão para suportar a governança operacional e fiduciária dos diversos stakeholders envolvidos no mercado de capitais.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Investidores</h4>
                        <p class="text-muted small mb-0">Investidores de CRI, CRA e CR interessados na performance e saúde dos ativos investidos.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Agentes Fiduciários</h4>
                        <p class="text-muted small mb-0">Monitoramento da conformidade de lastro, garantias e condições estabelecidas nos documentos da emissão.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Emissores</h4>
                        <p class="text-muted small mb-0">Acompanhamento consolidado do histórico operacional de suas próprias emissões e status de garantias.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Gestores e Administradores</h4>
                        <p class="text-muted small mb-0">Organização documental e visibilidade operacional necessárias para controle de carteiras e fundos de investimento.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Áreas de Backoffice</h4>
                        <p class="text-muted small mb-0">Base de dados e fluxo de caixa padronizados para alimentar rotinas de controle e auditoria interna.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Stakeholders Autorizados</h4>
                        <p class="text-muted small mb-0">Acesso segregado a eventos relevantes e relatórios gerenciais conforme regras de governança e proteção de dados.</p>
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
            <h2 class="h3 fw-bold text-dark mb-3">Informação estruturada para acompanhamento da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossos relatórios e arquivos de controle são ferramentas elaboradas para proporcionar visibilidade operacional consistente sobre as carteiras e ativos estruturados.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Visibilidade de Performance</h3>
                    <p class="text-muted mb-0">Acompanhamento do comportamento da inadimplência, do fluxo de caixa e do status das garantias. Proporcionamos organização documental para acompanhamento contínuo dos recebíveis e suporte à governança da operação.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Rigor e Conformidade</h3>
                    <p class="text-muted mb-0">Nossos relatórios fiduciários e regulatórios são estruturados conforme a governança aplicável e alinhados às práticas de acompanhamento do mercado, proporcionando rastreabilidade operacional e organização de rotinas operacionais.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Dados que Ajudam a Decidir</h3>
                    <p class="text-muted mb-0">Apresentamos o status da operação por meio de indicadores estruturados, monitorando concentração de risco e taxa de pré-pagamento, oferecendo maior previsibilidade informacional e redução de assimetria.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Entregas e Inteligência -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5 align-items-center">
            <div class="col-lg-7">
                <div class="position-relative p-4 bg-white rounded-4 shadow-sm border border-brand-subtle overflow-hidden">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <span class="badge bg-light text-dark border px-3 py-1 rounded-pill smaller fw-bold">Monitoramento de Gatilhos</span>
                        <div class="d-flex gap-1">
                            <div class="bg-gold rounded-circle" style="width: 8px; height: 8px;"></div>
                            <div class="bg-gold rounded-circle" style="width: 8px; height: 8px; opacity: 0.2;"></div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="bg-light rounded-3 p-3 mb-3 border">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="small fw-bold text-muted">Inadimplência (D+30)</div>
                                    <div class="smaller text-success fw-bold">Dentro do Limite</div>
                                </div>
                                <div style="height: 100px; width: 100%; position: relative;">
                                    <canvas id="inadimplenciaChart"></canvas>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="bg-white border p-2 rounded-2 text-center shadow-xs">
                                        <div class="smaller text-muted">Overcollateral</div>
                                        <div class="small fw-bold text-brand">125.4%</div>
                                        <div class="smaller text-success" style="font-size: 0.6rem;">Min: 115%</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-white border p-2 rounded-2 text-center shadow-xs">
                                        <div class="smaller text-muted">LTV Médio</div>
                                        <div class="small fw-bold text-brand">58.2%</div>
                                        <div class="smaller text-success" style="font-size: 0.6rem;">Max: 70%</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="rounded-3 p-3 text-white h-100 shadow-sm" style="background-color: var(--brand-strong, #091b23);">
                                <div class="small fw-bold opacity-75 mb-3">Status de Covenants</div>
                                <div class="d-flex flex-column gap-3">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="smaller opacity-75">Financeiro</span>
                                        <span class="badge bg-success" style="font-size: 0.5rem;">OK</span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="smaller opacity-75">Garantias</span>
                                        <span class="badge bg-success" style="font-size: 0.5rem;">OK</span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="smaller opacity-75">Operacional</span>
                                        <span class="badge bg-success" style="font-size: 0.5rem;">OK</span>
                                    </div>
                                    <div class="mt-2 pt-2 border-top text-center" style="border-color: rgba(255,255,255,0.15) !important;">
                                        <div class="smaller fw-bold text-gold" style="color: var(--gold, #d4af37);">LIQUIDAÇÃO: NORMAL</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="smaller text-muted mt-4 mb-0 text-center" style="font-size: 0.65rem;">
                        <em>Exemplo ilustrativo de acompanhamento gerencial. Indicadores, limites e status variam conforme a operação, os documentos da emissão e a disponibilidade das informações.</em>
                    </p>
                </div>
            </div>
            <div class="col-lg-5">
                <h2 class="h4 fw-bold text-dark mb-4">Relatórios que entregamos</h2>
                <div class="d-flex flex-column gap-3">
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Visão Operacional da Carteira</div>
                        <div class="text-muted smaller">Acompanhamento do fluxo de caixa e pagamentos, organizando relatórios gerenciais sobre o comportamento da carteira.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Apoio ao Agente Fiduciário</div>
                        <div class="text-muted smaller">Consolidação de indicadores de garantias e covenants, apoiando o monitoramento de lastro e a observância às condições da operação.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Documentos Oficiais</div>
                        <div class="text-muted smaller">Estruturamos informes periódicos como o Informe Mensal CVM e relatórios fiduciários exigidos regulatoriamente.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Checklist de Relatórios -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h4 fw-bold text-dark">Nosso escopo de relatórios gerenciais</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Consolidamos os relatórios aplicáveis a cada operação, conforme estruturado em seus documentos, para manter todos os stakeholders alinhados.</p>
        </div>

        <div class="row g-4 justify-content-center text-center">
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Informe Mensal CVM</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Relatório Fiduciário</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Status de Garantias</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Fluxo de Caixa</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Eventos Relevantes</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Histórico Documental</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Caminho Crítico de Mitigação -->
<section class="py-5 bg-light" style="border-top: 1px solid var(--border);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <h2 class="h3 fw-bold text-dark mb-4">Monitoramento de eventos e pontos de atenção</h2>
                <p class="text-muted">Estabelecemos rotinas contínuas de acompanhamento. Quando são identificadas divergências operacionais estruturais ou quebras de limites aplicáveis, adotamos protocolos internos voltados a organizar o fluxo de informação e alertar os agentes adequados.</p>
            </div>
            <div class="col-lg-7">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded-4 border shadow-xs h-100 text-center">
                            <div class="h5 fw-bold text-danger mb-2">01</div>
                            <div class="fw-bold small text-dark mb-1">Identificação de Desvios</div>
                            <p class="smaller text-muted mb-0">Identificação por meio de nossas rotinas de conciliação de informações e acompanhamento fiduciário estruturado.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded-4 border shadow-xs h-100 text-center">
                            <div class="h5 fw-bold text-warning mb-2">02</div>
                            <div class="fw-bold small text-dark mb-1">Comunicação Adequada</div>
                            <p class="smaller text-muted mb-0">Comunicação diligente sobre eventos relevantes aos agentes envolvidos conforme a governança e os documentos da operação.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded-4 border shadow-xs h-100 text-center">
                            <div class="h5 fw-bold text-success mb-2">03</div>
                            <div class="fw-bold small text-dark mb-1">Avaliação e Suporte</div>
                            <p class="smaller text-muted mb-0">Avaliação de medidas aplicáveis e suporte na interface junto aos agentes autorizados para direcionamento do plano de ação.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Ciclo de produção dos relatórios -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Como estruturamos os dados da operação</h2>
                <p class="text-muted mb-4 lead">
                    Nossa rotina é fundamentada na revisão técnica e na consolidação de bases. O processo visa otimizar o fluxo de dados, reduzir assimetria informacional e organizar a comunicação conforme os prazos operacionais.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Consolidação de bases e acompanhamento estruturado do fluxo de caixa conforme os parâmetros dos documentos da emissão.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Revisão técnica e conciliação de informações financeiras para a entrega periódica de relatórios fiduciários e gerenciais de forma diligente.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Interface com prestadores de serviço e sistemas de controle de recebíveis de modo a compor relatórios consolidados confiáveis.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Disponibilização de relatórios no Portal do Investidor BSI, mantendo um histórico controlado de publicações acessível aos stakeholders autorizados.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/relatorios.jpg') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Serviços relacionados -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Serviços relacionados</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Os relatórios alimentam o portal e sustentam a conformidade regulatória. Conheça os serviços diretamente conectados a esta entrega.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.servicos.portal-investidor') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Portal do Investidor</h3>
                    <p class="text-muted mb-3">O ambiente onde todos os relatórios ficam disponíveis. Oferecemos acesso seguro, envio de notificações automáticas e o histórico completo de cada operação.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.servicos.monitoramento-regulatorio') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Monitoramento regulatório</h3>
                    <p class="text-muted mb-3">A equipe que garante que a operação ande na linha. Eles definem os padrões de qualidade e os prazos que os relatórios devem seguir para atender a CVM e a ANBIMA.</p>
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
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('inadimplenciaChart').getContext('2d');

        const brand = '#091b23';

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun'],
                datasets: [{
                    label: 'Inadimplência',
                    data: [1.2, 1.8, 1.6, 2.4, 2.2, 3.4],
                    backgroundColor: [
                        'rgba(160, 110, 40, 0.25)',
                        'rgba(160, 110, 40, 0.25)',
                        'rgba(160, 110, 40, 0.25)',
                        'rgba(160, 110, 40, 0.5)',
                        'rgba(160, 110, 40, 0.5)',
                        'rgba(160, 110, 40, 1)',
                    ],
                    borderRadius: {
                        topLeft: 4,
                        topRight: 4,
                        bottomLeft: 0,
                        bottomRight: 0
                    },
                    borderSkipped: false,
                    barPercentage: 0.9,
                    categoryPercentage: 0.9
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: brand,
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + '%';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: false,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        display: false,
                        beginAtZero: true,
                        grid: {
                            display: false
                        }
                    }
                },
                animation: {
                    duration: 1500,
                    easing: 'easeOutQuart'
                }
            }
        });
    });
</script>
@endpush
@endsection

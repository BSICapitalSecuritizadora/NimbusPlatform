@extends('site.layout')

@section('title', 'Monitoramento Regulatório — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/monitoramento.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Serviços</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Monitoramento Regulatório para<br><span style="color: var(--gold);">Operações Estruturadas</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Acompanhamos obrigações regulatórias, controles de KYC/PLD-FTP, covenants e eventos críticos das operações, com rotinas de governança, registro de ocorrências e trilha auditável para apoiar emissores, investidores e agentes fiduciários.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Solicitar acompanhamento regulatório
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
                        <img src="{{ asset('images/monitoramento.jpg') }}" class="img-fluid" alt="Monitoramento regulatório" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Monitoramento ativo</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">KYC & PLD/FTP</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Público-alvo Section -->
<section class="py-5 bg-white">
    <div class="container py-4">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <h2 class="h3 fw-bold text-dark mb-4">Para quem o monitoramento regulatório é indicado</h2>
                <p class="text-muted mb-4">
                    Nossa esteira de controle, trilha auditável e governança atende às necessidades de acompanhamento operacional e compliance dos principais agentes de securitização, em emissões de CRI, CRA ou CR.
                </p>
                <a href="{{ route('site.contact') }}" class="btn btn-outline-brand px-4 py-2">Consultar suporte regulatório da operação</a>
            </div>
            <div class="col-lg-7">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-gold mt-1">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold mb-1">Emissores de CRI, CRA e CR</h4>
                                <p class="text-muted small mb-0">Acompanhamento contínuo de obrigações e monitoramento da operação estruturada.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-gold mt-1">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold mb-1">Investidores Institucionais</h4>
                                <p class="text-muted small mb-0">Vigilância ativa de covenants e eventos críticos relacionados à carteira.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-gold mt-1">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold mb-1">Agentes Fiduciários</h4>
                                <p class="text-muted small mb-0">Apoio na identificação e documentação de ocorrências e prazos da operação.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-gold mt-1">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold mb-1">Áreas de Compliance e Risco</h4>
                                <p class="text-muted small mb-0">Registros e diligências que compõem uma trilha de auditoria transparente.</p>
                            </div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Governança regulatória ao longo da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Mantemos a integridade da estrutura de securitização através de rotinas de governança sólidas. Nossas práticas são desenhadas para apoiar a aderência às normas da CVM e ANBIMA de forma institucional.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Prevenção e Gestão de Riscos</h3>
                    <p class="text-muted mb-0">Nossas análises de clientes e prestadores (KYC/KYP) e diligências contínuas baseadas em risco contribuem para a segurança jurídica e para a mitigação de riscos reputacionais relacionados a PLD/FTP.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Vigilância de Covenants</h3>
                    <p class="text-muted mb-0">Acompanhamento contínuo das obrigações contratuais: monitoramos o cumprimento de cláusulas, registrando ocorrências e emitindo alertas para viabilizar as devidas tratativas conforme governança da operação.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7m0-18H5a2 2 0 0 1-2 2v14a2 2 0 0 1 2 2h7m0-18v18"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Independência Institucional</h3>
                    <p class="text-muted mb-0">Decisões técnicas focadas na segurança da estrutura. Nosso comitê atua com segregação decisória em relação à área comercial, buscando que os riscos sejam avaliados de forma imparcial e transparente.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Tecnologia e Governança -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5 align-items-center">
            <div class="col-lg-6">
                <h2 class="h4 fw-bold text-dark mb-4">Tecnologia a serviço da agilidade</h2>
                <p class="text-muted small mb-4">Unimos tecnologia e processos digitais para otimizar etapas de triagem. Consultas e verificações em bases diversas reduzem o retrabalho operacional e reforçam o controle documental com trilha de auditoria para os agentes da operação.</p>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark smaller mb-1">Bases Oficiais</div>
                            <div class="text-muted smaller">Receita Federal, tribunais e demais fontes, conforme escopo da análise.</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark smaller mb-1">PLD/FTP & Restritivas</div>
                            <div class="text-muted smaller">Controles relacionados a listas restritivas e normas de PLD/FTP.</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark smaller mb-1">Crédito & Informação</div>
                            <div class="text-muted smaller">Bureau de crédito e bases públicas ou privadas contratadas.</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark smaller mb-1">Reputação Institucional</div>
                            <div class="text-muted smaller">Monitoramento ativo de notícias e reputação aplicável à carteira.</div>
                        </div>
                    </div>
                </div>

                <!-- Box de Downloads Rápidos -->
                <div class="mt-5 p-4 bg-white rounded-4 border shadow-sm">
                    <h4 class="h6 fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                        Políticas e Governança
                    </h4>
                    <div class="d-flex flex-column gap-2">
                        <a href="{{ route('site.compliance') }}" class="d-flex justify-content-between align-items-center p-2 bg-light rounded text-decoration-none border-hover transition-all">
                            <span class="smaller fw-medium text-dark">Código de Ética e Conduta Institucional</span>
                            <span class="badge bg-gold-subtle text-gold">Acessar</span>
                        </a>
                        <a href="{{ route('site.compliance') }}" class="d-flex justify-content-between align-items-center p-2 bg-light rounded text-decoration-none border-hover transition-all">
                            <span class="smaller fw-medium text-dark">Política Interna de PLD/FTP</span>
                            <span class="badge bg-gold-subtle text-gold">Acessar</span>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="bg-white p-4 rounded-4 shadow-sm border border-brand-subtle overflow-hidden">
                    <div class="text-center mb-4">
                        <span class="badge bg-gold-subtle text-gold px-3 py-1 rounded-pill smaller fw-bold">Fluxo Institucional</span>
                    </div>
                    <h3 class="h5 fw-bold text-dark mb-4 text-center">Nossa Esteira de Aprovação</h3>
                    <div class="d-flex flex-column gap-3 position-relative">
                        <div class="position-absolute h-100 border-start border-2 border-dashed" style="left: 20px; top: 0; opacity: 0.2;"></div>

                        <div class="d-flex align-items-center gap-3 position-relative z-1">
                            <div class="text-white rounded-circle d-flex align-items-center justify-content-center fw-bold small shadow-sm" style="background-color: var(--brand, #091b23); width: 40px; height: 40px; min-width: 40px;">1</div>
                            <div class="flex-grow-1 p-2 bg-light rounded-3 border">
                                <div class="fw-bold smaller" style="color: var(--brand, #091b23);">Triagem e coleta de informações</div>
                                <div class="smaller text-muted">Consultas e verificações em bases públicas, privadas ou contratadas, conforme escopo e disponibilidade.</div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3 position-relative z-1">
                            <div class="text-white rounded-circle d-flex align-items-center justify-content-center fw-bold small shadow-sm" style="background-color: var(--brand, #091b23); width: 40px; height: 40px; min-width: 40px;">2</div>
                            <div class="flex-grow-1 p-2 bg-light rounded-3 border">
                                <div class="fw-bold smaller" style="color: var(--brand, #091b23);">Análise técnica e diligências</div>
                                <div class="smaller text-muted">Nossa equipe avalia a documentação com critérios regulatórios para identificar desvios e pontos de atenção.</div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3 position-relative z-1">
                            <div class="text-white rounded-circle d-flex align-items-center justify-content-center fw-bold small shadow-sm" style="background-color: var(--gold, #d4af37); width: 40px; height: 40px; min-width: 40px;">3</div>
                            <div class="flex-grow-1 p-3 rounded-3 border shadow-xs" style="background-color: rgba(212,175,55,0.05); border-color: rgba(212,175,55,0.2) !important;">
                                <div class="fw-bold small" style="color: var(--gold, #d4af37);">Comitê e registro da decisão</div>
                                <div class="smaller text-muted">Um comitê avalia a operação através de regras de segregação, emitindo parecer técnico com registro de deliberação.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Tratativa de alertas -->
<section class="py-5 bg-white">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="p-4 p-md-5 rounded-4 border bg-light d-flex flex-column flex-md-row align-items-center gap-5 shadow-sm">
                    <div class="flex-shrink-0 text-center">
                        <div class="bg-white p-4 rounded-circle shadow-sm border border-brand-subtle mb-3 mx-auto" style="width: 100px; height: 100px; display: grid; place-items: center; color: var(--gold);">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </div>
                        <span class="badge bg-gold text-white px-3 py-1 text-uppercase fw-bold" style="font-size: 0.6rem;">Escalonamento</span>
                    </div>
                    <div>
                        <h3 class="h4 fw-bold text-dark mb-3">Gestão de alertas e exceções</h3>
                        <p class="text-muted mb-4">
                            Quando um covenant, prazo regulatório ou documento crítico apresenta desvios, nosso time atua na formalização: registra a ocorrência, define responsáveis, classifica a criticidade e preserva a trilha auditável. O objetivo é viabilizar a comunicação institucional para emissores e agentes fiduciários, acompanhando as tratativas até sua deliberação ou regularização.
                        </p>
                        <p class="text-muted mb-4">
                            Informações sobre governança corporativa, código de conduta e canal de integridade da BSI Capital são concentradas na página institucional de Compliance.
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <a href="{{ route('site.contact') }}" class="btn btn-dark btn-sm px-4 py-2">Avaliar obrigações da operação</a>
                            <a href="{{ route('site.compliance') }}" class="btn btn-outline-dark btn-sm px-4 py-2">Ir para Compliance Institucional</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Ciclo de monitoramento -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Rotina de alertas, tratativas e reporte</h2>
                <p class="text-muted mb-4 lead">
                    O monitoramento contínuo apoia a operação após a emissão. Aplicamos protocolos institucionais para que obrigações contratuais e regulatórias sejam acompanhadas e, em caso de pontos de atenção, investidores e agentes fiduciários recebam comunicados com previsibilidade e clareza.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Alertas e controles documentais estruturados para identificar desvios em covenants, garantias e fluxo de caixa.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Protocolos de resposta e comunicação de eventos aplicáveis, oferecendo apoio operacional em assembleias, se contratado.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acompanhamento rigoroso de reportes, obrigações CVM e registros junto a prestadores e agentes de liquidação.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Interface e coordenação de entregas com auditorias externas e demais agentes envolvidos no acompanhamento.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/monitoramento.jpg') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Serviços relacionados -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Serviços relacionados</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">O monitoramento regulatório sustenta a integridade dos dados publicados nos relatórios e o controle de acesso ao ambiente do investidor.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.servicos.relatorios') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Relatórios e Monitoramento</h3>
                    <p class="text-muted mb-3">Acompanhe cada detalhe da sua carteira. Geramos relatórios periódicos e transparentes sobre inadimplência, covenants e eventos de crédito, mantendo investidores e agentes fiduciários sempre bem informados.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.servicos.auditoria-acessos') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Auditoria de Acessos</h3>
                    <p class="text-muted mb-3">Segurança total nos seus dados. Monitoramos cada acesso ao ambiente operacional com rastreabilidade completa, garantindo que as políticas de segregação e segurança sejam sempre respeitadas.</p>
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
@endsection

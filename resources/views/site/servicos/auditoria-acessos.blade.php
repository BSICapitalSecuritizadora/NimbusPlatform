@extends('site.layout')

@section('title', 'Auditoria de Acessos — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/auditoria_acessos.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Tecnologia</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Rastreabilidade e <br><span style="color: var(--gold);">Trilha de Auditoria</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Registre acessos, downloads e alterações de perfil em trilhas estruturadas, com logs operacionais, evidências de integridade e relatórios exportáveis para apoiar compliance, auditoria, agente fiduciário e governança documental.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Solicitar auditoria de acessos
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
                        <img src="{{ asset('images/auditoria_acessos.png') }}" class="img-fluid" alt="Auditoria de Acessos" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Evidências operacionais</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Log por operação</div>
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
                <h2 class="h3 fw-bold text-dark mb-4">Para quem a auditoria de acessos é indicada</h2>
                <p class="text-muted mb-4">
                    A geração de evidências estruturadas e logs operacionais atende aos requisitos de controle de diversos stakeholders da operação.
                </p>
                <a href="{{ route('site.contact') }}" class="btn btn-outline-brand px-4 py-2">Solicitar relatório de acessos</a>
            </div>
            <div class="col-lg-7">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-gold mt-1">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold mb-1">Agentes fiduciários e Investidores</h4>
                                <p class="text-muted small mb-0">Verificação de acessos aos documentos restritos da operação.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-gold mt-1">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold mb-1">Compliance e Privacidade</h4>
                                <p class="text-muted small mb-0">Trilha de eventos para apoio a DPOs, LGPD e exigências de controles internos.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-gold mt-1">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold mb-1">Auditoria interna e externa</h4>
                                <p class="text-muted small mb-0">Logs operacionais e relatórios exportáveis para suporte em verificações.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-gold mt-1">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold mb-1">Equipes operacionais e Emissores</h4>
                                <p class="text-muted small mb-0">Rastreabilidade em alterações de perfis e gestão do ambiente documental.</p>
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
            <h2 class="h3 fw-bold text-dark mb-3">Governança, logs e evidências operacionais</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Registros técnicos compõem trilhas estruturadas. Interações operacionais são apoiadas por controle de integridade para dar suporte documental ao compliance e ao agente fiduciário.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Integridade verificável dos registros</h3>
                    <p class="text-muted mb-0">Registros com hash criptográfico (SHA-256) podem apoiar a verificação de integridade dos eventos registrados, compondo a trilha de evidências da operação conforme a governança e os controles aplicáveis.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Relatórios para auditoria e compliance</h3>
                    <p class="text-muted mb-0">Relatórios estruturados e exportáveis facilitam a rotina em verificações de due diligence. Auditores externos consultam dados de acessos conforme permissão e escopo.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-5.607-7.928c.43.393.944.607 1.525.607h1.411c.581 0 1.095-.214 1.525-.607m-5.607 0C2.909 11.201 2 8.517 2 5a5 5 0 0 1 5-5c2.753 0 5.174 1.838 6.03 4.417M2 5h10M2 5v10m10 0c0 3.517 1.009 6.799 2.753 9.571m5.607-7.928c-.43.393-.944.607-1.525.607h-1.411c-.581 0-1.095-.214-1.525-.607m5.607 0C21.091 11.201 22 8.517 22 5a5 5 0 0 1-5-5c-2.753 0-5.174 1.838-6.03 4.417M22 5h-10M22 5v10"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Governança de dados sensíveis</h3>
                    <p class="text-muted mb-0">Controles e logs alinhados à LGPD, políticas internas e normas aplicáveis para apoiar análises de acesso indevido e auditoria a dados pessoais, quando necessário.</p>
                </div>
            </div>
        </div>
    </div>
</section>
        </div>
    </div>
</section>

<!-- Visualização de Dashboard de Auditoria -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5 align-items-center">
            <div class="col-lg-7">
                <!-- Dashboard Mockup -->
                <div class="bg-white rounded-4 shadow-lg border overflow-hidden">
                    <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <div class="bg-brand rounded p-1">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            </div>
                            <span class="fw-bold text-dark smaller">Painel de Monitoramento de Conformidade</span>
                        </div>
                        <div class="badge bg-success-subtle text-success border border-success-subtle smaller">Sistema Ativo</div>
                    </div>
                    
                    <div class="p-4">
                        <div class="row g-3 mb-4">
                            <div class="col-4">
                                <div class="p-3 bg-light rounded-3 text-center border">
                                    <div class="smaller text-muted mb-1">Acessos (24h)</div>
                                    <div class="h4 fw-bold mb-0 text-brand">1.284</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-3 bg-light rounded-3 text-center border">
                                    <div class="smaller text-muted mb-1">Downloads</div>
                                    <div class="h4 fw-bold mb-0 text-brand">42</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-3 bg-light rounded-3 text-center border border-warning" style="background: rgba(212, 175, 55, 0.05);">
                                    <div class="smaller text-muted mb-1">Alertas</div>
                                    <div class="h4 fw-bold mb-0 text-warning">03</div>
                                </div>
                            </div>
                        </div>

                        <!-- Gráfico Simulado -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-end" style="height: 100px; gap: 8px;">
                                @foreach([30, 45, 25, 60, 80, 40, 55, 90, 35, 50, 75, 65] as $height)
                                    <div class="bg-brand-subtle rounded-top w-100" style="height: {{ $height }}%; opacity: 0.6; transition: all 0.3s ease;" onmouseover="this.style.opacity='1'; this.style.backgroundColor='var(--gold)'" onmouseout="this.style.opacity='0.6'; this.style.backgroundColor='var(--brand-subtle)'"></div>
                                @endforeach
                            </div>
                            <div class="border-top mt-2 pt-1 d-flex justify-content-between smaller text-muted" style="font-size: 0.65rem;">
                                <span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:59</span>
                            </div>
                        </div>

                        <!-- Lista de Eventos Recentes -->
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless align-middle mb-0" style="font-size: 0.75rem;">
                                <thead class="text-muted border-bottom">
                                    <tr>
                                        <th>Horário</th>
                                        <th>Usuário</th>
                                        <th>Evento</th>
                                        <th class="text-end">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="text-muted">14:05:22</td>
                                        <td class="fw-bold">Ag. Fiduciário</td>
                                        <td>Download Escritura CRI</td>
                                        <td class="text-end text-success"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> Auditado</td>
                                    </tr>
                                    <tr class="bg-warning-subtle" style="background: rgba(212, 175, 55, 0.1);">
                                        <td class="text-muted">14:10:05</td>
                                        <td class="fw-bold text-danger">Desconhecido</td>
                                        <td class="text-danger fw-bold">Tentativa de Acesso (Sacados)</td>
                                        <td class="text-end text-danger"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> Bloqueado</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">14:15:48</td>
                                        <td class="fw-bold">Investidor Q.</td>
                                        <td>Visualizou Relatório Mensal</td>
                                        <td class="text-end text-success"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> Auditado</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="p-3 bg-dark text-white d-flex justify-content-between align-items-center" style="background-color: #091B23 !important;">
                        <div class="smaller d-flex align-items-center gap-2">
                            <span class="text-gold">•</span> Registro Criptográfico SHA-256 Ativo
                        </div>
                        <div class="smaller opacity-75">Hash: 8f2d...b18a</div>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <span class="small text-muted" style="font-size: 0.75rem; opacity: 0.8;">Exemplo ilustrativo de painel de auditoria. Dados reais variam conforme a operação, usuários autorizados, documentos cadastrados e permissões de acesso.</span>
                </div>
            </div>
            
            <div class="col-lg-5">
                <h2 class="h4 fw-bold text-dark mb-4">Monitoramento Fiduciário</h2>
                <div class="d-flex flex-column gap-3">
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Controle de acesso a dados do lastro</div>
                        <div class="text-muted smaller">Registros de acesso para apoiar rotinas de privacidade, governança e prestação de contas sobre dados sensíveis.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Notificação de Anomalias</div>
                        <div class="text-muted smaller">Alertas configuráveis para eventos fora do padrão, permitindo análises estruturadas pela equipe de compliance.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Exportação Digital Auditada</div>
                        <div class="text-muted smaller">Relatórios exportáveis para análise e documentação, gerando evidências operacionais de verificação estruturada.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Evidência em tempo real -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Evidências estruturadas para compliance e auditoria</h2>
                <p class="text-muted mb-4 lead">
                    A trilha de auditoria apoia a governança corporativa. Oferecemos ferramentas e evidências operacionais que facilitam o trabalho de reguladores e auditores na verificação de conformidade.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium"><strong>Filtros Avançados:</strong> Facilita a localização de eventos por usuário, documento, operação ou período.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium"><strong>Arquivos Assinados:</strong> Exportação de relatórios para apoio à revisão externa.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium"><strong>Acesso Direto:</strong> Acessos controlados para auditorias, conforme permissão e escopo.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium"><strong>Registros de acesso a dados pessoais:</strong> Registros para apoiar rotinas de privacidade e prestação de contas.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/auditoria_acessos.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Serviços relacionados -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Serviços relacionados</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">A trilha de auditoria é alimentada pelo controle de acesso documental e consumida pelo compliance para vigilância regulatória contínua.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.servicos.documentos-acl') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Documentos com ACL</h3>
                    <p class="text-muted mb-3">A base do controle documental. Configure permissões por perfil, operação e documento, assegurando as diretrizes que originam a trilha de evidências.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.servicos.monitoramento-regulatorio') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Monitoramento regulatório</h3>
                    <p class="text-muted mb-3">Monitoramento de obrigações, alertas e exceções. Utilizamos a trilha operacional para apoiar as verificações fiduciárias de rotina e a identificação de pendências.</p>
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

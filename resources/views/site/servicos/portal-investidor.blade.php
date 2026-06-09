@extends('site.layout')

@section('title', 'Portal do Investidor — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/portal_investidor.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Serviços</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Portal do <span style="color: var(--gold);">Investidor</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Acompanhe suas posições de PU, cronogramas de amortização e documentos fiduciários em um ambiente seguro, segregado por operação e disponível em tempo real.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ config('services.portal.url') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Acessar o Portal
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.contact') }}" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Falar com nossa equipe
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/portal_investidor.png') }}" class="img-fluid" alt="Portal do Investidor" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Acesso seguro 24h</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Posição em tempo real</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Transparência ativa para decisões fundamentadas</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Centralizamos os dados da sua operação em uma interface intuitiva, eliminando a busca manual por informações e garantindo controle total ao investidor.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Privacidade e Blindagem</h3>
                    <p class="text-muted mb-0">Segurança de nível bancário com autenticação multifator e criptografia. Garantimos que seus dados estejam protegidos e em total conformidade com a LGPD e normas da CVM.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Documentação Organizada</h3>
                    <p class="text-muted mb-0">Esqueça o e-mail. Tenha acesso imediato a escrituras, adendos, relatórios fiduciários e fatos relevantes em um repositório cronológico e fácil de navegar.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Monitoramento Financeiro</h3>
                    <p class="text-muted mb-0">Visualize a evolução do PU, saldos devedores e fluxos de amortização. Informação financeira clara para simplificar sua gestão de portfólio.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Conectividade e APIs -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <h2 class="h4 fw-bold text-dark mb-4">Dados Fluidos para sua Gestão</h2>
                <p class="text-muted small mb-4">Sabemos que agilidade exige integração. Além da interface web, entregamos dados estruturados via API para facilitar a vida de gestoras e administradores.</p>
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex align-items-center gap-3 p-3 bg-white rounded-3 shadow-sm card-hover transition-all">
                        <div class="bg-light p-2 rounded-circle text-gold">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
                        </div>
                        <div>
                            <div class="fw-bold text-dark small">Data Feed via API</div>
                            <div class="text-muted smaller">Integração direta com sistemas de controle de risco e gestão (ERP/Asset Management).</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3 p-3 bg-white rounded-3 shadow-sm card-hover transition-all">
                        <div class="bg-light p-2 rounded-circle text-gold">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </div>
                        <div>
                            <div class="fw-bold text-dark small">Exportação Estruturada</div>
                            <div class="text-muted smaller">Downloads em massa de PU e cronogramas em formatos compatíveis com backoffice (JSON/CSV).</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="position-relative p-4 bg-white rounded-4 shadow-sm border border-brand-subtle overflow-hidden">
                    <div class="text-center mb-3">
                        <span class="badge bg-gold-subtle text-gold px-3 py-1 rounded-pill smaller fw-bold">Interface do Portal</span>
                    </div>
                    
                    <!-- Simulated Dashboard Visual -->
                    <div class="bg-light rounded-4 p-3 border shadow-xs">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="d-flex gap-2">
                                <div class="bg-brand rounded" style="width: 30px; height: 8px;"></div>
                                <div class="bg-gold rounded" style="width: 20px; height: 8px;"></div>
                            </div>
                            <div class="bg-white rounded-pill px-2 py-1 shadow-xs" style="font-size: 0.6rem;">Usuário Logado</div>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-8">
                                <div class="bg-white rounded-3 p-3 border shadow-sm mb-3">
                                    <div class="smaller text-muted mb-2">Evolução de PU (Série 1)</div>
                                    <div style="height: 60px; width: 100%; position: relative;">
                                        <svg viewBox="0 0 100 40" style="width: 100%; height: 100%;" preserveAspectRatio="none">
                                            <defs>
                                                <linearGradient id="puGradient" x1="0" x2="0" y1="0" y2="1">
                                                    <stop offset="0%" stop-color="var(--gold, #d4af37)" stop-opacity="0.4"/>
                                                    <stop offset="100%" stop-color="var(--gold, #d4af37)" stop-opacity="0"/>
                                                </linearGradient>
                                            </defs>
                                            <path d="M0,35 L10,32 L20,34 L30,28 L40,29 L50,22 L60,24 L70,18 L80,15 L90,10 L100,5 L100,40 L0,40 Z" fill="url(#puGradient)" stroke="none"></path>
                                            <path d="M0,35 L10,32 L20,34 L30,28 L40,29 L50,22 L60,24 L70,18 L80,15 L90,10 L100,5" fill="none" stroke="var(--gold, #d4af37)" stroke-width="2" vector-effect="non-scaling-stroke"></path>
                                            <circle cx="100" cy="5" r="1.5" fill="var(--gold, #d4af37)" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="bg-white rounded-3 p-2 border text-center">
                                            <div style="font-size: 0.5rem;" class="text-muted">Saldo Devedor</div>
                                            <div class="fw-bold" style="font-size: 0.7rem;">R$ 42.5M</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="bg-white rounded-3 p-2 border text-center">
                                            <div style="font-size: 0.5rem;" class="text-muted">Próximo Pagto.</div>
                                            <div class="fw-bold text-success" style="font-size: 0.7rem;">15/06</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="rounded-3 p-3 text-white h-100" style="background-color: var(--brand-strong, #091b23);">
                                    <div class="smaller opacity-75 mb-3">Últimos Fatos Relevantes</div>
                                    <div class="d-flex flex-column gap-2">
                                        <div class="p-1 rounded smaller" style="font-size: 0.55rem; background-color: rgba(255,255,255,0.1);">• Encerramento da Oferta</div>
                                        <div class="p-1 rounded smaller" style="font-size: 0.55rem; background-color: rgba(255,255,255,0.1);">• Pagamento de Juros Série A</div>
                                        <div class="p-1 rounded smaller" style="font-size: 0.55rem; background-color: rgba(255,255,255,0.1);">• Waiver aprovado em AGT</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Documentos e Relatórios Visuais -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h4 fw-bold text-dark">Documentação e Reporting</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Acesse a prateleira completa de documentos da sua operação de forma organizada e rastreável.</p>
        </div>
        
        <div class="row g-4 justify-content-center">
            <div class="col-6 col-md-3">
                <div class="text-center p-3 border rounded-4 shadow-xs card-hover transition-all bg-light">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <div class="fw-bold small text-dark">Escrituras e Termos</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-center p-3 border rounded-4 shadow-xs card-hover transition-all bg-light">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    </div>
                    <div class="fw-bold small text-dark">Relatórios Fiduciários</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-center p-3 border rounded-4 shadow-xs card-hover transition-all bg-light">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </div>
                    <div class="fw-bold small text-dark">Cronogramas Financeiros</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-center p-3 border rounded-4 shadow-xs card-hover transition-all bg-light">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </div>
                    <div class="fw-bold small text-dark">Fatos Relevantes</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Experiência do produto -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">A Experiência do Usuário no Portal</h2>
                <p class="text-muted mb-4 lead">
                    O portal foi desenhado para ser uma ferramenta de trabalho, não apenas um arquivo. Reduzimos o tempo de resposta entre o evento operacional e a visibilidade para o investidor.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Painel consolidado com PU atualizado e próximos eventos de pagamento (amortização e juros) organizados por data.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Alertas em tempo real sobre Fatos Relevantes, relatórios periódicos e convocações de assembleias.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Histórico completo de documentos com controle de versões e downloads ilimitados.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acesso dedicado para Agentes Fiduciários monitorarem covenants e status de garantias sem ruído.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/portal_investidor.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Como obter acesso -->
<section class="py-5 bg-light" style="border-top: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="bg-white p-4 p-md-5 rounded-4 shadow-sm border">
                    <h2 class="h4 fw-bold text-dark mb-4 text-center">Como obter acesso ao Portal?</h2>
                    <div class="d-flex flex-column gap-4">
                        <div class="d-flex align-items-start gap-3">
                            <div class="bg-gold-subtle text-gold p-2 rounded-circle fw-bold small" style="width: 32px; height: 32px; display: grid; place-items: center; flex-shrink: 0;">1</div>
                            <div>
                                <h4 class="h6 fw-bold text-dark mb-1">Onboarding Automático</h4>
                                <p class="smaller text-muted mb-0">No momento do fechamento da operação (*closing*), investidores e emissores recebem um convite por e-mail com as credenciais de acesso temporárias.</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <div class="bg-gold-subtle text-gold p-2 rounded-circle fw-bold small" style="width: 32px; height: 32px; display: grid; place-items: center; flex-shrink: 0;">2</div>
                            <div>
                                <h4 class="h6 fw-bold text-dark mb-1">Ativação Segura</h4>
                                <p class="smaller text-muted mb-0">Ao primeiro login, será solicitada a criação de uma senha forte e a configuração da Autenticação Multifator (MFA) via aplicativo ou SMS.</p>
                            </div>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <div class="bg-gold-subtle text-gold p-2 rounded-circle fw-bold small" style="width: 32px; height: 32px; display: grid; place-items: center; flex-shrink: 0;">3</div>
                            <div>
                                <h4 class="h6 fw-bold text-dark mb-1">Suporte Dedicado</h4>
                                <p class="smaller text-muted mb-0">Caso você já seja um investidor da BSI e ainda não tenha suas credenciais, entre em contato com nosso time de RI ou através do suporte direto no portal.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Outros serviços -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Serviços relacionados</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">O portal é o ponto de acesso do investidor — conheça os serviços que alimentam as informações disponíveis nele.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.servicos.relatorios') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Relatórios e Monitoramento</h3>
                    <p class="text-muted mb-3">Produção dos relatórios periódicos para investidores e agente fiduciário que alimentam os dados exibidos no portal.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.servicos.compliance') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Compliance</h3>
                    <p class="text-muted mb-3">Gestão de conformidade regulatória que garante que as informações publicadas no portal atendam às exigências da CVM e da ANBIMA.</p>
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

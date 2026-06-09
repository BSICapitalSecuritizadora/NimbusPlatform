<?php $__env->startSection('title', 'Monitoramento Regulatório — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/compliance.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Serviços</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Monitoramento</span> <br>Regulatório
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Nossa frente de monitoramento regulatório protege sua operação do onboarding ao pós-emissão. Combinamos processos ágeis de verificação (KYC/PLD) com um acompanhamento rigoroso de obrigações, garantindo segurança jurídica e transparência para todos os envolvidos.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Falar com um Especialista
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="<?php echo e(route('site.emissions')); ?>" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="<?php echo e(asset('images/compliance.png')); ?>" class="img-fluid" alt="Monitoramento regulatório" style="width: 100%; height: 500px; object-fit: cover;">
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

<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Vigilância ativa ao longo da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Mantemos a integridade do seu negócio através de uma governança sólida. Nossas práticas são desenhadas para atender as exigências da CVM e ANBIMA de forma prática e eficiente.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Prevenção e Gestão de Riscos</h3>
                    <p class="text-muted mb-0">Conhecemos profundamente quem caminha conosco. Nossas análises de parceiros e clientes (KYC/KYP) são detalhadas para afastar riscos reputacionais e garantir conformidade total com as normas do COAF e CVM.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Vigilância de Covenants</h3>
                    <p class="text-muted mb-0">Monitoramento contínuo das obrigações contratuais: acompanhamos cada cláusula de perto, emitindo alertas preventivos para que eventuais desvios sejam corrigidos rapidamente, mantendo a saúde da operação.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7m0-18H5a2 2 0 0 1-2 2v14a2 2 0 0 1 2 2h7m0-18v18"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Independência Institucional</h3>
                    <p class="text-muted mb-0">Decisões isentas e focadas na segurança. Nosso comitê de crédito atua com total autonomia em relação à área comercial, garantindo que cada risco seja avaliado de forma técnica e transparente.</p>
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
                <p class="text-muted small mb-4">Unimos tecnologia e inteligência para acelerar seu processo de entrada. Nosso sistema se conecta em tempo real com as bases de dados mais confiáveis do mercado, garantindo um onboarding rápido, seguro e sem burocracias desnecessárias.</p>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark smaller mb-1">Bases Públicas</div>
                            <div class="text-muted smaller">Receita Federal, COAF e Tribunais.</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark smaller mb-1">Listas Restritivas</div>
                            <div class="text-muted smaller">OFAC, ONU e PEPs internacionais.</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark smaller mb-1">Crédito & Fraude</div>
                            <div class="text-muted smaller">Serasa Experian e Bureau de Crédito.</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark smaller mb-1">Reputação & ESG</div>
                            <div class="text-muted smaller">Monitoramento reputacional e ambiental.</div>
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
                        <a href="#" class="d-flex justify-content-between align-items-center p-2 bg-light rounded text-decoration-none border-hover transition-all">
                            <span class="smaller fw-medium text-dark">Código de Ética e Conduta</span>
                            <span class="badge bg-gold-subtle text-gold">PDF</span>
                        </a>
                        <a href="#" class="d-flex justify-content-between align-items-center p-2 bg-light rounded text-decoration-none border-hover transition-all">
                            <span class="smaller fw-medium text-dark">Política de PLD/FTP</span>
                            <span class="badge bg-gold-subtle text-gold">PDF</span>
                        </a>
                        <a href="<?php echo e(route('site.governance')); ?>" class="smaller text-brand fw-bold mt-2 d-inline-block">Ver todos os documentos →</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="bg-white p-4 rounded-4 shadow-sm border border-brand-subtle overflow-hidden">
                    <div class="text-center mb-4">
                        <span class="badge bg-gold-subtle text-gold px-3 py-1 rounded-pill smaller fw-bold">Fluxo Industrial de Risco</span>
                    </div>
                    <h3 class="h5 fw-bold text-dark mb-4 text-center">Nossa Esteira de Aprovação</h3>
                    <div class="d-flex flex-column gap-3 position-relative">
                        <div class="position-absolute h-100 border-start border-2 border-dashed" style="left: 20px; top: 0; opacity: 0.2;"></div>
                        
                        <div class="d-flex align-items-center gap-3 position-relative z-1">
                            <div class="text-white rounded-circle d-flex align-items-center justify-content-center fw-bold small shadow-sm" style="background-color: var(--brand, #091b23); width: 40px; height: 40px; min-width: 40px;">1</div>
                            <div class="flex-grow-1 p-2 bg-light rounded-3 border">
                                <div class="fw-bold smaller" style="color: var(--brand, #091b23);">Check-up Digital</div>
                                <div class="smaller text-muted">Consultas instantâneas em mais de 50 bases para uma visão clara de riscos.</div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3 position-relative z-1">
                            <div class="text-white rounded-circle d-flex align-items-center justify-content-center fw-bold small shadow-sm" style="background-color: var(--brand, #091b23); width: 40px; height: 40px; min-width: 40px;">2</div>
                            <div class="flex-grow-1 p-2 bg-light rounded-3 border">
                                <div class="fw-bold smaller" style="color: var(--brand, #091b23);">Olhar Especialista</div>
                                <div class="smaller text-muted">Nossa equipe mergulha nos detalhes com uma análise técnica e criteriosa.</div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3 position-relative z-1">
                            <div class="text-white rounded-circle d-flex align-items-center justify-content-center fw-bold small shadow-sm" style="background-color: var(--gold, #d4af37); width: 40px; height: 40px; min-width: 40px;">3</div>
                            <div class="flex-grow-1 p-3 rounded-3 border shadow-xs" style="background-color: rgba(212,175,55,0.05); border-color: rgba(212,175,55,0.2) !important;">
                                <div class="fw-bold small" style="color: var(--gold, #d4af37);">Decisão Final Independente</div>
                                <div class="smaller text-muted">Um comitê autônomo avalia o projeto, garantindo imparcialidade total.</div>
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
                            Quando um covenant, prazo regulatório ou documento crítico sai do trilho, nosso time registra a ocorrência, define responsáveis e preserva a trilha auditável até a regularização. O objetivo aqui é acelerar a resposta operacional com clareza para emissores, investidores e agentes fiduciários.
                        </p>
                        <p class="text-muted mb-4">
                            Temas institucionais, canal de integridade e diretrizes éticas da BSI continuam concentrados na página institucional de Compliance, evitando ambiguidades na navegação do site.
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-dark btn-sm px-4 py-2">Falar com especialistas</a>
                            <a href="<?php echo e(route('site.compliance')); ?>" class="btn btn-outline-dark btn-sm px-4 py-2">Compliance institucional</a>
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
                <h2 class="h3 fw-bold text-dark mb-4">Monitoramento contínuo: do alerta à solução</h2>
                <p class="text-muted mb-4 lead">
                    O monitoramento não para após a emissão. Atuamos de forma proativa para que cada detalhe da operação seja respeitado. Se algo sair do planejado, nossos protocolos de ação rápida garantem que investidores e reguladores sejam informados com total clareza e agilidade.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acompanhamento atento de cada cláusula contratual e financeira, com alertas automáticos para evitar qualquer desvio.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Resposta rápida em caso de desvios, com notificação imediata aos parceiros e suporte total nas assembleias de titulares.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Reportes rigorosos aos órgãos reguladores (COAF, CVM), garantindo que todas as obrigações legais sejam cumpridas no prazo.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Trabalho conjunto com auditorias renomadas (Big Four) para validar o lastro e a governança de cada operação.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/compliance.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
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
                <a href="<?php echo e(route('site.servicos.relatorios')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Relatórios e Monitoramento</h3>
                    <p class="text-muted mb-3">Acompanhe cada detalhe da sua carteira. Geramos relatórios periódicos e transparentes sobre inadimplência, covenants e eventos de crédito, mantendo investidores e agentes fiduciários sempre bem informados.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.auditoria-acessos')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
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

<?php $__env->startPush('head'); ?>
<style>
    @keyframes float {
        0% { transform: translateY(0px); }
        50% { transform: translateY(-10px); }
        100% { transform: translateY(0px); }
    }
</style>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/servicos/compliance.blade.php ENDPATH**/ ?>
<?php $__env->startSection('title', 'Auditoria de Acessos — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/auditoria_acessos.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Tecnologia</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Rastreabilidade e <br><span style="color: var(--gold);">Trilha de Auditoria</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Registramos cada acesso a documentos por operação, download, alteração de permissão e sessão de usuário — gerando uma trilha imutável que suporta auditorias externas, fiscalização pela CVM e verificações de compliance interno.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Consultar Especialista
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
                        <img src="<?php echo e(asset('images/auditoria_acessos.png')); ?>" class="img-fluid" alt="Auditoria de Acessos" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Trilha imutável</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Log por operação</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Governança e evidência de acesso</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossa trilha de auditoria fornece a segurança necessária para a governança corporativa e a verificação de eventos críticos em tempo real, com rastreabilidade completa de cada interação na plataforma.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Monitoramento Fiduciário</h3>
                    <p class="text-muted mb-0">Registramos em tempo real cada acesso, visualização de documento e download — com timestamp, identificação do usuário e contexto operacional —, gerando um histórico completo e imutável por operação.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Prontidão para Auditoria</h3>
                    <p class="text-muted mb-0">Geração automática de evidências estruturadas para processos de auditoria externa e atendimento às demandas da CVM, da ANBIMA e da Receita Federal — exportáveis com hash de integridade para validação de imutabilidade.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Integridade da Custódia</h3>
                    <p class="text-muted mb-0">Logs armazenados com backup redundante, retenção mínima conforme exigido pela CVM para operações de mercado de capitais e verificação periódica de integridade — garantindo que o histórico da emissão permaneça blindado e auditável.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Visualização de Log e Monitoramento -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5 align-items-center">
            <div class="col-lg-7">
                <div class="rounded-4 p-4 shadow-sm border overflow-hidden position-relative" style="background-color: #eae8e8; border-color: rgba(0,0,0,0.05) !important;">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="d-flex gap-2">
                            <div class="bg-danger rounded-circle" style="width: 8px; height: 8px;"></div>
                            <div class="bg-warning rounded-circle" style="width: 8px; height: 8px;"></div>
                            <div class="bg-success rounded-circle" style="width: 8px; height: 8px;"></div>
                        </div>
                        <span class="small fw-mono fw-bold" style="font-family: monospace; color: color-mix(in srgb, var(--brand) 60%, transparent);">audit_log_v2.stream</span>
                    </div>
                    
                    <div class="d-flex flex-column gap-3" style="font-family: monospace;">
                        <div class="small p-2 ps-3 border-start border-3" style="border-color: #10b981 !important; border-radius: 6px; color: var(--brand); font-weight: 600;">
                            <span class="fw-bold opacity-100">10:42:01</span> <span class="opacity-75">[INFO] User_ID: 104 [Investidor] - Visualizou 'Escritura_CRI_01.pdf' - IP: 187.45.xx</span>
                        </div>
                        <div class="small p-2 ps-3 border-start border-3" style="border-color: #10b981 !important; border-radius: 6px; color: var(--brand); font-weight: 600;">
                            <span class="fw-bold opacity-100">11:15:22</span> <span class="opacity-75">[INFO] User_ID: 088 [Ag. Fiduciário] - Download 'Relatório_Mensal_Set.zip' - IP: 200.18.xx</span>
                        </div>
                        <div class="small p-2 ps-3 border-start border-3 rounded-end shadow-sm" style="border-color: #f59e0b !important; border-radius: 6px; background-color: rgba(212, 175, 55, 0.15); color: var(--brand); font-weight: 700;">
                            <span class="opacity-100">14:05:10 [ALERT] User_ID: 142 [Outros] - Tentativa de acesso bloqueada (ACL Violation) - IP: 45.12.xx</span>
                        </div>
                        <div class="small p-2 ps-3 border-start border-3" style="border-color: #10b981 !important; border-radius: 6px; color: var(--brand); font-weight: 600;">
                            <span class="fw-bold opacity-100">16:30:45</span> <span class="opacity-75">[INFO] Auditor_ID: 002 [BigFour] - Exportação de trilha de auditoria (Hash: 8f2d...b1)</span>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-3 d-flex justify-content-between align-items-center" style="border-top: 1px solid rgba(0,0,0,0.05);">
                        <div class="small fw-bold" style="color: var(--brand);">Integridade verificada (SHA-256)</div>
                        <span class="badge rounded-pill fw-bold" style="background-color: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981; padding: 0.4rem 0.8rem; letter-spacing: 0.05em;">IMUTÁVEL</span>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-5">
                <h2 class="h4 fw-bold text-dark mb-4">Monitoramento e Vigilância</h2>
                <div class="d-flex flex-column gap-3">
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Vigilância Proativa</div>
                        <div class="text-muted smaller">Algoritmos que detectam comportamentos anômalos e notificam o compliance instantaneamente.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Audit-Ready 24/7</div>
                        <div class="text-muted smaller">Acesso direto para auditores externos, reduzindo o tempo de resposta em due diligences e fiscalizações.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Prova de Integridade</div>
                        <div class="text-muted smaller">Uso de criptografia para garantir que nenhum registro de log possa ser alterado ou removido.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Como a trilha é utilizada na prática -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Como a Trilha de Auditoria é Utilizada na Prática</h2>
                <p class="text-muted mb-4 lead">
                    O registro automático de cada interação só tem valor quando pode ser consultado, exportado e apresentado com precisão. A BSI Capital estrutura o acesso à trilha de forma que compliance, auditores e reguladores possam extrair evidências sem fricção operacional.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Extração de logs por período, por usuário ou por operação específica — disponível para compliance interno, agente fiduciário e auditores externos mediante solicitação formal.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Registros exportáveis em formato estruturado (CSV e PDF auditado) com hash de integridade — permitindo validação independente da imutabilidade dos dados apresentados.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento proativo com alertas em tempo real para o compliance em caso de acessos anômalos, tentativas de download em massa ou sessões de locais não autorizados.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Perfil dedicado de "Auditor Independente" para consulta autônoma e segura da trilha de auditoria, eliminando a burocracia na troca de arquivos durante auditorias digitais.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Retenção dos logs pelo prazo mínimo exigido pela regulação vigente para operações de mercado de capitais, com verificação periódica de integridade e backup em infraestrutura redundante.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/auditoria_acessos.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
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
                <a href="<?php echo e(route('site.servicos.documentos-acl')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Documentos com ACL</h3>
                    <p class="text-muted mb-3">Controle granular de permissões por perfil e operação — cada acesso registrado na trilha de auditoria parte das regras definidas nas ACLs configuradas.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.compliance')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Compliance</h3>
                    <p class="text-muted mb-3">A área de compliance consome a trilha de auditoria para vigilância regulatória — detectando padrões de acesso anômalos e suportando investigações internas.</p>
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

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/servicos/auditoria-acessos.blade.php ENDPATH**/ ?>
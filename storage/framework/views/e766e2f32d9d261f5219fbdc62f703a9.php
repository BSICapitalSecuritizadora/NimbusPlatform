<?php $__env->startSection('title', 'Documentos com ACL — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/documentos_acl.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Tecnologia</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Documentos <br>com <span style="color: var(--gold);">ACL</span>
                </h1>
                <p class="h5 fw-medium mb-4" style="color: var(--gold); letter-spacing: 0.05em;">COFRE DIGITAL E DATA ROOM SEGURO</p>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Permissões granulares por perfil — agente fiduciário, investidor qualificado ou distribuidor — com controle de versão de documentos e log de acesso por operação, garantindo que cada parte visualize apenas os materiais autorizados para sua função.
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
                        <img src="<?php echo e(asset('images/documentos_acl.png')); ?>" class="img-fluid" alt="Documentos com ACL" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Acesso controlado</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Por perfil e operação</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Governança documental e sigilo operacional</h2>
            <p class="text-muted mx-auto" style="max-width: 640px;">ACL (Access Control List) — controle granular de quem pode ver o quê. Nossa tecnologia garante a segregação de acessos e a rastreabilidade total, com aderência às regras operacionais, regulatórias e de proteção de dados.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Segregação por Operação</h3>
                    <p class="text-muted mb-0">Gestão granular de permissões por série, tipo de investidor ou função — agente fiduciário, distribuidor, escriturador —, assegurando que informações sensíveis permaneçam restritas aos perfis autorizados para cada emissão.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Rastreabilidade de Custódia</h3>
                    <p class="text-muted mb-0">Cada interação no repositório gera registros auditáveis com timestamp — quem acessou, qual documento, em que momento —, permitindo o acompanhamento rigoroso de toda a trilha de custódia da operação.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Blindagem Informacional</h3>
                    <p class="text-muted mb-0">Infraestrutura desenhada para o cumprimento integral da LGPD e das normas de confidencialidade da CVM — impedindo que dados de uma operação sejam visíveis fora do escopo de acesso autorizado.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Visualização de Controle de Acesso -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5">
                <h2 class="h4 fw-bold text-dark mb-4">Arquitetura de Visibilidade</h2>
                <p class="text-muted small mb-4">Um único repositório, múltiplas visões. Nossa tecnologia de ACL garante que cada stakeholder acesse exatamente o que sua função exige, protegendo o sigilo estratégico da operação.</p>
                
                <div class="d-flex flex-column gap-3">
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Agente Fiduciário</div>
                        <div class="text-muted smaller">Acesso total a documentos de lastro, seguros e garantias para monitoramento de covenants.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Investidor</div>
                        <div class="text-muted smaller">Acesso a relatórios de performance, fatos relevantes e demonstrações financeiras da série.</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-7">
                <div class="bg-white p-4 rounded-4 shadow-sm border border-brand-subtle">
                    <div class="text-center mb-4">
                        <span class="badge bg-light text-dark border px-3 py-1 rounded-pill smaller fw-bold">Simulação de Repositório ACL</span>
                    </div>
                    
                    <div class="row g-3">
                        <!-- Perfil 1 -->
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 border bg-light h-100">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <div class="bg-brand text-white rounded-circle p-1"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
                                    <span class="smaller fw-bold text-brand">Agente Fiduciário</span>
                                </div>
                                <div class="d-flex flex-column gap-2">
                                    <div class="d-flex align-items-center gap-2 smaller text-dark bg-white p-2 rounded border shadow-xs">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg>
                                        Escritura e Aditamentos
                                    </div>
                                    <div class="d-flex align-items-center gap-2 smaller text-dark bg-white p-2 rounded border shadow-xs text-brand fw-bold">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg>
                                        Dossiê de Garantias
                                    </div>
                                    <div class="d-flex align-items-center gap-2 smaller text-muted opacity-50 p-2">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        Dados Sensíveis Sacados
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Perfil 2 -->
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 border bg-light h-100">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <div class="bg-gold text-white rounded-circle p-1"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
                                    <span class="smaller fw-bold text-dark">Investidor Qualificado</span>
                                </div>
                                <div class="d-flex flex-column gap-2">
                                    <div class="d-flex align-items-center gap-2 smaller text-dark bg-white p-2 rounded border shadow-xs">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg>
                                        Relatórios de Performance
                                    </div>
                                    <div class="d-flex align-items-center gap-2 smaller text-dark bg-white p-2 rounded border shadow-xs">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg>
                                        Fatos Relevantes
                                    </div>
                                    <div class="d-flex align-items-center gap-2 smaller text-muted opacity-50 p-2">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        Contratos Sociais Emissor
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

<!-- Gestão de permissões na prática -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Como as Permissões São Geridas na Prática</h2>
                <p class="text-muted mb-4 lead">
                    O controle de acesso não é uma configuração estática — ele evolui com a operação. Cada alteração de mandato, substituição de agente ou decisão de compliance gera um ciclo formal de revisão, aprovação e registro.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Configuração de perfis de acesso no momento da estruturação — agente fiduciário, investidor qualificado, distribuidor, escriturador — com revisão a cada evento contratual relevante.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Data Room seguro desde o "Dia Zero": suporte à troca de minutas, documentos de lastro e due diligence jurídica entre advogados e emissores durante toda a fase de estruturação (Closing).</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Revogação imediata por término de mandato, substituição de agente ou decisão de compliance — com registro auditável da alteração e confirmação às partes afetadas.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Fluxo formal de aprovação de novos acessos: solicitação, validação pelo compliance, ativação documentada e notificação ao titular — rastreabilidade completa de cada concessão.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/documentos_acl.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Serviços relacionados -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Serviços relacionados</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">O controle de acesso documental conecta-se diretamente à auditoria de acessos e ao portal onde as permissões têm efeito para o investidor.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.auditoria-acessos')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Auditoria de Acessos</h3>
                    <p class="text-muted mb-3">Rastreabilidade completa dos acessos ao ambiente operacional — log de ações, revisão de permissões e conformidade com as políticas de segregação definidas no compliance.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.portal-investidor')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Portal do Investidor</h3>
                    <p class="text-muted mb-3">Ambiente onde as permissões de ACL têm efeito — cada investidor visualiza apenas as operações e documentos autorizados para o seu perfil de acesso.</p>
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

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/servicos/documentos-acl.blade.php ENDPATH**/ ?>
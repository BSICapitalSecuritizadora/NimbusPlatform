<?php $__env->startSection('title', 'Integrações — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/integracoes.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Tecnologia</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Integrações</span> <br>& APIs
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Conectamos a plataforma da BSI Capital ao ecossistema de mercado de capitais — B3, escrituradores, custodiantes e ERPs de emissores — por meio de APIs REST com autenticação OAuth 2.0, eliminando reprocessamento manual e garantindo consistência de lastro em tempo real.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Consultar Especialista
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="#" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
                        Portal Developer
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="<?php echo e(asset('images/integracoes.png')); ?>" class="img-fluid" alt="Integrações & APIs" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">API nativa</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">B3 · Escriturador · ERP</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Conectividade nativa com o ecossistema de mercado de capitais</h2>
            <p class="text-muted mx-auto" style="max-width: 640px;">A interoperabilidade entre sistemas elimina retrabalho, reduz risco operacional e garante que os dados que sustentam cada operação estejam sincronizados entre todas as partes.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Conexão com Ecossistema</h3>
                    <p class="text-muted mb-0">Integração direta com B3, escrituradores e custodiantes — sincronizando registros de emissão, movimentações de custódia e eventos de pagamento sem intervenção manual e com confirmação de liquidação em tempo real.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Monitoramento Dinâmico</h3>
                    <p class="text-muted mb-0">Captura de indicadores de performance via webhooks em tempo real — inadimplência, substituições de lastro e eventos de covenant — com disparo automático de alertas quando gatilhos contratuais são atingidos.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Arquitetura Aberta</h3>
                    <p class="text-muted mb-0">APIs REST documentadas com autenticação OAuth 2.0 e escopos granulares por operação — permitindo que ERPs de emissores, plataformas de cobrança e sistemas de gestão de garantias se conectem com segurança e rastreabilidade.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Arquitetura e Ecossistema -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5 align-items-center text-center text-lg-start">
            <div class="col-lg-5">
                <h2 class="h4 fw-bold text-dark mb-4">Arquitetura de Conectividade</h2>
                <p class="text-muted small mb-4">Atuamos como o centro de inteligência de dados, conectando todas as pontas da operação de forma fluida e segura.</p>
                
                <div class="bg-white p-4 rounded-4 shadow-sm border border-brand-subtle mb-4">
                    <div class="fw-bold smaller text-brand mb-3">Conectividade com ERPs de Mercado:</div>
                    <div class="row g-3">
                        <div class="col-4"><div class="smaller border rounded p-2 text-center text-muted">SAP</div></div>
                        <div class="col-4"><div class="smaller border rounded p-2 text-center text-muted">TOTVS</div></div>
                        <div class="col-4"><div class="smaller border rounded p-2 text-center text-muted">Sienge</div></div>
                        <div class="col-4"><div class="smaller border rounded p-2 text-center text-muted">Mega</div></div>
                        <div class="col-4"><div class="smaller border rounded p-2 text-center text-muted">Sankhya</div></div>
                        <div class="col-4"><div class="smaller border rounded p-2 text-center text-muted">+ Outros</div></div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-7">
                <div class="p-4 bg-white rounded-4 shadow-sm border border-brand-subtle position-relative overflow-hidden">
                    <div class="text-center mb-4">
                        <span class="badge bg-light text-dark border px-3 py-1 rounded-pill smaller fw-bold">Mapa de Fluxo de Dados</span>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <!-- Originador -->
                        <div class="text-center" style="width: 25%;">
                            <div class="p-3 border rounded-3 bg-light mb-2 shadow-xs">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
                                <div class="smaller fw-bold text-dark mt-1">Originador</div>
                            </div>
                            <div class="smaller text-muted opacity-75">ERP / CRM</div>
                        </div>

                        <div class="flex-grow-1 position-relative">
                            <div class="border-top border-2 border-dashed opacity-25 w-100"></div>
                            <div class="position-absolute top-50 start-50 translate-middle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3"><polyline points="13 17 18 12 13 7"></polyline><polyline points="6 17 11 12 6 7"></polyline></svg>
                            </div>
                        </div>

                        <!-- BSI Hub -->
                        <div class="text-center" style="width: 30%;">
                            <div class="p-3 border-gold rounded-3 bg-brand text-white mb-2 shadow-md border-2" style="background: var(--brand-strong);">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                <div class="smaller fw-bold mt-1">BSI API HUB</div>
                            </div>
                            <div class="smaller text-gold fw-bold">Validação & Registro</div>
                        </div>

                        <div class="flex-grow-1 position-relative">
                            <div class="border-top border-2 border-dashed opacity-25 w-100"></div>
                            <div class="position-absolute top-50 start-50 translate-middle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3"><polyline points="13 17 18 12 13 7"></polyline><polyline points="6 17 11 12 6 7"></polyline></svg>
                            </div>
                        </div>

                        <!-- Mercado -->
                        <div class="text-center" style="width: 25%;">
                            <div class="p-3 border rounded-3 bg-light mb-2 shadow-xs">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                                <div class="smaller fw-bold text-dark mt-1">Mercado</div>
                            </div>
                            <div class="smaller text-muted opacity-75">B3 / Custodiantes</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Como a integração funciona na prática -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Como a Integração Funciona na Prática</h2>
                <p class="text-muted mb-4 lead">
                    Cada nova integração passa por um processo estruturado de homologação antes de entrar em produção — garantindo que os fluxos de dados entre sistemas sejam validados, rastreáveis e auditáveis desde o primeiro evento.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Onboarding em ambiente de sandbox com dados sintéticos — validação completa dos fluxos de envio, recebimento e reconciliação antes da ativação em produção.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Webhooks configurados por evento crítico — liquidação financeira, vencimento de parcela, pagamento de juros e substituição de lastro — com retry automático e log de entrega auditável.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Credenciais OAuth 2.0 com escopos por operação e revogação imediata — cada sistema parceiro acessa apenas os dados autorizados para a emissão específica à qual está vinculado.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/integracoes.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Serviços relacionados -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Serviços relacionados</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">As integrações alimentam a trilha de auditoria e operam sob as permissões de acesso definidas nas ACLs documentais.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.auditoria-acessos')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Auditoria de Acessos</h3>
                    <p class="text-muted mb-3">Cada chamada de API e evento de integração é registrado na trilha de auditoria — com timestamp, origem e contexto operacional —, garantindo rastreabilidade completa dos fluxos automatizados.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.documentos-acl')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Documentos com ACL</h3>
                    <p class="text-muted mb-3">As permissões de acesso configuradas nas ACLs definem quais sistemas integrados podem consultar e receber documentos de cada operação — controle granular por perfil e emissão.</p>
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

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/servicos/integracoes.blade.php ENDPATH**/ ?>
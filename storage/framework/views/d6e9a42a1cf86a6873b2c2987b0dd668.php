<?php $__env->startSection('title', 'Portal do Investidor — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/portal_investidor.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Serviços</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Portal do <span style="color: var(--gold);">Investidor</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Ambiente centralizado para acompanhar posição de PU, cronogramas de amortização, histórico de Fatos Relevantes e documentos fiduciários — com acesso seguro, segregado por operação e disponível 24 horas.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(config('services.portal.url')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Acessar o Portal
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Falar com nossa equipe
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="<?php echo e(asset('images/portal_investidor.png')); ?>" class="img-fluid" alt="Portal do Investidor" style="width: 100%; height: 500px; object-fit: cover;">
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
            <h2 class="h3 fw-bold text-dark mb-3">Transparência ativa e controle total sobre suas posições</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Consolidamos os dados estratégicos da operação em uma interface segura e organizada, alinhada às rotinas de acompanhamento do investidor institucional.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Segurança e Privacidade</h3>
                    <p class="text-muted mb-0">Autenticação multifator, criptografia de ponta e segregação rigorosa de acessos por operação — com conformidade total à LGPD e às exigências de confidencialidade da CVM.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Repositório Estratégico</h3>
                    <p class="text-muted mb-0">Acesso imediato a relatórios fiduciários, Fatos Relevantes, escrituras, adendos e trilhas de auditoria — toda a documentação da operação em um histórico rastreável e organizado por emissão.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Inteligência de Ativos</h3>
                    <p class="text-muted mb-0">Acompanhe a evolução do Preço Unitário (PU), cronogramas de amortização, rentabilidade acumulada e eventos de pagamento futuros com detalhamento financeiro completo por série e emissão.</p>
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
                <h2 class="h4 fw-bold text-dark mb-4">Conectividade e Integração B2B</h2>
                <p class="text-muted small mb-4">Entendemos que a agilidade institucional exige dados fluidos. Além do portal web, oferecemos infraestrutura para consumo de dados via API, facilitando a vida de Assets e administradores.</p>
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
                        <span class="badge bg-light text-dark border px-3 py-1 rounded-pill smaller fw-bold">Dashboard Visual</span>
                    </div>
                    <div class="row g-3">
                        <div class="col-7">
                            <div class="bg-light rounded-3 p-3 mb-3" style="height: 120px;">
                                <div class="w-25 h-2 bg-brand opacity-10 rounded mb-2"></div>
                                <div class="w-75 h-4 bg-brand opacity-20 rounded mb-4"></div>
                                <div class="d-flex gap-2 align-items-end" style="height: 40px;">
                                    <div class="flex-grow-1 bg-gold opacity-50 rounded-top" style="height: 40%;"></div>
                                    <div class="flex-grow-1 bg-gold opacity-50 rounded-top" style="height: 70%;"></div>
                                    <div class="flex-grow-1 bg-gold opacity-50 rounded-top" style="height: 55%;"></div>
                                    <div class="flex-grow-1 bg-gold opacity-50 rounded-top" style="height: 90%;"></div>
                                </div>
                            </div>
                            <div class="bg-light rounded-3 p-3" style="height: 100px;">
                                <div class="w-50 h-2 bg-brand opacity-10 rounded mb-2"></div>
                                <div class="row g-2">
                                    <div class="col-6"><div class="h-8 bg-white rounded shadow-xs"></div></div>
                                    <div class="col-6"><div class="h-8 bg-white rounded shadow-xs"></div></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-5">
                            <div class="bg-brand-subtle rounded-3 p-3 h-100 border border-brand-subtle">
                                <div class="w-75 h-2 bg-brand opacity-20 rounded mb-3"></div>
                                <div class="d-flex flex-column gap-2">
                                    <div class="h-2 bg-white rounded"></div>
                                    <div class="h-2 bg-white rounded"></div>
                                    <div class="h-2 bg-white rounded"></div>
                                    <div class="h-2 bg-white rounded w-50"></div>
                                </div>
                                <div class="mt-4 pt-4 border-top border-white opacity-50">
                                    <div class="h-8 bg-white rounded-pill"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background: rgba(255,255,255,0.05); backdrop-filter: blur(1px);">
                        <span class="small fw-bold text-brand px-3 py-2 bg-white rounded-pill shadow-sm border">Ambiente Proprietário</span>
                    </div>
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
                <h2 class="h3 fw-bold text-dark mb-4">O que o Investidor Encontra ao Acessar o Portal</h2>
                <p class="text-muted mb-4 lead">
                    O portal não é apenas um repositório — é um ambiente de acompanhamento ativo. Cada módulo é estruturado para reduzir o tempo entre o evento operacional e a informação disponível ao investidor.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Painel de posições por emissão com PU atualizado, saldo devedor e próximos eventos de pagamento — amortização, juros e atualização monetária — organizados por data.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Notificações automáticas de Fatos Relevantes, publicação de relatórios periódicos e convocações de assembleias, com histórico completo de comunicados por operação.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acesso aos documentos da operação com controle de versões — escrituras, adendos e termos de cessão — organizados cronologicamente e disponíveis para download.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Interface dedicada para Agentes Fiduciários, com painel exclusivo para monitoramento de covenants, gatilhos financeiros e status de garantias em tempo real.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/portal_investidor.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
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
                <a href="<?php echo e(route('site.servicos.relatorios')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Relatórios e Monitoramento</h3>
                    <p class="text-muted mb-3">Produção dos relatórios periódicos para investidores e agente fiduciário que alimentam os dados exibidos no portal.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.compliance')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
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

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/servicos/portal-investidor.blade.php ENDPATH**/ ?>
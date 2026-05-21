<?php $__env->startSection('title', 'CRI e Real Estate — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/cri_real_estate.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>
    
    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Imobiliário</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Securitização <span style="color: var(--gold);">Imobiliária</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Expertise integral na estruturação e gestão de CRI, unindo segurança jurídica, monitoramento rigoroso do lastro e governança ativa da carteira.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Falar com nossa equipe
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="<?php echo e(route('site.emissions')); ?>?type=CRI" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões
                    </a>
                </div>
            </div>
            
            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <!-- Image Card -->
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="<?php echo e(asset('images/cri_real_estate.png')); ?>" class="img-fluid" alt="CRI Real Estate" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <!-- Floating Data Box -->
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle text-primary" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Crédito estruturado</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Execução com controle</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Inteligência técnica em cada fase da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Estruturamos e gerimos o CRI com governança ativa, documentação controlada e fluxo informacional contínuo entre todas as partes.</p>
        </div>

        <div class="row g-4">
            <!-- Diferencial 1 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Segurança Jurídica e Colaterais</h3>
                    <p class="text-muted mb-0">Rigor na formalização de garantias reais e cessão fiduciária, desenhando veículos de securitização com conformidade normativa absoluta.</p>
                </div>
            </div>
            
            <!-- Diferencial 2 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Monitoramento e Diligência</h3>
                    <p class="text-muted mb-0">Diligência contínua do lastro e dos covenants financeiros, com prontidão no reporte de eventos de crédito ao mercado.</p>
                </div>
            </div>

            <!-- Diferencial 3 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Engenharia Financeira</h3>
                    <p class="text-muted mb-0">Modelagem de fluxos de caixa complexos com indexadores moldados à natureza do ativo e ao perfil estratégico da carteira.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Emissões em destaque -->
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($featuredEmissions) && $featuredEmissions->isNotEmpty()): ?>
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-5">
            <div>
                <h2 class="h3 fw-bold text-dark mb-2">Emissões de CRI Estruturadas</h2>
                <p class="text-muted mb-0">Operações públicas disponíveis para consulta técnica detalhada.</p>
            </div>
            <a href="<?php echo e(route('site.emissions')); ?>?type=CRI" class="btn btn-outline-brand btn-sm px-4 flex-shrink-0">Ver todas as emissões</a>
        </div>

        <div class="row g-4">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $featuredEmissions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 border-0 shadow-sm emission-card overflow-hidden">
                        <div style="height: 4px; background: linear-gradient(90deg, var(--brand), var(--gold), var(--brand));"></div>
                        <div class="card-body p-3 p-lg-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div class="flex-grow-1">
                                    <div class="small text-uppercase text-muted fw-semibold mb-2"><?php echo e($e->if_code ?? 'CRI'); ?></div>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($e->type): ?>
                                            <span class="badge badge-type-<?php echo e(strtolower($e->type)); ?> px-3 py-2"><?php echo e($e->type); ?></span>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($e->status_label): ?>
                                            <span class="badge badge-status-<?php echo e($e->status); ?> px-3 py-2"><?php echo e($e->status_label); ?></span>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                    <h3 class="h5 fw-bold text-brand mb-0" style="line-height: 1.45; word-wrap: break-word;"><?php echo e($e->name); ?></h3>
                                </div>
                                <div class="d-flex align-items-center justify-content-center flex-shrink-0 p-2" style="width: 64px; height: 64px; border-radius: 14px; background: rgba(0,32,91,0.06); color: var(--brand);">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($e->logo_path): ?>
                                        <img src="<?php echo e(Storage::disk($e->logo_storage_disk)->url($e->logo_path)); ?>" alt="<?php echo e($e->name); ?>" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                                    <?php else: ?>
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1m4 0h1m-5 4h1m4 0h1M9 16h1m4 0h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path></svg>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </div>

                            <div class="row g-2 small">
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Emissão</div>
                                    <div class="fw-semibold"><?php echo e($e->emission_number ?? '—'); ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Série</div>
                                    <div class="fw-semibold"><?php echo e($e->series ?? '—'); ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Data de emissão</div>
                                    <div class="fw-semibold"><?php echo e(optional($e->issue_date)->format('d/m/Y') ?? '—'); ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Vencimento</div>
                                    <div class="fw-semibold"><?php echo e(optional($e->maturity_date)->format('d/m/Y') ?? '—'); ?></div>
                                </div>
                                <div class="col-12">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Remuneração</div>
                                    <div class="fw-semibold"><?php echo e($e->formatted_remuneration ?? '—'); ?></div>
                                </div>
                                <div class="col-12">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Emissor</div>
                                    <div class="fw-semibold"><?php echo e($e->issuer ?? '—'); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 p-3 p-lg-4 pt-0 d-grid">
                            <a href="<?php echo e(route('site.emissions.show', $e->if_code)); ?>" class="btn btn-outline-brand btn-sm w-100">Consultar Operação</a>
                        </div>
                    </div>
                </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<!-- Additional Context or Stats -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Excelência Operacional e Transparência Pós-Fechamento</h2>
                <p class="text-muted mb-4 lead">
                    Asseguramos a perenidade da operação através de uma gestão ativa. Nossa plataforma integra o controle de fluxos de caixa e o monitoramento de contas vinculadas ao cumprimento rigoroso das obrigações da escritura.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Relatórios granulares de desempenho da carteira para investidores e agente fiduciário.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento contínuo de lastro, covenants e eventos de crédito com suporte técnico.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acessos dedicados via Portal do Investidor, com custódia documental e trilha de auditoria.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&q=80&w=1000') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Outros segmentos imobiliários -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Outros Segmentos do Imobiliário</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Atuamos em diferentes frentes do mercado imobiliário com estruturas adaptadas à natureza de cada ativo.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="<?php echo e(route('site.imobiliario.incorporacao')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Incorporação Imobiliária</h3>
                    <p class="text-muted mb-3">Estruturação de CRI lastreados em créditos imobiliários oriundos de projetos de incorporação residencial e comercial.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.imobiliario.loteamentos')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Loteamentos</h3>
                    <p class="text-muted mb-3">Securitização de recebíveis de loteamentos urbanos e fechados com lastro em contratos de promessa de compra e venda.</p>
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

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/imobiliario/cri.blade.php ENDPATH**/ ?>
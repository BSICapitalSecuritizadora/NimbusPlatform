<?php $__env->startSection('title', 'Compliance — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.12; background: url('<?php echo e(asset('images/compliance.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Compliance</span> <br>& Ética Corporativa
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Estrutura ativa de KYC/KYP com reporte ao COAF, conformidade à Instrução CVM 60 e ciclos periódicos de revisão de PLD/FTP — garantindo idoneidade das partes, segregação de funções e proteção de dados conforme a LGPD em todas as operações estruturadas pela BSI Capital.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Falar com Especialista
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
                        <img src="<?php echo e(asset('images/compliance.png')); ?>" class="img-fluid" alt="Compliance & Ética Corporativa" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Compliance ativo</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">KYC · COAF · LGPD</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Selos e Certificações -->
<section class="py-4" style="background: #f8f9fa; border-bottom: 1px solid rgba(0,32,91,0.05);">
    <div class="container">
        <div class="row align-items-center justify-content-center g-4 opacity-75">
            <div class="col-6 col-md-3 text-center">
                <div class="d-flex flex-column align-items-center">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mb-2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <span class="small fw-bold text-uppercase text-dark" style="letter-spacing: 0.05em; font-size: 0.7rem;">Compliance S.A.</span>
                </div>
            </div>
            <div class="col-6 col-md-3 text-center">
                <div class="d-flex flex-column align-items-center">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mb-2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span class="small fw-bold text-uppercase text-dark" style="letter-spacing: 0.05em; font-size: 0.7rem;">Auditoria Ativa</span>
                </div>
            </div>
            <div class="col-6 col-md-3 text-center">
                <div class="d-flex flex-column align-items-center">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mb-2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <span class="small fw-bold text-uppercase text-dark" style="letter-spacing: 0.05em; font-size: 0.7rem;">CVM & ANBIMA</span>
                </div>
            </div>
            <div class="col-6 col-md-3 text-center">
                <div class="d-flex flex-column align-items-center">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mb-2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <span class="small fw-bold text-uppercase text-dark" style="letter-spacing: 0.05em; font-size: 0.7rem;">LGPD Compliant</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Pilares Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Pilares do Nosso Compliance</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">O Programa estrutura-se sobre pilares fundamentais que reforçam a conduta ética, a mitigação de riscos e a conformidade irrestrita aos referenciais normativos.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">PLD / FTP</h3>
                    <p class="text-muted mb-0">Estrutura robusta de Prevenção à Lavagem de Dinheiro e ao Financiamento do Terrorismo, com protocolos rigorosos de diligência e monitoramento.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Código de Conduta</h3>
                    <p class="text-muted mb-0">Estabelece diretrizes de comportamento ético e integridade esperados de colaboradores e parceiros em todas as interações institucionais.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Conformidade CVM</h3>
                    <p class="text-muted mb-0">Aderência irrestrita às Resoluções da CVM, garantindo conformidade normativa em todas as fases de estruturação e gestão de ativos.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Privacidade (LGPD)</h3>
                    <p class="text-muted mb-0">Tratamento de dados sob rigorosos padrões de segurança cibernética e sigilo fiduciário, em estrita observância à LGPD.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Cultura Ética e Código de Conduta -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5">
                <div class="p-5" style="background: var(--brand-strong); border-radius: 24px; box-shadow: 0 20px 40px rgba(0,32,91,0.15);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5" class="mb-4"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <h2 class="h3 fw-bold mb-3" style="color: #fff;">Nosso Código de Ética</h2>
                    <p style="color: #8892b0; line-height: 1.7;">Mais do que um documento, nosso Código de Ética e Conduta é a base da nossa cultura fiduciária. Ele orienta cada decisão e define o padrão de integridade inegociável da BSI Capital.</p>
                    <a href="<?php echo e(route('public-documents')); ?>" class="btn btn-brand mt-3 d-inline-flex align-items-center gap-2">
                        Leitura Completa
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline></svg>
                    </a>
                </div>
            </div>
            <div class="col-lg-7">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91,0.05); letter-spacing: 0.1em; font-weight: 600;">Educação & Prevenção</span>
                <h2 class="h3 fw-bold text-dark mb-4">Treinamento e Engajamento Contínuo</h2>
                <p class="text-muted mb-4">Acreditamos que o compliance eficaz nasce da consciência, não apenas do controle. Por isso, mantemos um calendário permanente de capacitação para todos os nossos colaboradores e parceiros estratégicos.</p>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-brand mt-1">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1" style="font-size: 1rem; color: #0b1220;">Onboarding Ético</h5>
                                <p class="small text-muted mb-0">Todo novo integrante passa por imersão profunda em nossos valores e políticas antes de iniciar suas atividades.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-brand mt-1">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1" style="font-size: 1rem; color: #0b1220;">Ciclos de Reciclagem</h5>
                                <p class="small text-muted mb-0">Treinamentos semestrais sobre PLD/FTP, LGPD e combate à corrupção, adaptados às mudanças regulatórias.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Políticas Section -->
<section class="py-5" style="background: #0b1220;">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold mb-3" style="color: #ffffff;">Políticas e Documentos</h2>
            <p class="mx-auto" style="max-width: 600px; color: #E6E4E4;">Consulte os normativos institucionais que regem nossas diretrizes de governança, conduta ética e conformidade regulatória.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $documents; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $document): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
            <div class="col-md-6 col-lg-4">
                <a href="<?php echo e(route('site.documents.download', $document)); ?>" class="text-decoration-none" download>
                    <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.05); border-radius: 16px; transition: .3s;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            <h4 class="fw-bold mb-0" style="color: #fff; font-size: 1rem;"><?php echo e($document->title); ?></h4>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span style="color: #8892b0; font-size: 0.85rem;">
                                <?php echo e($document->published_at?->format('d/m/Y') ?? $document->created_at->format('d/m/Y')); ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($document->file_size): ?>
                                    · <?php echo e(number_format($document->file_size / 1024, 0)); ?> KB
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </div>
                    </div>
                </a>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            <div class="col-12 text-center py-4">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="1.5" class="mb-3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                <p class="mb-0" style="color: #8892b0;">Os documentos normativos de compliance estarão disponíveis nesta seção após sua publicação oficial.</p>
            </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>
</section>

<!-- Canal de Denúncia -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Canal de Integridade</span>
                <h2 class="h3 fw-bold text-dark mb-3">Canal de Integridade e Denúncia</h2>
                <p class="text-muted mb-4">A BSI Capital disponibiliza um Canal de Integridade para o relato seguro de desvios de conduta, irregularidades ou descumprimento de normas. Asseguramos o **anonimato tecnológico absoluto** (com criptografia de ponta a ponta e sem rastreamento de IP) e mantemos uma política rigorosa de não retaliação ao denunciante de boa-fé.</p>
                <p class="text-muted mb-4">A gestão do canal é pautada por protocolos de independência, com os relatos sendo encaminhados diretamente ao Comitê de Compliance — sem qualquer interferência da área comercial ou de diretores estatutários envolvidos.</p>
                <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand d-inline-flex align-items-center gap-2 px-4 py-2">
                    Reportar ao Comitê
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 p-5" style="background: linear-gradient(135deg, rgba(0,32,91,0.05), rgba(212,175,55,0.05)); border-radius: 20px;">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 52px; height: 52px; background: rgba(0,32,91,0.08); color: var(--brand);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </div>
                        <h4 class="fw-bold mb-0" style="color: var(--brand);">Protocolo de Sigilo</h4>
                    </div>
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span class="text-muted" style="font-size: 0.95rem;"><strong class="text-dark">Recebimento e triagem</strong> — relato registrado com protocolo único e encaminhado ao Comitê de Compliance sem identificação do denunciante.</span>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span class="text-muted" style="font-size: 0.95rem;"><strong class="text-dark">Investigação independente</strong> — apuração conduzida com acesso restrito, segregada da área envolvida e da diretoria comercial.</span>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span class="text-muted" style="font-size: 0.95rem;"><strong class="text-dark">Encerramento formal</strong> — conclusão registrada em trilha auditável, com resposta ao denunciante e medidas corretivas documentadas.</span>
                        </div>
                    </div>
                </div>
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

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/compliance.blade.php ENDPATH**/ ?>
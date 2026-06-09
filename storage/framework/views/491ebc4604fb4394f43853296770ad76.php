<?php $__env->startSection('title', 'CRA | Crédito para o Agronegócio | BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/cra_agronegocio.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Agronegócio</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Securitização do <br><span style="color: var(--gold);">Agronegócio</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Estruturamos CRAs sob medida para a agroindústria, garantindo conformidade com as novas normas regulatórias e total integração ao ciclo do campo.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(route('proposal.create')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Simular Operação
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="<?php echo e(route('site.emissions')); ?>?type=CRA" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Portfólio de CRA
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="<?php echo e(asset('images/cra_agronegocio.png')); ?>" class="img-fluid" alt="CRA - Certificados de Recebíveis do Agronegócio" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Safra</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Liquidez no tempo certo</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- BSI no Campo (Social Proof) -->
<section class="py-5" style="background: linear-gradient(135deg, var(--brand-strong), var(--brand));">
    <div class="container py-4">
        <div class="row g-4 text-center">
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">R$ 1.5Bi+</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Emissões Agro</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">120+</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">CPRs Monitoradas</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">10</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Principais Culturas</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">24h</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Monitoramento Geográfico</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Culturas Atendidas -->
<section class="py-5 bg-white">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Presença em toda a cadeia produtiva</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Atuamos em diversos segmentos, adaptando a estrutura de garantias à realidade biológica de cada cultura.</p>
        </div>
        <div class="row g-4 justify-content-center">
            <?php
                $culturas = [
                    ['icon' => '🌽', 'title' => 'Grãos', 'desc' => 'Soja, milho e trigo com foco em custeio e CPR.'],
                    ['icon' => '🎋', 'title' => 'Sucroenergético', 'desc' => 'Antecipação de recebíveis para usinas e fornecedores.'],
                    ['icon' => '🥩', 'title' => 'Pecuária', 'desc' => 'Operações para cria, recria e engorda com penhor de gado.'],
                    ['icon' => '☕', 'title' => 'Café', 'desc' => 'Estruturas para cooperativas e exportadores.'],
                    ['icon' => '🍊', 'title' => 'Citricultura', 'desc' => 'Financiamento para pomares e agroindústria de sucos.'],
                    ['icon' => '🌲', 'title' => 'Silvicultura', 'desc' => 'Crédito de longo prazo para florestas plantadas.'],
                ];
            ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $culturas; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $cultura): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
            <div class="col-md-4 col-lg-2 text-center">
                <div class="p-4 border rounded-4 h-100 card-hover bg-light">
                    <div class="fs-1 mb-2"><?php echo e($cultura['icon']); ?></div>
                    <h4 class="h6 fw-bold mb-1"><?php echo e($cultura['title']); ?></h4>
                    <p class="small text-muted mb-0" style="line-height: 1.2;"><?php echo e($cultura['desc']); ?></p>
                </div>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
        </div>
    </div>
</section>

<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Inteligência técnica aplicada ao Agro</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Estruturamos o crédito respeitando as janelas de safra e os marcos reais de produção.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Ciclo de Safra</h3>
                    <p class="text-muted mb-0">Fluxos de pagamento alinhados à comercialização da cultura, evitando pressões de caixa fora do período de liquidez.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Diversificação de Funding</h3>
                    <p class="text-muted mb-0">Reduza a dependência bancária. O mercado de capitais oferece taxas competitivas e prazos longos para projetos de expansão.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Controle de CPR</h3>
                    <p class="text-muted mb-0">Gestão rigorosa de Cédulas de Produto Rural e colaterais agrícolas. Garantimos que o lastro esteja seguro e transparente.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Perfil de Atuação e Tíquetes -->
<section class="py-5" style="background-color: #f8f9fa;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Operações Agro</span>
                <h2 class="h3 fw-bold text-dark mb-4">Diretrizes para Estruturação</h2>
                <p class="text-muted mb-4">Atendemos agroindústrias, cooperativas e grandes produtores que buscam escala e eficiência financeira.</p>
                
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">R$ 10MM a R$ 200MM</div>
                            <div class="small text-muted">Tíquete médio por emissão de CRA.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Prazos até 10 anos</div>
                            <div class="small text-muted">Estruturas de longo prazo para investimento e infraestrutura.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Garantias Reais</div>
                            <div class="small text-muted">CPR-F/F, penhor de safra, gado ou hipoteca rural.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Rating Agro</div>
                            <div class="small text-muted">Avaliação focada na solidez produtiva e comercial.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg overflow-hidden" style="border-radius: 24px;">
                    <div class="card-body p-5" style="background: var(--brand);">
                        <h4 class="text-white fw-bold mb-4">Público Atendido</h4>
                        <div class="d-flex flex-column gap-3">
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Agroindústrias com faturamento consolidado.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Cooperativas de produção e crédito.</span>
                            </div>
                            <div class="col-lg-12 text-white">
                                <div class="bg-gold p-2 rounded-circle d-inline-block" style="width: 10px; height: 10px;"></div>
                                <span class="ms-2">Distribuidores de insumos com carteira de recebíveis.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Exportadores de commodities agrícolas.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Monitoramento pós-fechamento -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Gestão ativa da safra</h2>
                <p class="text-muted mb-4 lead">
                    No CRA, o lastro evolui com o campo. Monitoramos cada etapa, garantindo que os eventos de colheita e liquidação sejam refletidos com precisão para os investidores.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Verificação contínua da entrega física e liquidação de CPRs.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acompanhamento de preços e covenants contra oscilações do mercado.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Relatórios periódicos sobre a evolução do lastro e posição das garantias.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/cra_agronegocio.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Leadership Quote -->
<section class="py-5" style="background: var(--bg);">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card border-0 shadow-sm p-4 p-md-5 position-relative overflow-hidden" style="border-radius: 30px; background: white;">
                    <div class="position-absolute top-0 end-0 p-4" style="opacity: 0.05; pointer-events: none;">
                        <svg width="120" height="120" viewBox="0 0 24 24" fill="var(--brand)"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
                    </div>
                    <div class="row align-items-center g-4 position-relative z-1">
                        <div class="col-md-3 text-center">
                            <div class="rounded-circle mx-auto mb-3" style="width: 120px; height: 120px; background: url('<?php echo e(asset('images/avatar-placeholder.png')); ?>') center/cover; border: 4px solid var(--gold-soft);"></div>
                        </div>
                        <div class="col-md-9">
                            <blockquote class="blockquote mb-0">
                                <p class="fs-4 fw-medium text-dark mb-4 italic" style="line-height: 1.6;">
                                    "No Agro, crédito não é apenas sobre números, é sobre tempo. Entendemos que uma CPR mal estruturada pode travar o fluxo de uma usina ou cooperativa. Nosso papel é colocar o mercado de capitais a serviço da agroindústria real, com a agilidade que a janela de safra exige."
                                </p>
                                <footer class="blockquote-footer mt-2">
                                    <span class="fw-bold text-brand fs-5 d-block">Diretoria de Agronegócio</span>
                                    <cite title="BSI Capital" class="small text-muted text-uppercase fw-bold" style="letter-spacing: 0.1em;">BSI Capital Securitizadora</cite>
                                </footer>
                            </blockquote>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Monitoramento e Tecnologia de Campo -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <h2 class="h3 fw-bold text-dark mb-4">Inteligência geográfica no monitoramento</h2>
                <p class="text-muted mb-4">O monitoramento do Agro não é só financeiro. Validamos a saúde do lastro com ferramentas de precisão no campo.</p>
                
                <div class="d-flex flex-column gap-4">
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h7"/><path d="M16 5V3"/><path d="M8 5V3"/><path d="M3 9h18"/><circle cx="18" cy="18" r="3"/><path d="m18 15.5-2 2.5 2 2.5 2-2.5-2-2.5z"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Sensoriamento Remoto</h5>
                            <p class="small text-muted mb-0">Acompanhamento via satélite do desenvolvimento vegetativo para identificar anomalias precoces.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Auditoria de Campo</h5>
                            <p class="small text-muted mb-0">Verificação presencial e documental de estoques em cooperativas e terminais.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Conformidade ESG</h5>
                            <p class="small text-muted mb-0">Monitoramento de embargos ambientais e diretrizes do CRA Verde.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="position-relative p-4 bg-white shadow-sm rounded-4 border">
                    <div class="text-brand fw-bold small text-uppercase mb-3" style="letter-spacing: 0.05em;">Foco Regulatório</div>
                    <h3 class="h4 fw-bold mb-3">CMN 5.118 e a Agroindústria Real</h3>
                    <p class="text-muted">Atuamos estritamente nas novas diretrizes do CMN, focando em operações onde o lastro advém da cadeia produtiva rural efetiva. Estruturamos CRAs para quem forma a base da economia do campo.</p>
                    <div style="height: 2px; width: 60px; background: var(--gold);" class="my-4"></div>
                    <p class="small text-muted mb-0 font-italic">*Suporte na transição para o novo marco regulatório do CRA.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="py-5 bg-white">
    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-4">
                <div class="pe-lg-4">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Dúvidas do Agro</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Inteligência Financeira no Campo</h2>
                    <p class="text-muted mb-4">Esclarecemos os principais pontos sobre estruturação de títulos e gestão de riscos no Agro.</p>
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-sm px-4 py-2">Consultar Estruturação</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqCRA">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Quais instrumentos compõem o lastro de um CRA?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqCRA">
                            <div class="accordion-body px-0 text-muted">
                                O lastro pode ser composto por Cédulas de Produto Rural (CPR), Certificados de Direitos Creditórios do Agronegócio (CDCA), Duplicatas e Notas Promissórias vinculadas a transações comerciais do setor.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Como funciona o CRA Verde?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqCRA">
                            <div class="accordion-body px-0 text-muted">
                                O CRA Verde vincula a emissão a projetos com impacto ambiental positivo. Ele atrai investidores institucionais com mandatos ESG, muitas vezes oferecendo melhores taxas e liquidez.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Qual o papel da BSI na quebra de safra?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqCRA">
                            <div class="accordion-body px-0 text-muted">
                                Atuamos preventivamente via satélite. Em caso de quebra, nossa governança trabalha na repactuação de prazos e acionamento de garantias para preservar o fluxo de pagamento do título.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. Quem pode emitir um CRA após a CMN 5.118?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqCRA">
                            <div class="accordion-body px-0 text-muted">
                                A regra restringiu o lastro a empresas cuja receita principal venha efetivamente do agronegócio, direcionando os recursos para a agroindústria real.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Outros segmentos do agronegócio -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Outros Segmentos do Agronegócio</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Atuamos em toda a cadeia produtiva com estruturas adaptadas ao perfil de cada negócio.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="<?php echo e(route('site.agronegocio.cooperativas')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Cooperativas</h3>
                    <p class="text-muted mb-3">Estruturas adaptadas ao modelo cooperativista e às particularidades do associativismo rural.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.agronegocio.projetos')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Projetos Agro</h3>
                    <p class="text-muted mb-3">Financiamento para expansão rural, armazenagem e logística com lastro em recebíveis.</p>
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

    .custom-accordion .accordion-button:not(.collapsed) {
        box-shadow: none;
        color: var(--brand);
    }

    .custom-accordion .accordion-button:focus {
        box-shadow: none;
    }

    .custom-accordion .accordion-button::after {
        background-size: 1rem;
    }

    .card-hover:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important;
    }
</style>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/agronegocio/cra.blade.php ENDPATH**/ ?>
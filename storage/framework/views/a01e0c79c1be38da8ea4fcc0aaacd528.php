<?php $__env->startSection('title', 'BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<section class="hero position-relative overflow-hidden">
    <video autoplay loop muted playsinline class="position-absolute w-100 h-100 object-fit-cover" style="top: 0; left: 0; z-index: 0; opacity: 0.18; pointer-events: none;">
        <source src="<?php echo e(asset('videos/logo-animacao-bsi.mp4')); ?>" type="video/mp4">
    </video>

    <div class="container py-4 position-relative">
        <div class="row align-items-center g-5">
            <div class="col-xl-7">
                <div class="kicker mb-3">Securitização • Mercado de Capitais • Crédito Estruturado</div>
                <h1 class="display-3 fw-bold mb-4">
                    A securitizadora que fica na operação do início ao fim.
                </h1>
                <p class="lead mb-4" style="max-width: 720px;">
                    Securitização e crédito estruturado com rigor técnico, governança sólida e acompanhamento especializado em todas as etapas: da estruturação ao vencimento final.
                </p>

                <div class="d-grid d-sm-flex gap-3 mb-4">
                    <a href="<?php echo e(route('proposal.create')); ?>" class="btn btn-brand btn-lg px-5">Enviar Proposta</a>
                    <a href="<?php echo e(route('site.emissions')); ?>" class="btn btn-light btn-lg px-5">Ver Emissões</a>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Desde</div>
                            <div class="hero-metric-value fw-bold" style="font-size: 1.5rem">2009</div>
                            <div class="small text-white-50">Presença contínua nos segmentos imobiliário, agrícola e corporativo.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Governança</div>
                            <div class="hero-metric-value fw-bold" style="font-size: 1.5rem">CVM</div>
                            <div class="small text-white-50">Companhia aberta registrada e aderente aos padrões de compliance.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Instrumentos</div>
                            <div class="hero-metric-value fw-bold" style="font-size: 1.5rem; letter-spacing: .04em;">CRI · CRA · CR</div>
                            <div class="small text-white-50">Atuação nos três principais instrumentos de securitização do mercado de capitais.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="surface-card-dark p-4 p-lg-5">
                    <div class="kicker mb-3">ATUAÇÃO PONTA A PONTA</div>
                    <h2 class="h3 fw-bold mb-3 text-white">Da estruturação à gestão: cobertura integral da operação</h2>
                    <p class="text-white-50 mb-4" style="text-align: justify;">
                        Atuamos desde a concepção jurídico-financeira até o acompanhamento pós-emissão, com processos definidos, documentação controlada e fluxo de informações estruturado entre emissores, investidores e partes envolvidas.
                    </p>

                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">1</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Estruturação e Modelagem</div>
                                <div class="small text-white-50" style="text-align: justify;">Desenho da tese, modelagem financeira, estruturação jurídico-regulatória e coordenação da oferta.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">2</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Monitoramento e Governança</div>
                                <div class="small text-white-50" style="text-align: justify;">Acompanhamento de covenants, garantias, indicadores da operação e eventos de crédito.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">3</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Transparência e Controle</div>
                                <div class="small text-white-50" style="text-align: justify;">Gestão de documentos, trilha de auditoria, relatórios e visibilidade para investidores.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-4">
        <div class="row align-items-end g-4 mb-5">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Atuação setorial</div>
                <h2 class="display-6 fw-bold mb-3 text-brand">Estruturas alinhadas ao ativo, ao setor e ao fluxo da operação</h2>
                <p class="section-copy mb-0">
                    Cada setor possui dinâmica própria de geração de caixa, risco e lastro. A BSI Capital estrutura operações sob medida, considerando as características do ativo, a natureza do negócio e os requisitos de cada emissão.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="section-divider ms-lg-auto"></div>
            </div>
        </div>

        <?php
            $industries = [
                ['Imobiliário', 'Operações de CRI e crédito imobiliário estruturado, desenvolvidas a partir de ativos, recebíveis e portfólios imobiliários, com controle documental, governança e monitoramento da carteira.', 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=800&auto=format&fit=crop', '/imobiliario/cri-real-estate'],
                ['Agronegócio', 'Operações de CRA e crédito estruturado para o agronegócio, alinhadas ao ciclo produtivo, às garantias e à dinâmica de geração de caixa do setor.', 'https://images.unsplash.com/photo-1500382017468-9049fed747ef?q=80&w=800&auto=format&fit=crop', '/agronegocio/cra'],
                ['Infra & Empresas', 'Operações de crédito corporativo estruturado, incluindo debêntures, notas comerciais e recebíveis empresariais, para apoiar expansão, capex e reorganização de passivos.', 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?q=80&w=800&auto=format&fit=crop', '/infra-empresas/cr-futuro'],
            ];
        ?>

        <div class="row g-4">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $industries; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as [$title, $desc, $img, $link]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 overflow-hidden position-relative border-0 shadow-sm card-hover" style="min-height: 420px;">
                        <img src="<?php echo e($img); ?>" class="position-absolute w-100 h-100 object-fit-cover" alt="<?php echo e($title); ?>">
                        <div class="position-absolute w-100 h-100" style="background: linear-gradient(180deg, rgba(2, 9, 24, 0.05) 0%, rgba(0, 18, 51, 0.82) 74%, rgba(0, 18, 51, 0.96) 100%);"></div>
                        <div class="position-relative h-100 d-flex flex-column justify-content-end p-4 text-white">
                            <h3 class="h4 fw-bold mb-3 text-white"><?php echo e($title); ?></h3>
                            <p class="mb-4 text-white-50"><?php echo e($desc); ?></p>
                            <div>
                                <a href="<?php echo e($link); ?>" class="btn btn-light px-4">Explorar Solução</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
        </div>
    </div>
</section>




<section class="py-5 section-dark">
    <div class="container py-5">
        <div class="row align-items-end g-4 mb-5">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Governança em Execução</div>
                <h2 class="display-6 fw-bold mb-3">Controle, governança e precisão em cada etapa da operação</h2>
                <p class="text-muted mb-0">
                    Da estruturação ao monitoramento pós-emissão, atuamos com processos rigorosos, tecnologia proprietária e visão integrada para garantir transparência, rastreabilidade e segurança operacional.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="section-divider ms-lg-auto"></div>
            </div>
        </div>

        <?php
            $cases = [
                [
                    'title' => 'Estruturação de CRI',
                    'desc' => 'Modelagem técnica integral, coordenação jurídica e financeira, controle de lastro e monitoramento ativo da operação até o vencimento final.',
                    'img' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=1000&auto=format&fit=crop',
                    'slug' => 'estruturacao-cri',
                ],
                [
                    'title' => 'Gestão de Ativos e Documentos',
                    'desc' => 'Ecosistema digital com acessos dedicados para emissores e investidores, integrando custódia de documentos e trilha de auditoria com total transparência.',
                    'img' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?q=80&w=1000&auto=format&fit=crop',
                    'slug' => 'gestao-de-documentos',
                ],
            ];
        ?>

        <div class="row g-4">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $cases; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $case): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                <div class="col-lg-6">
                    <div class="card h-100 border-0 overflow-hidden">
                        <div class="row g-0 h-100">
                            <div class="col-md-5">
                                <img src="<?php echo e($case['img']); ?>" class="w-100 h-100 object-fit-cover" alt="<?php echo e($case['title']); ?>" style="min-height: 280px;">
                            </div>
                            <div class="col-md-7">
                                <div class="p-4 p-lg-5 d-flex flex-column h-100">
                                    <div class="section-kicker mb-2">Estudo de Caso</div>
                                    <h3 class="h3 fw-bold mb-3"><?php echo e($case['title']); ?></h3>
                                    <p class="text-muted mb-4"><?php echo e($case['desc']); ?></p>
                                    <div class="mt-auto">
                                        <a href="<?php echo e(route('site.cases.show', $case['slug'])); ?>" class="btn btn-outline-gold px-4">Analisar Caso</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
        </div>
    </div>
</section>

<section class="py-4" style="background: color-mix(in srgb, var(--surface) 94%, var(--brand) 6%); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Nossa missão</div>
                <p class="fw-semibold mb-3" style="font-size: 1.05rem; color: var(--text); line-height: 1.6;">
                    Prover soluções de securitização com alto padrão técnico e eficiência operacional, assegurando integridade e conformidade em todas as etapas do mercado de capitais.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="result-chip">Governança corporativa</span>
                    <span class="result-chip">Transparência absoluta</span>
                    <span class="result-chip">Diligência fiduciária</span>
                    <span class="result-chip">Parcerias sólidas</span>
                </div>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="<?php echo e(route('site.about')); ?>" class="btn btn-outline-brand px-4">Conheça a BSI</a>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="card border-0 overflow-hidden" style="background: linear-gradient(135deg, var(--brand-strong), #0b1f4f);">
            <div class="row g-0 align-items-center">
                <div class="col-lg-8">
                    <div class="p-4 p-lg-5">
                        <div class="section-kicker mb-2">Relacionamento institucional</div>
                        <h2 class="h2 fw-bold text-white mb-3">Entre em contato com a BSI Capital</h2>
                        <p class="text-white-50 mb-0" style="max-width: 640px;">
                            Nossa equipe técnica está pronta para atuar na estruturação de operações, coordenação de ofertas ou relações com investidores, garantindo agilidade e suporte consultivo em cada contato.
                        </p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-4 p-lg-5 d-flex flex-column gap-3 align-items-start align-items-lg-stretch">
                        <a href="<?php echo e(route('proposal.create')); ?>" class="btn btn-light btn-lg">Enviar Proposta</a>
                        <a href="<?php echo e(route('site.contact')); ?>" class="text-white-50 text-decoration-none small" style="padding: 0.25rem 0;">
                            ou fale com nossa equipe →
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/home.blade.php ENDPATH**/ ?>
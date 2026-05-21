<?php $__env->startSection('title', 'Relações com Investidores — BSI Capital'); ?>

<?php $__env->startPush('head'); ?>
<style>
    .ri-pagination-shell {
        margin-top: 2rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.25rem;
        border: 1px solid color-mix(in srgb, var(--brand) 10%, var(--border));
        border-radius: 22px;
        background: color-mix(in srgb, var(--surface) 95%, var(--brand) 5%);
        box-shadow: var(--shadow-soft);
    }

    .ri-pagination-summary {
        margin: 0;
        color: var(--muted);
        font-size: 0.92rem;
        line-height: 1.6;
        text-align: center;
    }

    .ri-pagination-summary strong {
        color: var(--brand);
    }

    .ri-pagination-nav {
        width: 100%;
    }

    .ri-pagination-nav .pagination {
        margin-top: 0;
    }

    @media (min-width: 992px) {
        .ri-pagination-shell {
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            gap: 1.5rem;
        }

        .ri-pagination-summary {
            text-align: left;
        }

        .ri-pagination-nav {
            display: flex;
            justify-content: flex-end;
        }
    }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<?php
    $activeFilters = array_filter([
        'Categoria' => $category ? ($categories[$category] ?? $category) : null,
        'Busca' => $q !== '' ? '"'.$q.'"' : null,
    ]);
?>

<section class="hero position-relative d-flex align-items-center" style="min-height: 38vh;">
    <div class="container position-relative">
        <div class="row g-4 align-items-end">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase">Institucional</span>
                <h1 class="display-4 fw-bold mb-3">Relações com <span style="color: var(--gold);">Investidores</span></h1>
                <p class="lead mb-0" style="max-width: 760px;">
                    Seja bem-vindo à nossa Central de Transparência. Aqui você acessa documentos e comunicados oficiais em tempo real, com a fidelidade informacional necessária para sua tomada de decisão.
                </p>
            </div>
            <div class="col-lg-4">
                <div class="surface-card-dark p-4">
                    <div class="small text-uppercase text-white-50 fw-semibold mb-2">Repositório Institucional</div>
                    <div class="d-flex justify-content-between align-items-end mb-2">
                        <div>
                            <div class="fs-2 fw-bold text-white"><?php echo e($docs->total()); ?></div>
                            <div class="small text-white-50">documento(s) disponível(is)</div>
                        </div>
                        <div class="badge badge-soft px-3 py-2"><?php echo e(count($categories)); ?> categorias</div>
                    </div>
                    <div class="small text-white-50">Busca, filtros e histórico em uma leitura mais clara e consistente com o restante da plataforma.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="surface-card p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-end">
                <div class="col-lg-7">
                    <div class="section-kicker mb-2">Consulta pública</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Repositório de Documentos Públicos: Navegação estruturada</h2>
                    <p class="section-copy mb-0">
                        Localize publicações oficiais por categoria ou palavra-chave, assegurando o acesso direto ao histórico documental da BSI Capital com precisão e clareza.
                    </p>
                </div>
                <div class="col-lg-5">
                    <form method="GET" id="riForm">
                        <div class="input-group">
                            <input
                                type="text"
                                class="form-control border-end-0"
                                name="q"
                                value="<?php echo e($q); ?>"
                                placeholder="Pesquisar documentos e comunicados..."
                            >
                            <button type="submit" class="input-group-text border-start-0 bg-transparent px-3">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            </button>
                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($category): ?>
                            <input type="hidden" name="category" value="<?php echo e($category); ?>">
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <a href="<?php echo e(route('site.ri', array_filter(['q' => $q]))); ?>" class="btn <?php echo e(!$category ? 'btn-brand' : 'btn-outline-brand'); ?> btn-sm px-4">Todos</a>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                    <a href="<?php echo e(route('site.ri', array_filter(['category' => $key, 'q' => $q]))); ?>" class="btn <?php echo e($category === $key ? 'btn-brand' : 'btn-outline-brand'); ?> btn-sm px-4">
                        <?php echo e($label); ?>

                    </a>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            </div>
        </div>

        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center mb-4">
            <div class="section-copy">
                <strong><?php echo e($docs->total()); ?></strong> documento(s) disponível(is)
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($category): ?>
                    na categoria <strong><?php echo e($categories[$category] ?? $category); ?></strong>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($q !== ''): ?>
                    para a busca <strong>"<?php echo e($q); ?>"</strong>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($activeFilters !== []): ?>
                    <a href="<?php echo e(route('site.ri')); ?>" class="btn btn-outline-brand btn-sm px-4">Limpar filtros</a>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <span class="result-chip"><?php echo e($docs->currentPage()); ?> / <?php echo e($docs->lastPage()); ?> página(s)</span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $activeFilters; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                <span class="result-chip"><?php echo e($label); ?>: <?php echo e($value); ?></span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                <span class="result-chip">Sem filtros ativos</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <div class="d-flex flex-column gap-3">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $docs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                <div class="card border-0 shadow-sm overflow-hidden">
                    <div class="row g-0 align-items-center">
                        <div class="col">
                            <div class="p-3 p-lg-4">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; border-radius: 14px; background: rgba(0, 32, 91, 0.06); color: var(--brand);">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h3 class="h5 fw-bold text-brand mb-2"><?php echo e($d->title); ?></h3>
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                            <span class="badge badge-soft px-3 py-2"><?php echo e($categories[$d->category] ?? ($d->category ?? '—')); ?></span>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $d->emissions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $emission): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                                                <span class="badge px-3 py-2" style="background: rgba(212,175,55,0.1); color: var(--gold); border: 1px solid rgba(212,175,55,0.2);"><?php echo e($emission->name); ?></span>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                                        </div>
                                        <div class="d-flex flex-wrap gap-3 small text-muted">
                                            <span><?php echo e(optional($d->{$dateField})->format('d/m/Y') ?? '—'); ?></span>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($d->file_size): ?>
                                                <span><?php echo e($d->file_size >= 1048576 ? number_format($d->file_size / 1048576, 1) . ' MB' : number_format($d->file_size / 1024, 0) . ' KB'); ?></span>
                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                            <span>Documento público</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-auto px-3 px-lg-4 pb-3 pb-lg-0 d-grid">
                            <a href="<?php echo e(Storage::disk($d->resolved_storage_disk)->url($d->file_path)); ?>" target="_blank" class="btn btn-brand btn-sm px-4 d-block text-center" download>
                                Acessar
                            </a>
                        </div>
                    </div>
                </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                <div class="card p-5 text-center border-0 shadow-sm">
                    <div class="fw-semibold text-muted mb-2">Nenhum documento foi localizado para os critérios aplicados.</div>
                    <div class="small text-muted mb-4">Caso não encontre o que procura, nossa equipe de RI está à disposição para auxiliá-lo.</div>
                    <div class="d-flex justify-content-center">
                        <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-outline-brand btn-sm px-4">Solicitar documento específico</a>
                    </div>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($docs->hasPages()): ?>
            <div class="ri-pagination-shell">
                <p class="ri-pagination-summary">
                    Exibindo <strong><?php echo e($docs->firstItem()); ?></strong> a <strong><?php echo e($docs->lastItem()); ?></strong> de <strong><?php echo e($docs->total()); ?></strong> documentos
                </p>
                <div class="ri-pagination-nav">
                    <?php echo e($docs->links('site.ri-pagination')); ?>

                </div>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="card border-0 overflow-hidden" style="background: linear-gradient(135deg, var(--brand-strong), #0b1f4f);">
            <div class="row g-0 align-items-center">
                <div class="col-lg-8">
                    <div class="p-4 p-lg-5">
                        <div class="section-kicker mb-2">Canal de contato com investidores</div>
                        <h2 class="h3 fw-bold text-white mb-3">Precisa de apoio sobre documentos públicos ou comunicados?</h2>
                        <p class="text-white-50 mb-0">
                            Entre em contato com nossa equipe para esclarecimentos sobre publicações, informações institucionais e temas de relacionamento com investidores.
                        </p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-4 p-lg-5">
                        <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-light btn-lg">Fale com RI</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/ri.blade.php ENDPATH**/ ?>
<?php $__env->startSection('title', 'Emissões — BSI Capital'); ?>

<?php $__env->startPush('head'); ?>
<style>
    .emissions-pagination-shell {
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

    .emissions-pagination-summary {
        margin: 0;
        color: var(--muted);
        font-size: 0.92rem;
        line-height: 1.6;
        text-align: center;
    }

    .emissions-pagination-summary strong {
        color: var(--brand);
    }

    .emissions-pagination-nav {
        width: 100%;
    }

    .emissions-pagination-nav .pagination {
        margin-top: 0;
    }

    @media (min-width: 992px) {
        .emissions-pagination-shell {
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            gap: 1.5rem;
        }

        .emissions-pagination-summary {
            text-align: left;
        }

        .emissions-pagination-nav {
            display: flex;
            justify-content: flex-end;
        }
    }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<?php
    $activeFilters = array_filter([
        'Busca' => ($q ?? '') !== '' ? '"'.($q ?? '').'"' : null,
        'Tipo' => ($type ?? '') !== '' ? $type : null,
        'Data de emissão' => ($issue_date_order ?? '') !== '' ? ($issue_date_order === 'desc' ? 'Mais recente para mais antiga' : 'Mais antiga para mais recente') : null,
        'Data de vencimento' => ($maturity_date_order ?? '') !== '' ? ($maturity_date_order === 'desc' ? 'Mais recente para mais antiga' : 'Mais antiga para mais recente') : null,
    ]);
?>

<section class="hero position-relative d-flex align-items-center" style="min-height: 34vh;">
    <div class="container">
        <div class="row g-4 align-items-end">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase">Mercado primário</span>
                <h1 class="display-4 fw-bold mb-3">Track Record de Operações: Especialidade em Securitização e Crédito Estruturado</h1>
                <p class="lead mb-0" style="max-width: 760px;">
                    Explore o detalhamento técnico das emissões estruturadas pela BSI Capital. Unimos transparência ativa e rigor na análise do lastro para sustentar estruturas sólidas e auditáveis no mercado de capitais.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="surface-card p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-end">
                <div class="col-lg-5">
                    <div class="section-kicker mb-2">Pesquisa e filtros</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Inteligência de Dados e Filtros Dinâmicos</h2>
                    <p class="section-copy mb-0">
                        Localize operações com precisão por setor, instrumento ou emissor. Disponibilizamos dados estruturados e granulares para dar suporte às análises mais exigentes e ao monitoramento estratégico de ativos no mercado de capitais.
                    </p>
                </div>
                <div class="col-lg-7">
                    <form method="GET" class="row g-3">
                        <div class="col-12">
                            <div class="input-group">
                                <span class="input-group-text border-end-0 bg-transparent ps-4">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                </span>
                                <input class="form-control border-start-0" name="q" value="<?php echo e($q ?? ''); ?>" placeholder="Busque por nome, emissor ou código IF">
                                <button class="btn btn-brand px-4 px-md-5">Buscar</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo de emissão</label>
                            <select name="type" class="form-select" onchange="this.form.submit()">
                                <option value="">Todos os tipos</option>
                                <option value="CR" <?php echo e(($type ?? '') === 'CR' ? 'selected' : ''); ?>>CR</option>
                                <option value="CRA" <?php echo e(($type ?? '') === 'CRA' ? 'selected' : ''); ?>>CRA</option>
                                <option value="CRI" <?php echo e(($type ?? '') === 'CRI' ? 'selected' : ''); ?>>CRI</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Data de emissão</label>
                            <select name="issue_date_order" class="form-select" onchange="this.form.submit()">
                                <option value="">Ordenar por...</option>
                                <option value="desc" <?php echo e(($issue_date_order ?? '') === 'desc' ? 'selected' : ''); ?>>Mais recente &gt; Mais antiga</option>
                                <option value="asc" <?php echo e(($issue_date_order ?? '') === 'asc' ? 'selected' : ''); ?>>Mais antiga &gt; Mais recente</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Data de vencimento</label>
                            <select name="maturity_date_order" class="form-select" onchange="this.form.submit()">
                                <option value="">Ordenar por...</option>
                                <option value="desc" <?php echo e(($maturity_date_order ?? '') === 'desc' ? 'selected' : ''); ?>>Mais recente &gt; Mais antiga</option>
                                <option value="asc" <?php echo e(($maturity_date_order ?? '') === 'asc' ? 'selected' : ''); ?>>Mais antiga &gt; Mais recente</option>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center mb-4">
            <div class="section-copy">
                <strong><?php echo e($emissions->total()); ?></strong> operação(ões) pública(s)
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($activeFilters !== []): ?>
                    com filtros ativos para leitura dirigida
                <?php else: ?>
                    disponíveis para consulta pública
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($activeFilters !== []): ?>
                    <a href="<?php echo e(route('site.emissions')); ?>" class="btn btn-outline-brand btn-sm px-4">Limpar filtros</a>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <span class="result-chip"><?php echo e($emissions->currentPage()); ?> / <?php echo e($emissions->lastPage()); ?> página(s)</span>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $activeFilters; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                <span class="result-chip"><?php echo e($label); ?>: <?php echo e($value); ?></span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                <span class="result-chip">Sem filtros ativos</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <div class="row g-4">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $emissions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
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
                                            <span class="badge badge-status-<?php echo e($e->status); ?> px-3 py-2">
                                                <?php echo e($e->status_label); ?>

                                            </span>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                    <h3 class="h5 fw-bold text-brand mb-0" style="line-height: 1.45; word-wrap: break-word;"><?php echo e($e->name); ?></h3>
                                </div>
                                <div class="d-flex align-items-center justify-content-center flex-shrink-0 p-2" style="width: 64px; height: 64px; border-radius: 14px; background: rgba(0,32,91,0.06); color: var(--brand);">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($e->logo_path): ?>
                                        <img src="<?php echo e(Storage::disk($e->logo_storage_disk)->url($e->logo_path)); ?>" alt="<?php echo e($e->name); ?>" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                                    <?php else: ?>
                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($e->type === 'CRI'): ?>
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1m4 0h1m-5 4h1m4 0h1M9 16h1m4 0h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path></svg>
                                        <?php elseif($e->type === 'CRA'): ?>
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                                        <?php else: ?>
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                                <div class="col-12">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Código ISIN</div>
                                    <div class="fw-semibold"><?php echo e($e->isin_code ?? '—'); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 p-3 p-lg-4 pt-0 d-grid">
                            <a href="<?php echo e(route('site.emissions.show', $e->if_code)); ?>" class="btn btn-outline-brand btn-sm w-100">Consultar Operação</a>
                        </div>
                    </div>
                </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                <div class="col-12">
                    <div class="card p-5 text-center text-muted">
                        <div class="fw-semibold mb-2">Nenhuma operação corresponde aos filtros atuais.</div>
                        <div class="small">Revise os critérios selecionados ou limpe a pesquisa para ampliar o universo de consulta.</div>
                    </div>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($emissions->hasPages()): ?>
            <div class="emissions-pagination-shell">
                <p class="emissions-pagination-summary">
                Exibindo <strong><?php echo e($emissions->firstItem()); ?></strong> a <strong><?php echo e($emissions->lastItem()); ?></strong> de <strong><?php echo e($emissions->total()); ?></strong> operações
                </p>
                <div class="emissions-pagination-nav">
                    <?php echo e($emissions->links('site.ri-pagination')); ?>

                </div>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/emissions.blade.php ENDPATH**/ ?>
<?php # [BlazeFolded]:{flux::icon.building-office-2}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/building-office-2.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.clock}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/clock.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.building-office-2}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/building-office-2.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.chart-bar}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/chart-bar.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php
    $activeEmissionsCount = $emissions->where('status', 'active')->count();
    $closedEmissionsCount = $emissions->where('status', 'closed')->count();
    $otherEmissionsCount = $emissions->count() - $activeEmissionsCount - $closedEmissionsCount;
?>

<div class="space-y-6">
    <section class="bsi-shell-card p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-3xl">
                <div class="bsi-kicker mb-2">Acompanhamento</div>
                <h1 class="text-3xl font-semibold tracking-[-0.04em] text-brand-800">Minhas emissões</h1>
                <p class="mt-3 text-sm leading-7 text-zinc-600">
                    Acompanhe as emissões vinculadas ao seu cadastro com uma leitura mais clara dos principais dados operacionais.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <span class="bsi-portal-meta">
                    <svg class="shrink-0 [:where(&amp;)]:size-6 size-4" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z"/>
</svg>

        
                    <span><?php echo e($emissions->count()); ?> operação(ões)</span>
                </span>
                <a href="<?php echo e(route('investor.documents')); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white      !rounded-full !px-5">
        Ver documentos
    </a>

            </div>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-3">
        <article class="bsi-shell-card p-5">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Status ativo</div>
            <div class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-brand-800"><?php echo e($activeEmissionsCount); ?></div>
            <p class="mt-2 text-sm leading-7 text-zinc-600">Operações em acompanhamento corrente e com maior probabilidade de novas publicações.</p>
        </article>

        <article class="bsi-shell-card p-5">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Status encerrado</div>
            <div class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-brand-800"><?php echo e($closedEmissionsCount); ?></div>
            <p class="mt-2 text-sm leading-7 text-zinc-600">Operações fechadas, preservadas no portal para consulta histórica e rastreabilidade.</p>
        </article>

        <article class="bsi-shell-card p-5">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Em observação</div>
            <div class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-brand-800"><?php echo e($otherEmissionsCount); ?></div>
            <p class="mt-2 text-sm leading-7 text-zinc-600">Operações com outro status operacional, úteis para leitura de pipeline ou transição de carteira.</p>
        </article>
    </section>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($emissions->isNotEmpty() && $activeEmissionsCount === 0): ?>
        <section class="bsi-portal-surface p-6 lg:p-7">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-4">
                    <span class="mt-1 flex size-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-700">
                        <svg class="shrink-0 [:where(&amp;)]:size-6 size-6" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
</svg>

        
                    </span>
                    <div>
                        <h2 class="text-xl font-semibold tracking-[-0.03em] text-brand-800">Nenhuma operação ativa no momento</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-7 text-zinc-600">
                            As emissões vinculadas ao seu cadastro seguem disponíveis para consulta, mas nenhuma delas está marcada como ativa neste instante.
                        </p>
                    </div>
                </div>

                <a href="<?php echo e(route('investor.documents')); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-[var(--color-accent)] hover:bg-[color-mix(in_oklab,_var(--color-accent),_transparent_10%)] text-[var(--color-accent-foreground)] border border-black/10 dark:border-0 shadow-[inset_0px_1px_--theme(--color-white/.2)] [[data-flux-button-group]_&amp;]:border-e-0 [:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-e-[1px] dark:[:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-e-0 dark:[:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-s-[1px] [:is([data-flux-button-group]&gt;&amp;:not(:first-child),_[data-flux-button-group]_:not(:first-child)&gt;&amp;)]:border-s-[color-mix(in_srgb,var(--color-accent-foreground),transparent_85%)]   !rounded-full !px-5" data-flux-group-target="data-flux-group-target">
        Ver documentos
    </a>

            </div>
        </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($emissions->isEmpty()): ?>
        <section class="bsi-shell-card p-10 text-center">
            <span class="mx-auto flex size-16 items-center justify-center rounded-[24px] bg-brand-50 text-brand-700">
                <svg class="shrink-0 [:where(&amp;)]:size-6 size-8" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z"/>
</svg>

        
            </span>
            <h2 class="mt-5 text-2xl font-semibold tracking-[-0.04em] text-brand-800">Nenhuma emissão vinculada</h2>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-7 text-zinc-600">
                Quando houver emissões associadas ao seu cadastro, elas aparecerão aqui com os principais dados para consulta rápida.
            </p>
        </section>
    <?php else: ?>
        <section class="grid gap-4 xl:grid-cols-2">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $emissions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $emission): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                <?php
                    $statusClasses = match ($emission->status) {
                        'active' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                        'closed' => 'border-red-200 bg-red-50 text-red-700',
                        default => 'border-amber-200 bg-amber-50 text-amber-700',
                    };
                ?>

                <article class="bsi-shell-card p-6" <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'emission-'.e($emission->id).''; ?>wire:key="emission-<?php echo e($emission->id); ?>">
                    <div class="flex flex-col gap-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full bg-gold-400/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-gold-600">
                                        <?php echo e($emission->type); ?>

                                    </span>
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?php echo e($statusClasses); ?>">
                                        <?php echo e($emission->status_label); ?>

                                    </span>
                                </div>
                                <h2 class="mt-4 text-2xl font-semibold tracking-[-0.04em] text-brand-800"><?php echo e($emission->name); ?></h2>
                                <p class="mt-2 text-sm text-zinc-500">
                                    IF <?php echo e($emission->if_code ?? '—'); ?> · ISIN <?php echo e($emission->isin_code ?? '—'); ?>

                                </p>
                            </div>

                            <div class="flex size-16 flex-shrink-0 items-center justify-center overflow-hidden rounded-[24px] border border-brand-100 bg-brand-50/70 p-3">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($emission->logo_path): ?>
                                    <img src="<?php echo e(Storage::disk($emission->logo_storage_disk)->url($emission->logo_path)); ?>" alt="<?php echo e($emission->name); ?>" class="h-full w-full object-contain">
                                <?php else: ?>
                                    <svg class="shrink-0 [:where(&amp;)]:size-6 size-7 text-brand-700" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
</svg>

        
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-[22px] border border-zinc-200/80 bg-zinc-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Emissor</div>
                                <div class="mt-2 text-sm font-semibold text-zinc-800"><?php echo e($emission->issuer ?? 'Não informado'); ?></div>
                            </div>
                            <div class="rounded-[22px] border border-zinc-200/80 bg-zinc-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Remuneração</div>
                                <div class="mt-2 text-sm font-semibold text-zinc-800"><?php echo e($emission->formatted_remuneration ?? 'Não informada'); ?></div>
                            </div>
                            <div class="rounded-[22px] border border-zinc-200/80 bg-zinc-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Emissão</div>
                                <div class="mt-2 text-sm font-semibold text-zinc-800"><?php echo e($emission->issue_date?->format('d/m/Y') ?? 'Não informada'); ?></div>
                            </div>
                            <div class="rounded-[22px] border border-zinc-200/80 bg-zinc-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Vencimento</div>
                                <div class="mt-2 text-sm font-semibold text-zinc-800"><?php echo e($emission->maturity_date?->format('d/m/Y') ?? 'Não informado'); ?></div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 border-t border-zinc-200/80 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-zinc-500">
                                Volume emitido: <span class="font-semibold text-zinc-800"><?php echo e($emission->issued_volume ? 'R$ '.number_format((float) $emission->issued_volume, 2, ',', '.') : 'Não informado'); ?></span>
                            </p>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($emission->if_code): ?>
                                <a href="<?php echo e(route('site.emissions.show', $emission->if_code)); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white      !rounded-full !px-5">
        Ver operação
    </a>

                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
        </section>

        <section class="bsi-shell-card p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl">
                    <div class="bsi-kicker mb-2">Próximo passo</div>
                    <h2 class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Cruze operações com a trilha documental</h2>
                    <p class="mt-3 text-sm leading-7 text-zinc-600">
                        Para leitura mais completa, combine a consulta das emissões com os documentos publicados no portal e acompanhe os eventos mais recentes da carteira.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="<?php echo e(route('investor.documents')); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-[var(--color-accent)] hover:bg-[color-mix(in_oklab,_var(--color-accent),_transparent_10%)] text-[var(--color-accent-foreground)] border border-black/10 dark:border-0 shadow-[inset_0px_1px_--theme(--color-white/.2)] [[data-flux-button-group]_&amp;]:border-e-0 [:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-e-[1px] dark:[:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-e-0 dark:[:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-s-[1px] [:is([data-flux-button-group]&gt;&amp;:not(:first-child),_[data-flux-button-group]_:not(:first-child)&gt;&amp;)]:border-s-[color-mix(in_srgb,var(--color-accent-foreground),transparent_85%)]   !rounded-full !px-5" data-flux-group-target="data-flux-group-target">
        Ir para documentos
    </a>

                    <a href="<?php echo e(route('investor.dashboard')); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white      !rounded-full !px-5">
        Voltar ao início
    </a>

                </div>
            </div>
        </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /home/desktop/projects/bsi-capital/resources/views/livewire/investor/investor-emissions.blade.php ENDPATH**/ ?>
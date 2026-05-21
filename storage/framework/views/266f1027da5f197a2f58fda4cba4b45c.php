<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.document-text}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/document-text.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.chart-pie}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/chart-pie.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.folder-open}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/folder-open.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.shield-check}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/shield-check.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.chart-bar}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/chart-bar.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.folder}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/folder.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php
    $portalHealthMessage = match (true) {
        $emissionsCount === 0 && $documentsCount === 0 => 'Seu cadastro ainda não possui emissões ou documentos vinculados. Assim que a liberação operacional for concluída, o portal passará a exibir os conteúdos disponíveis.',
        $emissionsCount === 0 => 'Ainda não há emissões vinculadas ao seu cadastro. Se o vínculo já deveria estar ativo, vale acionar a equipe responsável para validar o escopo de acesso.',
        $documentsCount === 0 => 'As emissões já estão vinculadas, mas ainda não existem documentos publicados no seu escopo. O portal atualizará automaticamente quando novos arquivos forem liberados.',
        $newDocumentsCount === 0 => 'Não há novas publicações desde o seu último acesso. O ambiente segue pronto para consulta de documentos e acompanhamento das operações já disponíveis.',
        default => 'O portal está atualizado com emissões, documentos e novos arquivos publicados desde o último acesso.',
    };

    $portalHealthTone = match (true) {
        $emissionsCount === 0 || $documentsCount === 0 => 'border-amber-200 bg-amber-50/90 text-amber-800',
        $newDocumentsCount === 0 => 'border-brand-100 bg-brand-50/90 text-brand-800',
        default => 'border-emerald-200 bg-emerald-50/90 text-emerald-800',
    };
?>

<div class="space-y-8">
    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_22rem]">
        <div class="bsi-portal-panel p-8 lg:p-10">
            <div class="flex h-full flex-col gap-8">
                <div>
                    <div class="bsi-kicker mb-4 text-gold-400">Portal do investidor</div>
                    <h1 class="max-w-3xl text-4xl font-semibold tracking-[-0.05em] text-white md:text-5xl">
                        Bem-vindo, <?php echo e($investor->name); ?>.
                    </h1>
                    <p class="mt-5 max-w-2xl text-base leading-8 text-brand-100">
                        Acesse suas emissões, acompanhe documentos publicados e concentre o relacionamento operacional em uma interface alinhada à identidade institucional da BSI Capital.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="<?php echo e(route('investor.documents')); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-[var(--color-accent)] hover:bg-[color-mix(in_oklab,_var(--color-accent),_transparent_10%)] text-[var(--color-accent-foreground)] border border-black/10 dark:border-0 shadow-[inset_0px_1px_--theme(--color-white/.2)] [[data-flux-button-group]_&amp;]:border-e-0 [:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-e-[1px] dark:[:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-e-0 dark:[:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-s-[1px] [:is([data-flux-button-group]&gt;&amp;:not(:first-child),_[data-flux-button-group]_:not(:first-child)&gt;&amp;)]:border-s-[color-mix(in_srgb,var(--color-accent-foreground),transparent_85%)]   !rounded-full !px-5 !py-3" data-flux-group-target="data-flux-group-target">
        Ver documentos
    </a>

                    <a href="<?php echo e(route('investor.emissions')); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-800 dark:text-white      !rounded-full !border !border-white/15 !bg-white/10 !px-5 !py-3 !text-white hover:!bg-white/15">
        Minhas emissões
    </a>

                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="bsi-portal-stat">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-400">Emissões</div>
                        <div class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-white"><?php echo e(number_format($emissionsCount)); ?></div>
                        <p class="mt-2 text-sm leading-6 text-brand-100">Operações vinculadas ao seu cadastro.</p>
                    </div>
                    <div class="bsi-portal-stat">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-400">Documentos</div>
                        <div class="mt-3 text-3xl font-semibold tracking-[-0.04em] text-white"><?php echo e(number_format($documentsCount)); ?></div>
                        <p class="mt-2 text-sm leading-6 text-brand-100">Arquivos acessíveis dentro do seu escopo.</p>
                    </div>
                    <div class="bsi-portal-stat">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-400">Último login</div>
                        <div class="mt-3 text-lg font-semibold text-white">
                            <?php echo e($investor->last_login_at?->format('d/m/Y H:i') ?? 'Primeiro acesso'); ?>

                        </div>
                        <p class="mt-2 text-sm leading-6 text-brand-100">Registro de autenticação mais recente.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
            <div class="bsi-shell-card p-6">
                <div class="bsi-kicker mb-2">Perfil</div>
                <div class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Acesso institucional</div>
                <div class="mt-4 space-y-3 text-sm text-zinc-600">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">E-mail</div>
                        <div class="mt-1 font-semibold text-zinc-800"><?php echo e($investor->email); ?></div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-400">Telefone</div>
                        <div class="mt-1 font-semibold text-zinc-800"><?php echo e($investor->mobile ?: ($investor->phone ?: 'Não informado')); ?></div>
                    </div>
                </div>
            </div>

            <div class="bsi-shell-card p-6">
                <div class="bsi-kicker mb-2">Governança</div>
                <div class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Documentos rastreáveis</div>
                <p class="mt-3 text-sm leading-7 text-zinc-600">
                    Consulte publicações e comunicações com a mesma lógica de controle documental aplicada ao site e às áreas operacionais da BSI.
                </p>
            </div>
        </div>
    </section>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($newDocumentsCount > 0): ?>
        <section class="bsi-portal-surface p-6 lg:p-7">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-4">
                    <span class="mt-1 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                        <svg class="shrink-0 [:where(&amp;)]:size-6 size-6" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
</svg>

        
                    </span>
                    <div>
                        <h2 class="text-xl font-semibold tracking-[-0.03em] text-brand-800">Novos documentos disponíveis</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-7 text-zinc-600">
                            Você tem <strong><?php echo e($newDocumentsCount); ?></strong> documento(s) publicado(s) desde o seu último acesso ao portal.
                        </p>
                    </div>
                </div>

                <a href="<?php echo e(route('investor.documents')); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-[var(--color-accent)] hover:bg-[color-mix(in_oklab,_var(--color-accent),_transparent_10%)] text-[var(--color-accent-foreground)] border border-black/10 dark:border-0 shadow-[inset_0px_1px_--theme(--color-white/.2)] [[data-flux-button-group]_&amp;]:border-e-0 [:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-e-[1px] dark:[:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-e-0 dark:[:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-s-[1px] [:is([data-flux-button-group]&gt;&amp;:not(:first-child),_[data-flux-button-group]_:not(:first-child)&gt;&amp;)]:border-s-[color-mix(in_srgb,var(--color-accent-foreground),transparent_85%)]   !rounded-full !px-5" data-flux-group-target="data-flux-group-target">
        Ver documentos
    </a>

            </div>
        </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <section class="grid gap-4 lg:grid-cols-3">
        <article class="bsi-shell-card p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                    <svg class="shrink-0 [:where(&amp;)]:size-6 size-6" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z"/>
  <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z"/>
</svg>

        
                </span>
                <div>
                    <div class="bsi-kicker mb-2">Status do acesso</div>
                    <h2 class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Cobertura operacional</h2>
                    <p class="mt-3 text-sm leading-7 text-zinc-600">
                        <?php echo e($emissionsCount > 0 ? 'Seu cadastro está associado a '.$emissionsCount.' operação(ões) com acompanhamento centralizado no portal.' : 'Nenhuma operação está disponível neste momento para consulta no portal.'); ?>

                    </p>
                </div>
            </div>
        </article>

        <article class="bsi-shell-card p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                    <svg class="shrink-0 [:where(&amp;)]:size-6 size-6" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776"/>
</svg>

        
                </span>
                <div>
                    <div class="bsi-kicker mb-2">Consulta documental</div>
                    <h2 class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Disponibilidade de arquivos</h2>
                    <p class="mt-3 text-sm leading-7 text-zinc-600">
                        <?php echo e($documentsCount > 0 ? 'Há '.$documentsCount.' documento(s) acessível(is) dentro do seu escopo atual, com filtros por emissão, categoria e período.' : 'Ainda não existem documentos liberados para o seu perfil de acesso.'); ?>

                    </p>
                </div>
            </div>
        </article>

        <article class="rounded-[26px] border px-6 py-5 shadow-sm <?php echo e($portalHealthTone); ?>">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-white/70">
                    <svg class="shrink-0 [:where(&amp;)]:size-6 size-6" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>
</svg>

        
                </span>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.2em]">Leitura do portal</div>
                    <p class="mt-3 text-sm leading-7">
                        <?php echo e($portalHealthMessage); ?>

                    </p>
                </div>
            </div>
        </article>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <article class="bsi-shell-card p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                    <svg class="shrink-0 [:where(&amp;)]:size-6 size-6" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
</svg>

        
                </span>
                <div>
                    <div class="bsi-kicker mb-2">Acesso rápido</div>
                    <h2 class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Minhas emissões</h2>
                    <p class="mt-3 text-sm leading-7 text-zinc-600">
                        Consulte operações associadas ao seu cadastro com status, vencimento e informações essenciais para acompanhamento.
                    </p>
                    <a href="<?php echo e(route('investor.emissions')); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white      mt-4 !rounded-full !px-5">
        Abrir emissões
    </a>

                </div>
            </div>
        </article>

        <article class="bsi-shell-card p-6">
            <div class="flex items-start gap-4">
                <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                    <svg class="shrink-0 [:where(&amp;)]:size-6 size-6" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>
</svg>

        
                </span>
                <div>
                    <div class="bsi-kicker mb-2">Consulta documental</div>
                    <h2 class="text-2xl font-semibold tracking-[-0.04em] text-brand-800">Documentos e relatórios</h2>
                    <p class="mt-3 text-sm leading-7 text-zinc-600">
                        Filtre por emissão, categoria ou período e mantenha uma rotina de consulta alinhada ao padrão institucional do portal.
                    </p>
                    <a href="<?php echo e(route('investor.documents')); ?>" data-flux-button="data-flux-button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white      mt-4 !rounded-full !px-5">
        Abrir documentos
    </a>

                </div>
            </div>
        </article>
    </section>
</div>
<?php /**PATH /home/desktop/projects/bsi-capital/resources/views/livewire/investor/investor-dashboard.blade.php ENDPATH**/ ?>
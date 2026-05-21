<?php # [BlazeFolded]:{flux::sidebar.collapse}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/collapse.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::sidebar.header}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/header.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::sidebar.nav}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/nav.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::spacer}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/spacer.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::sidebar}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::sidebar.toggle}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/toggle.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::spacer}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/spacer.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::header}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/header.blade.php}:{1771950596} ?>
<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>" class="dark">
    <head>
        <?php echo $__env->make('partials.head', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <ui-sidebar-toggle class="z-20 fixed inset-0 bg-black/10 hidden data-flux-sidebar-on-mobile:not-data-flux-sidebar-collapsed-mobile:block" data-flux-sidebar-backdrop></ui-sidebar-toggle>

<ui-sidebar
    class="[grid-area:sidebar] z-1 flex flex-col gap-4 [:where(&amp;)]:w-64 p-4 data-flux-sidebar-collapsed-desktop:w-14 data-flux-sidebar-collapsed-desktop:px-2 data-flux-sidebar-collapsed-desktop:cursor-e-resize rtl:data-flux-sidebar-collapsed-desktop:cursor-w-resize max-lg:data-flux-sidebar-cloak:hidden data-flux-sidebar-on-mobile:data-flux-sidebar-collapsed-mobile:-translate-x-full data-flux-sidebar-on-mobile:data-flux-sidebar-collapsed-mobile:rtl:translate-x-full z-20! data-flux-sidebar-on-mobile:start-0! data-flux-sidebar-on-mobile:fixed! data-flux-sidebar-on-mobile:top-0! data-flux-sidebar-on-mobile:min-h-dvh! data-flux-sidebar-on-mobile:max-h-dvh! max-h-dvh overflow-y-auto overscroll-contain border-e border-zinc-200/70 bg-white/95 dark:border-white/10 dark:bg-[#08111df2]" x-init="$el.classList.add(&#039;transition-transform&#039;)"
     collapsible="mobile"          sticky     x-data
    data-flux-sidebar-cloak
    data-flux-sidebar
>
    <div class="flex items-center justify-between gap-2 min-h-10" data-flux-sidebar-header>
    <?php if (isset($component)) { $__componentOriginal7b17d80ff7900603fe9e5f0b453cc7c3 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7b17d80ff7900603fe9e5f0b453cc7c3 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.app-logo','data' => ['sidebar' => true,'href' => ''.e(route('dashboard')).'','wire:navigate' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('app-logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['sidebar' => true,'href' => ''.e(route('dashboard')).'','wire:navigate' => true]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7b17d80ff7900603fe9e5f0b453cc7c3)): ?>
<?php $attributes = $__attributesOriginal7b17d80ff7900603fe9e5f0b453cc7c3; ?>
<?php unset($__attributesOriginal7b17d80ff7900603fe9e5f0b453cc7c3); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7b17d80ff7900603fe9e5f0b453cc7c3)): ?>
<?php $component = $__componentOriginal7b17d80ff7900603fe9e5f0b453cc7c3; ?>
<?php unset($__componentOriginal7b17d80ff7900603fe9e5f0b453cc7c3); ?>
<?php endif; ?>
                <ui-sidebar-toggle class="w-10 h-8 flex items-center justify-center in-data-flux-sidebar-collapsed-desktop:opacity-0 in-data-flux-sidebar-collapsed-desktop:absolute in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:opacity-100  lg:hidden" data-flux-sidebar-collapse>
    <ui-tooltip position="right center"  data-flux-tooltip >
        <button type="button" class="size-10 relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none text-sm rounded-lg inline-flex  bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white in-data-flux-sidebar-collapsed-desktop:cursor-e-resize rtl:in-data-flux-sidebar-collapsed-desktop:cursor-w-resize [&amp;[collapsible=&quot;mobile&quot;]]:in-data-flux-sidebar-on-desktop:hidden rtl:rotate-180">
            <svg class="text-zinc-500 dark:text-zinc-400" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M7.5 3.75V16.25M3.4375 16.25H16.5625C17.08 16.25 17.5 15.83 17.5 15.3125V4.6875C17.5 4.17 17.08 3.75 16.5625 3.75H3.4375C2.92 3.75 2.5 4.17 2.5 4.6875V15.3125C2.5 15.83 2.92 16.25 3.4375 16.25Z" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"></path>
            </svg>
        </button>

                    <div popover="manual" class="relative py-2 px-2.5 rounded-md text-xs text-white font-medium bg-zinc-800 dark:bg-zinc-700 dark:border dark:border-white/10 p-0 overflow-visible" data-flux-tooltip-content>
    Toggle sidebar

    </div>
            </ui-tooltip>
</ui-sidebar-toggle>
</div>

            <div class="px-4 pb-4">
                <div class="bsi-kicker mb-2">Painel BSI</div>
                <p class="bsi-copy text-xs">
                    Ambiente interno para leitura operacional, acesso institucional e navegação rápida entre fluxos relevantes.
                </p>
            </div>

            <nav class="flex flex-col overflow-visible min-h-auto" data-flux-sidebar-nav>
    <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/group.blade.php', $__blaze->compiledPath.'/422073a6c3c3549da54aa7ea2b89477a.php'); ?>
<?php require_once $__blaze->compiledPath.'/422073a6c3c3549da54aa7ea2b89477a.php'; ?>
<?php $__attrs422073a6c3c3549da54aa7ea2b89477a = ['heading' => __('Navegação'),'class' => 'grid']; ?>
<?php $__blaze->pushData($__attrs422073a6c3c3549da54aa7ea2b89477a); ?>
<?php $slots422073a6c3c3549da54aa7ea2b89477a = []; ?>
<?php ob_start(); ?>
                    <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/item.blade.php', $__blaze->compiledPath.'/b1900792061809836d7651c2d407b102.php'); ?>
<?php require_once $__blaze->compiledPath.'/b1900792061809836d7651c2d407b102.php'; ?>
<?php $__attrsb1900792061809836d7651c2d407b102 = ['icon' => 'home','href' => route('dashboard'),'current' => request()->routeIs('dashboard'),'wire:navigate' => true]; ?>
<?php $__blaze->pushData($__attrsb1900792061809836d7651c2d407b102); ?>
<?php $slotsb1900792061809836d7651c2d407b102 = []; ?>
<?php ob_start(); ?>
                        <?php echo e(__('Visão geral')); ?>

                    <?php $slotsb1900792061809836d7651c2d407b102['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slotsb1900792061809836d7651c2d407b102); ?>
<?php _b1900792061809836d7651c2d407b102($__blaze, $__attrsb1900792061809836d7651c2d407b102, $slotsb1900792061809836d7651c2d407b102, ['href', 'current', 'wire:navigate'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
                    <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/item.blade.php', $__blaze->compiledPath.'/b1900792061809836d7651c2d407b102.php'); ?>
<?php require_once $__blaze->compiledPath.'/b1900792061809836d7651c2d407b102.php'; ?>
<?php $__attrsb1900792061809836d7651c2d407b102 = ['icon' => 'globe-alt','href' => route('site.home')]; ?>
<?php $__blaze->pushData($__attrsb1900792061809836d7651c2d407b102); ?>
<?php $slotsb1900792061809836d7651c2d407b102 = []; ?>
<?php ob_start(); ?>
                        <?php echo e(__('Site institucional')); ?>

                    <?php $slotsb1900792061809836d7651c2d407b102['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slotsb1900792061809836d7651c2d407b102); ?>
<?php _b1900792061809836d7651c2d407b102($__blaze, $__attrsb1900792061809836d7651c2d407b102, $slotsb1900792061809836d7651c2d407b102, ['href'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
                    <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/item.blade.php', $__blaze->compiledPath.'/b1900792061809836d7651c2d407b102.php'); ?>
<?php require_once $__blaze->compiledPath.'/b1900792061809836d7651c2d407b102.php'; ?>
<?php $__attrsb1900792061809836d7651c2d407b102 = ['icon' => 'document-text','href' => route('proposal.create')]; ?>
<?php $__blaze->pushData($__attrsb1900792061809836d7651c2d407b102); ?>
<?php $slotsb1900792061809836d7651c2d407b102 = []; ?>
<?php ob_start(); ?>
                        <?php echo e(__('Nova proposta')); ?>

                    <?php $slotsb1900792061809836d7651c2d407b102['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slotsb1900792061809836d7651c2d407b102); ?>
<?php _b1900792061809836d7651c2d407b102($__blaze, $__attrsb1900792061809836d7651c2d407b102, $slotsb1900792061809836d7651c2d407b102, ['href'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
                    <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/sidebar/item.blade.php', $__blaze->compiledPath.'/b1900792061809836d7651c2d407b102.php'); ?>
<?php require_once $__blaze->compiledPath.'/b1900792061809836d7651c2d407b102.php'; ?>
<?php $__attrsb1900792061809836d7651c2d407b102 = ['icon' => 'folder-open','href' => route('site.ri')]; ?>
<?php $__blaze->pushData($__attrsb1900792061809836d7651c2d407b102); ?>
<?php $slotsb1900792061809836d7651c2d407b102 = []; ?>
<?php ob_start(); ?>
                        <?php echo e(__('Relações com investidores')); ?>

                    <?php $slotsb1900792061809836d7651c2d407b102['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slotsb1900792061809836d7651c2d407b102); ?>
<?php _b1900792061809836d7651c2d407b102($__blaze, $__attrsb1900792061809836d7651c2d407b102, $slotsb1900792061809836d7651c2d407b102, ['href'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
                <?php $slots422073a6c3c3549da54aa7ea2b89477a['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slots422073a6c3c3549da54aa7ea2b89477a); ?>
<?php _422073a6c3c3549da54aa7ea2b89477a($__blaze, $__attrs422073a6c3c3549da54aa7ea2b89477a, $slots422073a6c3c3549da54aa7ea2b89477a, ['heading'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
</nav>


            <div class="flex-1" data-flux-spacer></div>


            <div class="px-4 pb-4">
                <div class="bsi-shell-card-soft p-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-500"><?php echo e(__('Conta')); ?></div>
                    <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        Gerencie preferências, perfil e acesso ao ambiente interno da BSI Capital.
                    </div>
                </div>
            </div>

            <?php if (isset($component)) { $__componentOriginalca54afb14f8d43d7f1acc5dbe6164a0a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalca54afb14f8d43d7f1acc5dbe6164a0a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.desktop-user-menu','data' => ['class' => 'hidden lg:block','name' => auth()->user()->name]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('desktop-user-menu'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'hidden lg:block','name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(auth()->user()->name)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalca54afb14f8d43d7f1acc5dbe6164a0a)): ?>
<?php $attributes = $__attributesOriginalca54afb14f8d43d7f1acc5dbe6164a0a; ?>
<?php unset($__attributesOriginalca54afb14f8d43d7f1acc5dbe6164a0a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalca54afb14f8d43d7f1acc5dbe6164a0a)): ?>
<?php $component = $__componentOriginalca54afb14f8d43d7f1acc5dbe6164a0a; ?>
<?php unset($__componentOriginalca54afb14f8d43d7f1acc5dbe6164a0a); ?>
<?php endif; ?>
</ui-sidebar>


        <header class="[grid-area:header] z-10 min-h-14 flex items-center px-6 lg:px-8 border-b border-zinc-200/70 bg-white/90 backdrop-blur dark:border-white/10 dark:bg-[#08111dcc] lg:hidden" data-flux-header>
            <button type="button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg w-10 inline-flex -ms-2.5 bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white      shrink-0 lg:hidden" data-flux-button="data-flux-button" x-data="" x-on:click="$dispatch('flux-sidebar-toggle')" aria-label="Toggle sidebar" data-flux-sidebar-toggle="data-flux-sidebar-toggle">
        <svg class="shrink-0 [:where(&amp;)]:size-5" data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon">
  <path fill-rule="evenodd" d="M2 6.75A.75.75 0 0 1 2.75 6h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 6.75Zm0 6.5a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd"/>
</svg>
    </button>

            <div class="flex-1" data-flux-spacer></div>

            <div class="text-sm font-semibold tracking-[-0.02em] text-brand-700 dark:text-white"><?php echo e(__('Painel BSI Capital')); ?></div>
    </header>


        <?php echo e($slot); ?>


        <?php app('livewire')->forceAssetInjection(); ?>
<?php echo app('flux')->scripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()]); ?>

    </body>
</html>
<?php /**PATH /home/desktop/projects/bsi-capital/resources/views/layouts/app/sidebar.blade.php ENDPATH**/ ?>
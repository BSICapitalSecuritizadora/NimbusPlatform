<?php # [BlazeFolded]:{flux::icon.home}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/home.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.chart-bar}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/chart-bar.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.document-text}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/document-text.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<!doctype html>
<html lang="pt-br">
<head>
    <?php echo $__env->make('partials.head', ['title' => $title ?? trim($__env->yieldContent('title', 'Portal do Investidor'))], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</head>
<body class="bsi-investor-body min-h-screen antialiased">
    <script>
        document.documentElement.classList.remove('dark');
        document.documentElement.style.colorScheme = 'light';
    </script>

    <?php
        $investorUser = auth('investor')->user();
        $isAuthenticatedInvestor = $investorUser instanceof \App\Models\Investor;
        $portalInitial = $isAuthenticatedInvestor ? strtoupper(mb_substr($investorUser->name, 0, 1)) : 'I';
        $showInvestorHeader = $isAuthenticatedInvestor || ! request()->routeIs('investor.login');
    ?>

    <div class="relative min-h-screen overflow-hidden">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[420px] bg-[radial-gradient(circle_at_top_left,rgba(0,32,91,0.16),transparent_36%),radial-gradient(circle_at_top_right,rgba(212,175,55,0.18),transparent_24%)]"></div>
        <div class="pointer-events-none absolute left-0 top-[18rem] h-[360px] w-[360px] rounded-full bg-brand-100/55 blur-3xl"></div>
        <div class="pointer-events-none absolute right-0 top-[7rem] h-[280px] w-[280px] rounded-full bg-gold-400/12 blur-3xl"></div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showInvestorHeader): ?>
            <header class="relative z-20">
                <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                    <div class="bsi-investor-header-surface flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex items-center justify-between gap-4">
                            <a href="<?php echo e($isAuthenticatedInvestor ? route('investor.dashboard') : route('investor.login')); ?>" class="flex min-w-0 items-center no-underline">
                                <img src="<?php echo e(asset('images/logo-mob.png')); ?>" alt="BSI Capital" style="max-height: 44px;">
                            </a>
                        </div>

                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isAuthenticatedInvestor): ?>
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-end">
                                <nav class="flex flex-wrap items-center gap-2">
                                    <a href="<?php echo e(route('investor.dashboard')); ?>" class="bsi-portal-nav-link <?php echo e(request()->routeIs('investor.dashboard') ? 'bsi-portal-nav-link-active' : ''); ?>">
                                        <svg class="shrink-0 [:where(&amp;)]:size-6 size-4" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>
</svg>

        
                                        <span>Início</span>
                                    </a>
                                    <a href="<?php echo e(route('investor.emissions')); ?>" class="bsi-portal-nav-link <?php echo e(request()->routeIs('investor.emissions') ? 'bsi-portal-nav-link-active' : ''); ?>">
                                        <svg class="shrink-0 [:where(&amp;)]:size-6 size-4" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
</svg>

        
                                        <span>Minhas emissões</span>
                                    </a>
                                    <a href="<?php echo e(route('investor.documents')); ?>" class="bsi-portal-nav-link <?php echo e(request()->routeIs('investor.documents*') ? 'bsi-portal-nav-link-active' : ''); ?>">
                                        <svg class="shrink-0 [:where(&amp;)]:size-6 size-4" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
</svg>

        
                                        <span>Documentos</span>
                                    </a>
                                </nav>

                                <div class="flex items-center gap-3 rounded-full border border-brand-100 bg-brand-50/80 px-3 py-2">
                                    <span class="flex size-10 items-center justify-center rounded-full bg-brand-700 text-sm font-bold text-white">
                                        <?php echo e($portalInitial); ?>

                                    </span>
                                    <div class="hidden min-w-0 sm:block">
                                        <div class="truncate text-sm font-semibold text-brand-800"><?php echo e($investorUser->name); ?></div>
                                        <div class="truncate text-xs text-zinc-500"><?php echo e($investorUser->email); ?></div>
                                    </div>
                                    <form method="POST" action="<?php echo e(route('investor.logout')); ?>">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-8 text-sm rounded-md px-3 inline-flex  bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white    *:transition-opacity [&amp;[disabled]&gt;:not([data-flux-loading-indicator])]:opacity-0 [&amp;[disabled]&gt;[data-flux-loading-indicator]]:opacity-100 [&amp;[disabled]]:pointer-events-none  !rounded-full !px-4" data-flux-button="data-flux-button">
        <div class="absolute inset-0 flex items-center justify-center opacity-0" data-flux-loading-indicator>
                <svg class="shrink-0 [:where(&amp;)]:size-4 animate-spin" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true" data-slot="icon">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
</svg>
        
            </div>
        
        
                    
            
            <span>Sair</span>
    </button>

                                    </form>
                                </div>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            </header>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <main class="relative z-10 mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 <?php echo e($showInvestorHeader ? '' : 'flex min-h-screen items-center justify-center'); ?>">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success')): ?>
                <div class="mb-6 rounded-[24px] border border-emerald-200 bg-emerald-50/90 px-5 py-4 text-sm font-medium text-emerald-700 shadow-sm">
                    <?php echo e(session('success')); ?>

                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('error')): ?>
                <div class="mb-6 rounded-[24px] border border-red-200 bg-red-50/90 px-5 py-4 text-sm font-medium text-red-700 shadow-sm">
                    <?php echo e(session('error')); ?>

                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if (! empty(trim($__env->yieldContent('content')))): ?>
                <?php echo $__env->yieldContent('content'); ?>
            <?php else: ?>
                <?php echo e($slot ?? ''); ?>

            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </main>
    </div>

    <?php app('livewire')->forceAssetInjection(); ?>
<?php echo app('flux')->scripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()]); ?>

</body>
</html>
<?php /**PATH /home/desktop/projects/bsi-capital/resources/views/investor/layout.blade.php ENDPATH**/ ?>
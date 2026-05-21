<?php # [BlazeFolded]:{flux::label}:{/var/www/html/vendor/livewire/flux/src/../stubs/resources/views/flux/label.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::input}:{/var/www/html/vendor/livewire/flux/src/../stubs/resources/views/flux/input/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::field}:{/var/www/html/vendor/livewire/flux/src/../stubs/resources/views/flux/field.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::label}:{/var/www/html/vendor/livewire/flux/src/../stubs/resources/views/flux/label.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::input}:{/var/www/html/vendor/livewire/flux/src/../stubs/resources/views/flux/input/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::field}:{/var/www/html/vendor/livewire/flux/src/../stubs/resources/views/flux/field.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.lock-closed}:{/var/www/html/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/lock-closed.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::icon.arrow-up-right}:{/var/www/html/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/arrow-up-right.blade.php}:{1771950596} ?>


<?php $__env->startSection('title', 'Login - Portal do Investidor'); ?>

<?php $__env->startPush('head'); ?>
<style>
    .bsi-investor-login-form [data-flux-field] {
        gap: 0.65rem;
    }

    .bsi-investor-login-form [data-flux-label] {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #091b23;
    }

    .bsi-investor-login-form .bsi-investor-credential-field {
        gap: 0.45rem;
        padding: 0.95rem 1rem;
        border: 2px solid var(--color-brand-700);
        border-radius: 1rem;
        background: var(--color-white);
    }

    .bsi-investor-login-form .bsi-investor-credential-field:focus-within {
        border-color: var(--color-brand-700);
        box-shadow: 0 0 0 3px rgba(9, 27, 35, 0.12);
    }

    .bsi-investor-login-form .bsi-investor-credential-field input[data-flux-control]:not([type='checkbox']) {
        min-height: auto;
        border: 0 !important;
        background: transparent !important;
        color: #091b23 !important;
        padding-inline: 0;
        box-shadow: none !important;
    }

    .bsi-investor-login-form .bsi-investor-credential-field input[data-flux-control]::placeholder {
        color: #8b9398;
        opacity: 1;
    }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<section class="mx-auto w-full max-w-[34rem]">
    <div class="bsi-investor-form-card p-8 lg:p-10">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <img src="<?php echo e(asset('images/logo-mob.png')); ?>" alt="BSI Capital" class="h-11 w-auto">
            <a href="<?php echo e(route('site.home')); ?>" class="inline-flex items-center justify-center rounded-full border border-brand-100 bg-brand-50/80 px-4 py-2.5 text-sm font-semibold text-brand-700 transition hover:border-gold-500/50 hover:bg-white">
                Voltar ao site
            </a>
        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
            <div class="mb-6 rounded-[22px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
                <?php echo e($errors->first()); ?>

            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <form method="POST" action="<?php echo e(route('investor.login.post')); ?>" class="bsi-investor-login-form space-y-5">
            <?php echo csrf_field(); ?>

            <div class="space-y-4">
                <ui-field class="min-w-0 [&amp;:not(:has([data-flux-field])):has([data-flux-control][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-radio-group][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-checkbox-group][disabled])&gt;[data-flux-label]]:opacity-50 block *:data-flux-label:mb-3 [&amp;&gt;[data-flux-label]:has(+[data-flux-description])]:mb-2 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mt-0 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mb-3 [&amp;&gt;*:not([data-flux-label])+[data-flux-description]]:mt-3 bsi-investor-credential-field" data-flux-field>
    <ui-label class="inline-flex items-center text-sm font-medium  [:where(&amp;)]:text-zinc-800 [:where(&amp;)]:dark:text-white [&amp;:has([data-flux-label-trailing])]:flex" data-flux-label>
    E-mail

    
    
    </ui-label>

                    <div class="w-full relative block group/input !rounded-[18px]" data-flux-input>
            
            <input
                type="email"
                
                class="w-full border rounded-lg block disabled:shadow-none dark:shadow-none appearance-none text-base sm:text-sm py-2 h-10 leading-[1.375rem] ps-3 pe-3 bg-white dark:bg-white/10 dark:disabled:bg-white/[7%] text-zinc-700 disabled:text-zinc-500 placeholder-zinc-400 disabled:placeholder-zinc-400/70 dark:text-zinc-300 dark:disabled:text-zinc-400 dark:placeholder-zinc-400 dark:disabled:placeholder-zinc-500 shadow-xs border-zinc-200 border-b-zinc-300/80 disabled:border-b-zinc-200 dark:border-white/10 dark:disabled:border-white/5 data-invalid:shadow-none data-invalid:border-red-500 dark:data-invalid:border-red-500 disabled:data-invalid:border-red-500 dark:disabled:data-invalid:border-red-500" value="<?php echo e(old('email')); ?>" placeholder="email@empresa.com.br" required="required" autofocus="autofocus" autocomplete="email"
                 name="email"                                                 <?php if (isset($scope)) $__scope = $scope; ?><?php $scope = array (
  'name' => 'email',
); ?>
                <?php if ($scope['name'] && $errors->has($scope['name'])): ?>
                aria-invalid="true" data-invalid
                <?php endif; ?>
                <?php if (isset($__scope)) { $scope = $__scope; unset($__scope); } ?>
                data-flux-control
                data-flux-group-target
                                            >

                    </div>
</ui-field>


                <ui-field class="min-w-0 [&amp;:not(:has([data-flux-field])):has([data-flux-control][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-radio-group][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-checkbox-group][disabled])&gt;[data-flux-label]]:opacity-50 block *:data-flux-label:mb-3 [&amp;&gt;[data-flux-label]:has(+[data-flux-description])]:mb-2 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mt-0 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mb-3 [&amp;&gt;*:not([data-flux-label])+[data-flux-description]]:mt-3 bsi-investor-credential-field" data-flux-field>
    <ui-label class="inline-flex items-center text-sm font-medium  [:where(&amp;)]:text-zinc-800 [:where(&amp;)]:dark:text-white [&amp;:has([data-flux-label-trailing])]:flex" data-flux-label>
    Senha

    
    
    </ui-label>

                    <div class="w-full relative block group/input !rounded-[18px]" data-flux-input>
            
            <input
                type="password"
                
                class="w-full border rounded-lg block disabled:shadow-none dark:shadow-none appearance-none text-base sm:text-sm py-2 h-10 leading-[1.375rem] ps-3 pe-10 bg-white dark:bg-white/10 dark:disabled:bg-white/[7%] text-zinc-700 disabled:text-zinc-500 placeholder-zinc-400 disabled:placeholder-zinc-400/70 dark:text-zinc-300 dark:disabled:text-zinc-400 dark:placeholder-zinc-400 dark:disabled:placeholder-zinc-500 shadow-xs border-zinc-200 border-b-zinc-300/80 disabled:border-b-zinc-200 dark:border-white/10 dark:disabled:border-white/5 data-invalid:shadow-none data-invalid:border-red-500 dark:data-invalid:border-red-500 disabled:data-invalid:border-red-500 dark:disabled:data-invalid:border-red-500" placeholder="Informe sua senha" required="required" autocomplete="current-password"
                 name="password"                                                 <?php if (isset($scope)) $__scope = $scope; ?><?php $scope = array (
  'name' => 'password',
); ?>
                <?php if ($scope['name'] && $errors->has($scope['name'])): ?>
                aria-invalid="true" data-invalid
                <?php endif; ?>
                <?php if (isset($__scope)) { $scope = $__scope; unset($__scope); } ?>
                data-flux-control
                data-flux-group-target
                                            >

                            <div class="absolute top-0 bottom-0 flex items-center gap-x-1.5 pe-2 border-e border-transparent end-0 text-xs text-zinc-400">
                    
                    
                    
                    
                    
                    
                                            <button type="button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-8 text-sm rounded-md w-8 inline-flex -ms-1.5 -me-1.5 bg-transparent hover:bg-zinc-800/5 dark:hover:bg-white/15 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white      -me-1" data-flux-button="data-flux-button" x-data="fluxInputViewable" x-on:click="toggle()" x-bind:data-viewable-open="open" aria-label="Toggle password visibility">
        <svg class="shrink-0 [:where(&amp;)]:size-4 hidden [[data-viewable-open]>&]:block" data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon">
  <path fill-rule="evenodd" d="M3.28 2.22a.75.75 0 0 0-1.06 1.06l10.5 10.5a.75.75 0 1 0 1.06-1.06l-1.322-1.323a7.012 7.012 0 0 0 2.16-3.11.87.87 0 0 0 0-.567A7.003 7.003 0 0 0 4.82 3.76l-1.54-1.54Zm3.196 3.195 1.135 1.136A1.502 1.502 0 0 1 9.45 8.389l1.136 1.135a3 3 0 0 0-4.109-4.109Z" clip-rule="evenodd"/>
  <path d="m7.812 10.994 1.816 1.816A7.003 7.003 0 0 1 1.38 8.28a.87.87 0 0 1 0-.566 6.985 6.985 0 0 1 1.113-2.039l2.513 2.513a3 3 0 0 0 2.806 2.806Z"/>
</svg>

            <svg class="shrink-0 [:where(&amp;)]:size-4 block [[data-viewable-open]>&]:hidden" data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon">
  <path d="M8 9.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>
  <path fill-rule="evenodd" d="M1.38 8.28a.87.87 0 0 1 0-.566 7.003 7.003 0 0 1 13.238.006.87.87 0 0 1 0 .566A7.003 7.003 0 0 1 1.379 8.28ZM11 8a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" clip-rule="evenodd"/>
</svg>
    </button>
                    
                                    </div>
                    </div>
</ui-field>

            </div>

            <div class="flex flex-col gap-3 rounded-[22px] border border-zinc-200/80 bg-zinc-50/80 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                <label class="group flex cursor-pointer items-center gap-3">
                    <div class="relative flex items-center justify-center">
                        <input type="checkbox" name="remember" class="peer size-5 cursor-pointer appearance-none rounded-md border border-zinc-300 bg-white transition hover:border-brand-500 checked:border-brand-700 checked:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500/20" <?php echo e(old('remember') ? 'checked' : ''); ?>>
                        <svg class="pointer-events-none absolute size-3.5 text-white opacity-0 transition peer-checked:opacity-100" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                    <span class="text-[0.95rem] font-medium text-zinc-700 transition group-hover:text-zinc-900">Manter conectado</span>
                </label>
                <div class="inline-flex items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-zinc-500">
                    <svg class="shrink-0 [:where(&amp;)]:size-6 size-4 text-gold-500" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
</svg>

        
                    <span>Conexão protegida</span>
                </div>
            </div>

            <button type="submit" class="mt-2 w-full rounded-full bg-brand-700 py-3.5 text-[0.95rem] font-semibold text-white shadow-sm transition hover:bg-brand-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-700">
                Entrar
            </button>
        </form>

        <div class="mt-8 rounded-[24px] border border-brand-100 bg-[linear-gradient(180deg,rgba(239,242,243,0.95),rgba(230,228,228,0.92))] p-5">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-gold-500">Canal institucional</div>
            <p class="mt-3 text-sm leading-7 text-zinc-600">
                Se precisar de ajuda com acesso, atualização cadastral ou validação do seu escopo, fale com a equipe da BSI Capital.
            </p>
            <a href="mailto:contato@bsicapital.com.br" class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-brand-700 transition hover:text-brand-800">
                contato@bsicapital.com.br
                <svg class="shrink-0 [:where(&amp;)]:size-6 size-4" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon">
  <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 19.5 15-15m0 0H8.25m11.25 0v11.25"/>
</svg>

        
            </a>
        </div>
    </div>
</section>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('investor.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/investor/auth/login.blade.php ENDPATH**/ ?>
<?php # [BlazeFolded]:{flux::navlist}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/navlist/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::separator}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/separator.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::heading}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/heading.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::subheading}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/subheading.blade.php}:{1771950596} ?>
<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <?php $__blaze->pushData(['ariaLabel' => e(__('Configurações'))]); $__env->pushConsumableComponentData(['ariaLabel' => e(__('Configurações'))]); ?><nav class="flex flex-col overflow-visible min-h-auto" aria-label="<?php echo e(__('Configurações')); ?>" data-flux-navlist>
    <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/navlist/item.blade.php', $__blaze->compiledPath.'/bb4c7b5951cba811ddd9e2b921465f4e.php'); ?>
<?php require_once $__blaze->compiledPath.'/bb4c7b5951cba811ddd9e2b921465f4e.php'; ?>
<?php $__attrsbb4c7b5951cba811ddd9e2b921465f4e = ['href' => route('profile.edit'),'wire:navigate' => true]; ?>
<?php $__blaze->pushData($__attrsbb4c7b5951cba811ddd9e2b921465f4e); ?>
<?php $slotsbb4c7b5951cba811ddd9e2b921465f4e = []; ?>
<?php ob_start(); ?><?php echo e(__('Perfil')); ?><?php $slotsbb4c7b5951cba811ddd9e2b921465f4e['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slotsbb4c7b5951cba811ddd9e2b921465f4e); ?>
<?php _bb4c7b5951cba811ddd9e2b921465f4e($__blaze, $__attrsbb4c7b5951cba811ddd9e2b921465f4e, $slotsbb4c7b5951cba811ddd9e2b921465f4e, ['href', 'wire:navigate'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(Laravel\Fortify\Features::canManageTwoFactorAuthentication()): ?>
                <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/navlist/item.blade.php', $__blaze->compiledPath.'/bb4c7b5951cba811ddd9e2b921465f4e.php'); ?>
<?php require_once $__blaze->compiledPath.'/bb4c7b5951cba811ddd9e2b921465f4e.php'; ?>
<?php $__attrsbb4c7b5951cba811ddd9e2b921465f4e = ['href' => route('two-factor.show'),'wire:navigate' => true]; ?>
<?php $__blaze->pushData($__attrsbb4c7b5951cba811ddd9e2b921465f4e); ?>
<?php $slotsbb4c7b5951cba811ddd9e2b921465f4e = []; ?>
<?php ob_start(); ?><?php echo e(__('Autenticação em dois fatores')); ?><?php $slotsbb4c7b5951cba811ddd9e2b921465f4e['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slotsbb4c7b5951cba811ddd9e2b921465f4e); ?>
<?php _bb4c7b5951cba811ddd9e2b921465f4e($__blaze, $__attrsbb4c7b5951cba811ddd9e2b921465f4e, $slotsbb4c7b5951cba811ddd9e2b921465f4e, ['href', 'wire:navigate'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/navlist/item.blade.php', $__blaze->compiledPath.'/bb4c7b5951cba811ddd9e2b921465f4e.php'); ?>
<?php require_once $__blaze->compiledPath.'/bb4c7b5951cba811ddd9e2b921465f4e.php'; ?>
<?php $__attrsbb4c7b5951cba811ddd9e2b921465f4e = ['href' => route('appearance.edit'),'wire:navigate' => true]; ?>
<?php $__blaze->pushData($__attrsbb4c7b5951cba811ddd9e2b921465f4e); ?>
<?php $slotsbb4c7b5951cba811ddd9e2b921465f4e = []; ?>
<?php ob_start(); ?><?php echo e(__('Aparência')); ?><?php $slotsbb4c7b5951cba811ddd9e2b921465f4e['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slotsbb4c7b5951cba811ddd9e2b921465f4e); ?>
<?php _bb4c7b5951cba811ddd9e2b921465f4e($__blaze, $__attrsbb4c7b5951cba811ddd9e2b921465f4e, $slotsbb4c7b5951cba811ddd9e2b921465f4e, ['href', 'wire:navigate'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
</nav>
<?php $__blaze->popData(); $__env->popConsumableComponentData(); ?>
    </div>

    <div data-orientation="horizontal" role="none" class="border-0 [print-color-adjust:exact] bg-zinc-800/15 dark:bg-white/20 h-px w-full md:hidden" data-flux-separator></div>


    <div class="flex-1 self-stretch max-md:pt-6">
        <div class="font-medium [:where(&amp;)]:text-zinc-800 [:where(&amp;)]:dark:text-white text-sm [&amp;:has(+[data-flux-subheading])]:mb-2 [[data-flux-subheading]+&amp;]:mt-2" data-flux-heading><?php echo e($heading ?? ''); ?></div>

        <div class="text-sm [:where(&amp;)]:text-zinc-500 [:where(&amp;)]:dark:text-white/70" data-flux-subheading>
    <?php echo e($subheading ?? ''); ?>

</div>


        <div class="mt-5 w-full max-w-lg">
            <?php echo e($slot); ?>

        </div>
    </div>
</div>
<?php /**PATH /home/desktop/projects/bsi-capital/resources/views/pages/settings/layout.blade.php ENDPATH**/ ?>
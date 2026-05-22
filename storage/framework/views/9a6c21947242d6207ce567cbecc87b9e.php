<?php # [BlazeFolded]:{flux::heading}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/heading.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::subheading}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/subheading.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::button}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::modal.trigger}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/modal/trigger.blade.php}:{1771950596} ?>
<?php
use Livewire\Component;
?>

<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <div class="font-medium [:where(&amp;)]:text-zinc-800 [:where(&amp;)]:dark:text-white text-sm [&amp;:has(+[data-flux-subheading])]:mb-2 [[data-flux-subheading]+&amp;]:mt-2" data-flux-heading><?php echo e(__('Excluir conta')); ?></div>

        <div class="text-sm [:where(&amp;)]:text-zinc-500 [:where(&amp;)]:dark:text-white/70" data-flux-subheading>
    <?php echo e(__('Exclua permanentemente sua conta e todos os dados associados')); ?>

</div>

    </div>

    <div
    class="contents"
    x-data
    x-on:click="$el.querySelector('button[disabled]') || $dispatch('modal-show', { name: 'confirm-user-deletion' })"
        data-flux-modal-trigger
>
    <button type="button" class="relative items-center font-medium justify-center gap-2 whitespace-nowrap disabled:opacity-75 dark:disabled:opacity-75 disabled:cursor-default disabled:pointer-events-none justify-center h-10 text-sm rounded-lg ps-4 pe-4 inline-flex  bg-red-500 hover:bg-red-600 dark:bg-red-600 dark:hover:bg-red-500 text-white  shadow-[inset_0px_1px_var(--color-red-500),inset_0px_2px_--theme(--color-white/.15)] dark:shadow-none [[data-flux-button-group]_&amp;]:border-e [:is([data-flux-button-group]&gt;&amp;:last-child,_[data-flux-button-group]_:last-child&gt;&amp;)]:border-e-0 [[data-flux-button-group]_&amp;]:border-red-600 dark:[[data-flux-button-group]_&amp;]:border-red-900/25" data-flux-button="data-flux-button" data-flux-group-target="data-flux-group-target" data-test="delete-user-button">
        <?php echo e(__('Excluir conta')); ?>

    </button>
</div>


    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('pages::settings.delete-user-modal', []);

$__keyOuter = $__key ?? null;

$__key = null;
$__componentSlots = [];

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3306872191-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key, $__componentSlots);

echo $__html;

unset($__html);
unset($__key);
$__key = $__keyOuter;
unset($__keyOuter);
unset($__name);
unset($__params);
unset($__componentSlots);
unset($__split);
?>
</section><?php /**PATH /home/desktop/projects/bsi-capital/storage/framework/views/livewire/views/1c22ca5a.blade.php ENDPATH**/ ?>
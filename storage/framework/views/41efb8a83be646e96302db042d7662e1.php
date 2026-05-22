<?php # [BlazeFolded]:{flux::heading}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/heading.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::subheading}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/subheading.blade.php}:{1771950596} ?>
<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'title',
    'description',
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'title',
    'description',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<div class="flex w-full flex-col text-center">
    <div class="font-medium [:where(&amp;)]:text-zinc-800 [:where(&amp;)]:dark:text-white text-2xl [&amp;:has(+[data-flux-subheading])]:mb-2 [[data-flux-subheading]+&amp;]:mt-2" data-flux-heading><?php echo e($title); ?></div>

    <div class="text-sm [:where(&amp;)]:text-zinc-500 [:where(&amp;)]:dark:text-white/70" data-flux-subheading>
    <?php echo e($description); ?>

</div>

</div>
<?php /**PATH /home/desktop/projects/bsi-capital/resources/views/components/auth-header.blade.php ENDPATH**/ ?>
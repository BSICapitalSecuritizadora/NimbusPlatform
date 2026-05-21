<?php
if (!function_exists('_0f1f746c98e53c79b306a00415c6748f')):
function _0f1f746c98e53c79b306a00415c6748f($__blaze, $__data = [], $__slots = [], $__bound = [], $__this = null) {
$__env = $__blaze->env;
$__slots['slot'] ??= new \Illuminate\View\ComponentSlot('');
if (($__data['attributes'] ?? null) instanceof \Illuminate\View\ComponentAttributeBag) { $__data = $__data + $__data['attributes']->all(); unset($__data['attributes']); }
$attributes = \Livewire\Blaze\Runtime\BlazeAttributeBag::sanitized($__data, $__bound);
extract($__slots, EXTR_SKIP); unset($__slots);
extract($__data, EXTR_SKIP); unset($__data, $__bound);
ob_start();
?>


<?php
$__defaults = [
    'kbd' => null,
];
$kbd ??= $attributes['kbd'] ?? $__defaults['kbd']; unset($attributes['kbd']);
unset($__defaults);
?>

<?php
$classes = Flux::classes([
    'relative py-2 px-2.5',
    'rounded-md',
    'text-xs text-white font-medium',
    'bg-zinc-800 dark:bg-zinc-700 dark:border dark:border-white/10',
    'p-0 overflow-visible',
]);
?>

<div popover="manual" <?php echo e($attributes->class($classes)); ?> data-flux-tooltip-content>
    <?php echo e($slot); ?>


    <?php if ($kbd): ?>
        <span class="ps-1 text-zinc-300"><?php echo e($kbd); ?></span>
    <?php endif; ?>
</div>
<?php
echo ltrim(ob_get_clean());
} endif; ?><?php /**PATH /home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/tooltip/content.blade.php ENDPATH**/ ?>
<?php
if (!function_exists('_09500ee2d7ee660b62f99a913b9d4f66')):
function _09500ee2d7ee660b62f99a913b9d4f66($__blaze, $__data = [], $__slots = [], $__bound = [], $__this = null) {
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
    'interactive' => null,
    'position' => 'top',
    'align' => 'center',
    'content' => null,
    'kbd' => null,
    'toggleable' => null,
];
$interactive ??= $attributes['interactive'] ?? $__defaults['interactive']; unset($attributes['interactive']);
$position ??= $attributes['position'] ?? $__defaults['position']; unset($attributes['position']);
$align ??= $attributes['align'] ?? $__defaults['align']; unset($attributes['align']);
$content ??= $attributes['content'] ?? $__defaults['content']; unset($attributes['content']);
$kbd ??= $attributes['kbd'] ?? $__defaults['kbd']; unset($attributes['kbd']);
$toggleable ??= $attributes['toggleable'] ?? $__defaults['toggleable']; unset($attributes['toggleable']);
unset($__defaults);
?>

<?php
// Support adding the .self modifier to the wire:model directive...
if (($wireModel = $attributes->wire('model')) && $wireModel->directive && ! $wireModel->hasModifier('self')) {
    unset($attributes[$wireModel->directive]);

    $wireModel->directive .= '.self';

    $attributes = $attributes->merge([$wireModel->directive => $wireModel->value]);
}
?>

<?php if ($toggleable): ?>
    <ui-dropdown position="<?php echo e($position); ?> <?php echo e($align); ?>" <?php echo e($attributes); ?> data-flux-tooltip>
        <?php echo e($slot); ?>


        <?php if ($content !== null): ?>
            <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/tooltip/content.blade.php', $__blaze->compiledPath.'/0f1f746c98e53c79b306a00415c6748f.php'); ?>
<?php require_once $__blaze->compiledPath.'/0f1f746c98e53c79b306a00415c6748f.php'; ?>
<?php $__attrs0f1f746c98e53c79b306a00415c6748f = ['kbd' => $kbd]; ?>
<?php $__blaze->pushData($__attrs0f1f746c98e53c79b306a00415c6748f); ?>
<?php $slots0f1f746c98e53c79b306a00415c6748f = []; ?>
<?php ob_start(); ?><?php echo e($content); ?><?php $slots0f1f746c98e53c79b306a00415c6748f['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slots0f1f746c98e53c79b306a00415c6748f); ?>
<?php _0f1f746c98e53c79b306a00415c6748f($__blaze, $__attrs0f1f746c98e53c79b306a00415c6748f, $slots0f1f746c98e53c79b306a00415c6748f, ['kbd'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
        <?php endif; ?>
    </ui-dropdown>
<?php else: ?>
    <ui-tooltip position="<?php echo e($position); ?> <?php echo e($align); ?>" <?php echo e($attributes); ?> data-flux-tooltip <?php if($interactive): ?> interactive <?php endif; ?>>
        <?php echo e($slot); ?>


        <?php if ($content !== null): ?>
            <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/tooltip/content.blade.php', $__blaze->compiledPath.'/0f1f746c98e53c79b306a00415c6748f.php'); ?>
<?php require_once $__blaze->compiledPath.'/0f1f746c98e53c79b306a00415c6748f.php'; ?>
<?php $__attrs0f1f746c98e53c79b306a00415c6748f = ['kbd' => $kbd]; ?>
<?php $__blaze->pushData($__attrs0f1f746c98e53c79b306a00415c6748f); ?>
<?php $slots0f1f746c98e53c79b306a00415c6748f = []; ?>
<?php ob_start(); ?><?php echo e($content); ?><?php $slots0f1f746c98e53c79b306a00415c6748f['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $__blaze->pushSlots($slots0f1f746c98e53c79b306a00415c6748f); ?>
<?php _0f1f746c98e53c79b306a00415c6748f($__blaze, $__attrs0f1f746c98e53c79b306a00415c6748f, $slots0f1f746c98e53c79b306a00415c6748f, ['kbd'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
        <?php endif; ?>
    </ui-tooltip>
<?php endif; ?>
<?php
echo ltrim(ob_get_clean());
} endif; ?><?php /**PATH /home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/tooltip/index.blade.php ENDPATH**/ ?>
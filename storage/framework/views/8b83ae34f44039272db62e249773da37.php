<?php
if (!function_exists('_8b83ae34f44039272db62e249773da37')):
function _8b83ae34f44039272db62e249773da37($__blaze, $__data = [], $__slots = [], $__bound = [], $__this = null) {
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
    'icon' => null,
    'name' => null,
];
$icon ??= $attributes['icon'] ?? $__defaults['icon']; unset($attributes['icon']);
$name ??= $attributes['name'] ?? $__defaults['name']; unset($attributes['name']);
unset($__defaults);
?>

<?php
$icon = $name ?? $icon;
?>

<?php $__resolved = $__blaze->resolve('flux::' . 'icon.' . $icon); ?>
<?php $__blaze->pushData($attributes->all()); ?>
<?php if ($__resolved !== false): ?>
<?php require_once $__blaze->compiledPath . '/' . $__resolved . '.php'; ?><?php $slots74b822470731d899a13e543ebd12c785 = []; ?>
<?php ob_start(); ?><?php echo e($slot); ?><?php $slots74b822470731d899a13e543ebd12c785['slot'] = new \Illuminate\View\ComponentSlot(trim(ob_get_clean()), []); ?>
<?php $slots74b822470731d899a13e543ebd12c785 = array_merge($__blaze->mergedComponentSlots(), $slots74b822470731d899a13e543ebd12c785); ?>
<?php ('_' . $__resolved)($__blaze, $attributes->all(), $slots74b822470731d899a13e543ebd12c785, [], isset($this) ? $this : null); ?>
<?php else: ?>
<?php if (!Flux::componentExists($name = 'icon.' . $icon)) throw new \Exception("Flux component [{$name}] does not exist."); ?><?php if (isset($component)) { $__componentOriginal99f5bdde02e072cb5fe2c95dd124b389 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal99f5bdde02e072cb5fe2c95dd124b389 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve([
    'view' => (app()->version() >= 12 ? hash('xxh128', 'flux') : md5('flux')) . '::' . 'icon.' . $icon,
    'data' => $__env->getCurrentComponentData(),
] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::' . 'icon.' . $icon); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php $component->withAttributes($attributes->getAttributes()); ?><?php echo e($slot); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal99f5bdde02e072cb5fe2c95dd124b389)): ?>
<?php $attributes = $__attributesOriginal99f5bdde02e072cb5fe2c95dd124b389; ?>
<?php unset($__attributesOriginal99f5bdde02e072cb5fe2c95dd124b389); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal99f5bdde02e072cb5fe2c95dd124b389)): ?>
<?php $component = $__componentOriginal99f5bdde02e072cb5fe2c95dd124b389; ?>
<?php unset($__componentOriginal99f5bdde02e072cb5fe2c95dd124b389); ?>
<?php endif; ?>
<?php endif; ?>
<?php $__blaze->popData(); ?>
<?php unset($__resolved) ?>

<?php
echo ltrim(ob_get_clean());
} endif; ?><?php /**PATH /home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/icon/index.blade.php ENDPATH**/ ?>
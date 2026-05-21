<?php
if (!function_exists('_69c7d158fd870b6136873607740a035f')):
function _69c7d158fd870b6136873607740a035f($__blaze, $__data = [], $__slots = [], $__bound = [], $__this = null) {
$__env = $__blaze->env;
$__slots['slot'] ??= new \Illuminate\View\ComponentSlot('');
if (($__data['attributes'] ?? null) instanceof \Illuminate\View\ComponentAttributeBag) { $__data = $__data + $__data['attributes']->all(); unset($__data['attributes']); }
$attributes = \Livewire\Blaze\Runtime\BlazeAttributeBag::sanitized($__data, $__bound);
extract($__slots, EXTR_SKIP); unset($__slots);
extract($__data, EXTR_SKIP); unset($__data, $__bound);
ob_start();
?>


<?php
extract(Flux::forwardedAttributes($attributes, [
    'type',
    'current',
    'href',
    'as',
]));
?>

<?php
$__defaults = [
    'type' => 'button',
    'current' => null,
    'href' => null,
    'as' => null,
];
$type ??= $attributes['type'] ?? $__defaults['type']; unset($attributes['type']);
$current ??= $attributes['current'] ?? $__defaults['current']; unset($attributes['current']);
$href ??= $attributes['href'] ?? $__defaults['href']; unset($attributes['href']);
$as ??= $attributes['as'] ?? $__defaults['as']; unset($attributes['as']);
unset($__defaults);
?>

<?php
if ($as !== 'div' || $href) {
    if ($current !== null) {
        // If the user manually specified :current="true/false", we need to stop Livewire from managing
        // the data-current attribute as it would be automatically added/removed when using wire:navigate...
        $attributes = $attributes->merge(['data-current' => $current, 'wire:current.ignore' => true]);
    } else {
        $hrefForCurrentDetection = str($href)->startsWith(trim(config('app.url')))
            ? (string) str($href)->after(trim(config('app.url'), '/'))
            : $href;

        if ($hrefForCurrentDetection === '') $hrefForCurrentDetection = '/';

        $requestIs = function ($pattern) {
            // Support current route detection during Livewire update requests as well...
            return app('livewire')?->isLivewireRequest()
                ? str()->is($pattern, app('livewire')->originalPath())
                : request()->is($pattern);
        };

        $current = $hrefForCurrentDetection
            ? $requestIs($hrefForCurrentDetection === '/' ? '/' : trim($hrefForCurrentDetection, '/'))
            : false;

        $attributes = $attributes->merge(['data-current' => $current]);
    }
}
?>

<?php if ($as === 'div' && ! $href): ?>
    <div <?php echo e($attributes); ?>>
        <?php echo e($slot); ?>

    </div>
<?php elseif ($as === 'a' || $href): ?>
    
    <a href="<?php echo e($href); ?>" <?php echo e($attributes); ?>>
        <?php echo e($slot); ?>

    </a>
<?php else: ?>
    <button <?php echo e($attributes->merge(['type' => $type])); ?>>
        <?php echo e($slot); ?>

    </button>
<?php endif; ?>
<?php
echo ltrim(ob_get_clean());
} endif; ?><?php /**PATH /home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/button-or-link.blade.php ENDPATH**/ ?>
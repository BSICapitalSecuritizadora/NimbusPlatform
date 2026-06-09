<?php # [BlazeFolded]:{flux::input}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/input/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::input}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/input/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::select.option}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/select/option/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::select}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/select/index.blade.php}:{1771950596} ?>
<?php # [BlazeFolded]:{flux::input}:{/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/input/index.blade.php}:{1771950596} ?>
<?php
use Livewire\Component;
?>

<div class="bg-white p-6 p-lg-8 rounded-4 shadow-xl border border-light">
    <div class="row g-5 align-items-center">
        <div class="col-lg-7">
            <div class="mb-4">
                <h3 class="h4 fw-bold text-dark mb-3">Simulador de Viabilidade CRI</h3>
                <p class="text-muted">Informe o VGV (Valor Global de Venda) do seu projeto para uma estimativa inicial de captação via securitização.</p>
            </div>
            
            <div class="mb-4">
                <label class="form-label small fw-bold text-uppercase text-muted mb-2">VGV do Projeto (R$)</label>
                <div class="w-full relative block group/input" data-flux-input>
            
            <input
                type="text"
                
                class="w-full border rounded-lg block disabled:shadow-none dark:shadow-none appearance-none text-base sm:text-sm py-2 h-10 leading-[1.375rem] ps-3 pe-3 bg-white dark:bg-white/10 dark:disabled:bg-white/[7%] text-zinc-700 disabled:text-zinc-500 placeholder-zinc-400 disabled:placeholder-zinc-400/70 dark:text-zinc-300 dark:disabled:text-zinc-400 dark:placeholder-zinc-400 dark:disabled:placeholder-zinc-500 shadow-xs border-zinc-200 border-b-zinc-300/80 disabled:border-b-zinc-200 dark:border-white/10 dark:disabled:border-white/5 data-invalid:shadow-none data-invalid:border-red-500 dark:data-invalid:border-red-500 disabled:data-invalid:border-red-500 dark:disabled:data-invalid:border-red-500" wire:model.live.debounce.500ms="vgv" inputmode="decimal" placeholder="Ex: 50.000.000,00"
                 name="vgv"                  x-mask:dynamic="$money($input, &#039;,&#039;, &#039;.&#039;, 2)"                                 <?php if (isset($scope)) $__scope = $scope; ?><?php $scope = array (
  'name' => 'vgv',
); ?>
                <?php if ($scope['name'] && $errors->has($scope['name'])): ?>
                aria-invalid="true" data-invalid
                <?php endif; ?>
                <?php if (isset($__scope)) { $scope = $__scope; unset($__scope); } ?>
                data-flux-control
                data-flux-group-target
                 wire:loading.class="pe-10"                  wire:target="vgv"             >

                            <div class="absolute top-0 bottom-0 flex items-center gap-x-1.5 pe-2 border-e border-transparent end-0 text-xs text-zinc-400">
                    
                                            <svg class="shrink-0 [:where(&amp;)]:size-5 animate-spin" wire:loading="" wire:target="vgv" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true" data-slot="icon">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
</svg>
        
                    
                    
                    
                    
                    
                    
                                    </div>
                    </div>

            </div>
            
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="p-3 bg-light rounded-3 border h-100">
                        <div class="text-xs text-muted text-uppercase fw-bold mb-1" style="font-size: 0.7rem;">Prazo Médio Estimado</div>
                        <div class="h5 fw-bold mb-3 text-brand"><?php echo e($term); ?> meses</div>
                        <ui-field class="min-w-0 [&amp;:not(:has([data-flux-field])):has([data-flux-control][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-radio-group][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-checkbox-group][disabled])&gt;[data-flux-label]]:opacity-50 block *:data-flux-label:mb-3 [&amp;&gt;[data-flux-label]:has(+[data-flux-description])]:mb-2 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mt-0 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mb-3 [&amp;&gt;*:not([data-flux-label])+[data-flux-description]]:mt-3" data-flux-field>
    <ui-label class="inline-flex items-center text-sm font-medium  [:where(&amp;)]:text-zinc-800 [:where(&amp;)]:dark:text-white [&amp;:has([data-flux-label-trailing])]:flex" data-flux-label>
    Prazo (meses)

    
    
    </ui-label>
        
        
        <div class="w-full relative block group/input" data-flux-input>
            
            <input
                type="number"
                
                class="w-full border rounded-lg block disabled:shadow-none dark:shadow-none appearance-none text-base sm:text-sm py-2 h-10 leading-[1.375rem] ps-3 pe-3 bg-white dark:bg-white/10 dark:disabled:bg-white/[7%] text-zinc-700 disabled:text-zinc-500 placeholder-zinc-400 disabled:placeholder-zinc-400/70 dark:text-zinc-300 dark:disabled:text-zinc-400 dark:placeholder-zinc-400 dark:disabled:placeholder-zinc-500 shadow-xs border-zinc-200 border-b-zinc-300/80 disabled:border-b-zinc-200 dark:border-white/10 dark:disabled:border-white/5 data-invalid:shadow-none data-invalid:border-red-500 dark:data-invalid:border-red-500 disabled:data-invalid:border-red-500 dark:disabled:data-invalid:border-red-500" min="1" max="120" step="1" wire:model.live.debounce.300ms="term" inputmode="numeric" label="Prazo (meses)"
                 name="term"                                                 <?php if (isset($scope)) $__scope = $scope; ?><?php $scope = array (
  'name' => 'term',
); ?>
                <?php if ($scope['name'] && $errors->has($scope['name'])): ?>
                aria-invalid="true" data-invalid
                <?php endif; ?>
                <?php if (isset($__scope)) { $scope = $__scope; unset($__scope); } ?>
                data-flux-control
                data-flux-group-target
                 wire:loading.class="pe-10"                  wire:target="term"             >

                            <div class="absolute top-0 bottom-0 flex items-center gap-x-1.5 pe-2 border-e border-transparent end-0 text-xs text-zinc-400">
                    
                                            <svg class="shrink-0 [:where(&amp;)]:size-5 animate-spin" wire:loading="" wire:target="term" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true" data-slot="icon">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
</svg>
        
                    
                    
                    
                    
                    
                    
                                    </div>
                    </div>

        <?php if (isset($scope)) $__scope = $scope; ?><?php $scope = array (
  'attributes' => 
  array (
    'name' => 'term',
  ),
); ?>
        <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/error.blade.php', $__blaze->compiledPath.'/f32fcff11737a3b3d94111c54830240b.php'); ?>
<?php require_once $__blaze->compiledPath.'/f32fcff11737a3b3d94111c54830240b.php'; ?>
<?php $__blaze->pushData(['attributes' => new \Illuminate\View\ComponentAttributeBag($scope['attributes'])]); ?>
<?php _f32fcff11737a3b3d94111c54830240b($__blaze, ['attributes' => new \Illuminate\View\ComponentAttributeBag($scope['attributes'])], [], ['attributes'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
        <?php if (isset($__scope)) { $scope = $__scope; unset($__scope); } ?>
</ui-field>

                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-3 bg-light rounded-3 border h-100">
                        <div class="text-xs text-muted text-uppercase fw-bold mb-1" style="font-size: 0.7rem;">Taxa Alvo de Mercado</div>
                        <div class="h5 fw-bold mb-3 text-brand"><?php echo e($this->remunerationLabel); ?></div>
                        <div class="row g-2">
                            <div class="col-6">
                                <?php $__blaze->pushData(['wire:model.live' => 'indexer', 'label' => 'Indexador']); $__env->pushConsumableComponentData(['wire:model.live' => 'indexer', 'label' => 'Indexador']); ?><ui-field class="min-w-0 [&amp;:not(:has([data-flux-field])):has([data-flux-control][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-radio-group][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-checkbox-group][disabled])&gt;[data-flux-label]]:opacity-50 block *:data-flux-label:mb-3 [&amp;&gt;[data-flux-label]:has(+[data-flux-description])]:mb-2 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mt-0 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mb-3 [&amp;&gt;*:not([data-flux-label])+[data-flux-description]]:mt-3" data-flux-field>
    <ui-label class="inline-flex items-center text-sm font-medium  [:where(&amp;)]:text-zinc-800 [:where(&amp;)]:dark:text-white [&amp;:has([data-flux-label-trailing])]:flex" data-flux-label>
    Indexador

    
    
    </ui-label>
        
        
        <select
    class="appearance-none [:where(&amp;)]:w-full ps-3 pe-10 block h-10 py-2 text-base sm:text-sm leading-[1.375rem] rounded-lg shadow-xs border bg-white dark:bg-white/10 dark:disabled:bg-white/[7%] text-zinc-700 dark:text-zinc-300 disabled:text-zinc-500 dark:disabled:text-zinc-400 has-[option.placeholder:checked]:text-zinc-400 dark:has-[option.placeholder:checked]:text-zinc-400 dark:[&amp;&gt;option]:bg-zinc-700 dark:[&amp;&gt;option]:text-white disabled:shadow-none border border-zinc-200 border-b-zinc-300/80 dark:border-white/10" wire:model.live="indexer" label="Indexador"
         name="indexer"         data-flux-control
    data-flux-select-native
    data-flux-group-target
>
    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $indexerOptions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoop($loop->index); ?><?php endif; ?>
                                        <option
    <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'indexer-'.e($option).''; ?>wire:key="indexer-<?php echo e($option); ?>"
     value="<?php echo e($option); ?>"      <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = ''.e($option).''; ?>wire:key="<?php echo e($option); ?>" ><?php echo e($option); ?></option>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
</select>

        <?php if (isset($scope)) $__scope = $scope; ?><?php $scope = array (
  'attributes' => 
  array (
    'name' => 'indexer',
  ),
); ?>
        <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/error.blade.php', $__blaze->compiledPath.'/f32fcff11737a3b3d94111c54830240b.php'); ?>
<?php require_once $__blaze->compiledPath.'/f32fcff11737a3b3d94111c54830240b.php'; ?>
<?php $__blaze->pushData(['attributes' => new \Illuminate\View\ComponentAttributeBag($scope['attributes'])]); ?>
<?php _f32fcff11737a3b3d94111c54830240b($__blaze, ['attributes' => new \Illuminate\View\ComponentAttributeBag($scope['attributes'])], [], ['attributes'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
        <?php if (isset($__scope)) { $scope = $__scope; unset($__scope); } ?>
</ui-field>
<?php $__blaze->popData(); $__env->popConsumableComponentData(); ?>
                            </div>
                            <div class="col-6">
                                <ui-field class="min-w-0 [&amp;:not(:has([data-flux-field])):has([data-flux-control][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-radio-group][disabled])&gt;[data-flux-label]]:opacity-50 [&amp;:has(&gt;[data-flux-checkbox-group][disabled])&gt;[data-flux-label]]:opacity-50 block *:data-flux-label:mb-3 [&amp;&gt;[data-flux-label]:has(+[data-flux-description])]:mb-2 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mt-0 [&amp;&gt;[data-flux-label]+[data-flux-description]]:mb-3 [&amp;&gt;*:not([data-flux-label])+[data-flux-description]]:mt-3" data-flux-field>
    <ui-label class="inline-flex items-center text-sm font-medium  [:where(&amp;)]:text-zinc-800 [:where(&amp;)]:dark:text-white [&amp;:has([data-flux-label-trailing])]:flex" data-flux-label>
    Percentual (%)

    
    
    </ui-label>
        
        
        <div class="w-full relative block group/input" data-flux-input>
            
            <input
                type="number"
                
                class="w-full border rounded-lg block disabled:shadow-none dark:shadow-none appearance-none text-base sm:text-sm py-2 h-10 leading-[1.375rem] ps-3 pe-3 bg-white dark:bg-white/10 dark:disabled:bg-white/[7%] text-zinc-700 disabled:text-zinc-500 placeholder-zinc-400 disabled:placeholder-zinc-400/70 dark:text-zinc-300 dark:disabled:text-zinc-400 dark:placeholder-zinc-400 dark:disabled:placeholder-zinc-500 shadow-xs border-zinc-200 border-b-zinc-300/80 disabled:border-b-zinc-200 dark:border-white/10 dark:disabled:border-white/5 data-invalid:shadow-none data-invalid:border-red-500 dark:data-invalid:border-red-500 disabled:data-invalid:border-red-500 dark:disabled:data-invalid:border-red-500" min="0" max="100" step="0.01" wire:model.live.debounce.300ms="rate" inputmode="decimal" label="Percentual (%)"
                 name="rate"                                                 <?php if (isset($scope)) $__scope = $scope; ?><?php $scope = array (
  'name' => 'rate',
); ?>
                <?php if ($scope['name'] && $errors->has($scope['name'])): ?>
                aria-invalid="true" data-invalid
                <?php endif; ?>
                <?php if (isset($__scope)) { $scope = $__scope; unset($__scope); } ?>
                data-flux-control
                data-flux-group-target
                 wire:loading.class="pe-10"                  wire:target="rate"             >

                            <div class="absolute top-0 bottom-0 flex items-center gap-x-1.5 pe-2 border-e border-transparent end-0 text-xs text-zinc-400">
                    
                                            <svg class="shrink-0 [:where(&amp;)]:size-5 animate-spin" wire:loading="" wire:target="rate" data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true" data-slot="icon">
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
</svg>
        
                    
                    
                    
                    
                    
                    
                                    </div>
                    </div>

        <?php if (isset($scope)) $__scope = $scope; ?><?php $scope = array (
  'attributes' => 
  array (
    'name' => 'rate',
  ),
); ?>
        <?php $__blaze->ensureCompiled('/home/desktop/projects/bsi-capital/vendor/livewire/flux/src/../stubs/resources/views/flux/error.blade.php', $__blaze->compiledPath.'/f32fcff11737a3b3d94111c54830240b.php'); ?>
<?php require_once $__blaze->compiledPath.'/f32fcff11737a3b3d94111c54830240b.php'; ?>
<?php $__blaze->pushData(['attributes' => new \Illuminate\View\ComponentAttributeBag($scope['attributes'])]); ?>
<?php _f32fcff11737a3b3d94111c54830240b($__blaze, ['attributes' => new \Illuminate\View\ComponentAttributeBag($scope['attributes'])], [], ['attributes'], isset($this) ? $this : null); ?>
<?php $__blaze->popData(); ?>
        <?php if (isset($__scope)) { $scope = $__scope; unset($__scope); } ?>
</ui-field>

                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p class="small text-muted mt-4 mb-0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                Valores meramente ilustrativos baseados em VGV, prazo e remuneração alvo de mercado.
            </p>
        </div>

        <div class="col-lg-5">
            <div class="p-4 p-lg-5 rounded-4 text-white position-relative overflow-hidden" style="background: var(--brand-strong); box-shadow: 0 20px 40px rgba(0,32,91,0.2);">
                <!-- Decorative element -->
                <div class="position-absolute top-0 end-0 bg-white opacity-10 rounded-circle" style="width: 200px; height: 200px; margin-top: -100px; margin-right: -100px;"></div>
                
                <div class="position-relative z-1">
                    <div class="text-uppercase fw-bold mb-2" style="font-size: 0.75rem; letter-spacing: 0.1em; color: rgba(255,255,255,0.7);">Potencial de Captação</div>
                    <div class="display-6 fw-bold mb-4" style="color: var(--gold);">
                        R$ <?php echo e(number_format($potential, 2, ',', '.')); ?>

                    </div>
                    
                    <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">
                    
                    <p class="small mb-5" style="color: rgba(255,255,255,0.6); line-height: 1.6;">
                        A estrutura final depende de rating do emissor, qualidade das garantias e fluxo de recebíveis auditado.
                    </p>
                    
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand w-100 py-3 fw-bold" style="background: var(--gold); border: none; color: var(--brand-strong);">
                        Analisar meu Projeto
                    </a>
                </div>
            </div>
        </div>
    </div>
</div><?php /**PATH /home/desktop/projects/bsi-capital/storage/framework/views/livewire/views/6aae4129.blade.php ENDPATH**/ ?>
<?php
    /** @var array<string, mixed>|null $latestSummary */
    $latestSummary ??= null;
    $canManageGuarantees ??= false;
    $canRegisterMonthlyIndicators ??= false;
    $migrationPending ??= false;
    $needsMonthlyUpdate ??= false;

    $formatCurrency = static fn (mixed $value): string => 'R$ ' . \App\Concerns\MoneyFormatter::formatCurrencyForDisplay($value);
    $formatRatio = static fn (?float $value): string => $value === null ? 'Nao disponivel' : number_format($value * 100, 0, ',', '.') . '%';
    $coverageRatio = $latestSummary['coverage_ratio'] ?? null;
    $coverageCardClasses = match (true) {
        $coverageRatio === null => 'border-white/10 bg-white/[0.03]',
        $coverageRatio > 1.3 => 'border-emerald-400/20 bg-emerald-500/10',
        $coverageRatio >= 1.2 => 'border-amber-400/20 bg-amber-500/10',
        default => 'border-rose-400/20 bg-rose-500/10',
    };
    $coverageLabelClasses = match (true) {
        $coverageRatio === null => 'text-gray-300',
        $coverageRatio > 1.3 => 'text-emerald-200',
        $coverageRatio >= 1.2 => 'text-amber-200',
        default => 'text-rose-200',
    };
    $coverageDescriptionClasses = match (true) {
        $coverageRatio === null => 'text-gray-300/80',
        $coverageRatio > 1.3 => 'text-emerald-100/80',
        $coverageRatio >= 1.2 => 'text-amber-100/80',
        default => 'text-rose-100/80',
    };
?>

<div class="mb-6 space-y-4">
    <section class="overflow-hidden rounded-3xl border border-white/10 bg-gray-950/70 shadow-2xl shadow-black/10">
        <div class="flex flex-col gap-4 border-b border-white/10 px-6 py-5 sm:px-8 xl:flex-row xl:items-start xl:justify-between">
            <div class="space-y-2">
                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Cobertura de garantias</span>
                <div>
                    <h3 class="text-xl font-semibold text-white">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($latestSummary): ?>
                            Competencia base: <?php echo e($latestSummary['reference_month_label']); ?>

                        <?php else: ?>
                            Nenhum indicador mensal cadastrado
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </h3>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-400">
                        Formula aplicada: (Valor das Quotas + Valor das Unidades + Recebiveis cedidos + Saldo das contas) / Saldo devedor.
                    </p>
                </div>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canManageGuarantees): ?>
                    <div class="flex flex-wrap gap-3 pt-2">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canRegisterMonthlyIndicators): ?>
                            <?php if (isset($component)) { $__componentOriginal6330f08526bbb3ce2a0da37da512a11f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6330f08526bbb3ce2a0da37da512a11f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.button.index','data' => ['color' => 'warning','icon' => 'heroicon-o-chart-bar-square','size' => 'sm','wire:click' => 'mountTableAction(\'update_monthly_snapshot\')']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['color' => 'warning','icon' => 'heroicon-o-chart-bar-square','size' => 'sm','wire:click' => 'mountTableAction(\'update_monthly_snapshot\')']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                                Atualizar indicadores mensais
                             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6330f08526bbb3ce2a0da37da512a11f)): ?>
<?php $attributes = $__attributesOriginal6330f08526bbb3ce2a0da37da512a11f; ?>
<?php unset($__attributesOriginal6330f08526bbb3ce2a0da37da512a11f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6330f08526bbb3ce2a0da37da512a11f)): ?>
<?php $component = $__componentOriginal6330f08526bbb3ce2a0da37da512a11f; ?>
<?php unset($__componentOriginal6330f08526bbb3ce2a0da37da512a11f); ?>
<?php endif; ?>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                        <?php if (isset($component)) { $__componentOriginal6330f08526bbb3ce2a0da37da512a11f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6330f08526bbb3ce2a0da37da512a11f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'filament::components.button.index','data' => ['color' => 'gray','icon' => 'heroicon-o-plus','size' => 'sm','wire:click' => 'mountTableAction(\'create\')']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('filament::button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['color' => 'gray','icon' => 'heroicon-o-plus','size' => 'sm','wire:click' => 'mountTableAction(\'create\')']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

                            Cadastrar garantia
                         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6330f08526bbb3ce2a0da37da512a11f)): ?>
<?php $attributes = $__attributesOriginal6330f08526bbb3ce2a0da37da512a11f; ?>
<?php unset($__attributesOriginal6330f08526bbb3ce2a0da37da512a11f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6330f08526bbb3ce2a0da37da512a11f)): ?>
<?php $component = $__componentOriginal6330f08526bbb3ce2a0da37da512a11f; ?>
<?php unset($__componentOriginal6330f08526bbb3ce2a0da37da512a11f); ?>
<?php endif; ?>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($migrationPending): ?>
                <div class="rounded-2xl border border-rose-400/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                    A tabela de indicadores mensais ainda nao foi criada. Execute a migration pendente e recarregue a pagina.
                </div>
            <?php elseif($needsMonthlyUpdate): ?>
                <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                    Atualizacao mensal pendente. Informe o valor das quotas do mes atual.
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($latestSummary): ?>
            <div class="grid gap-4 px-6 py-6 sm:px-8 md:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Valor das quotas</span>
                    <div class="mt-3 text-2xl font-semibold text-white"><?php echo e($formatCurrency($latestSummary['quota_value'])); ?></div>
                    <p class="mt-2 text-sm text-gray-400">Informado manualmente em Garantias.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Valor das unidades</span>
                    <div class="mt-3 text-2xl font-semibold text-white"><?php echo e($formatCurrency($latestSummary['units_value'])); ?></div>
                    <p class="mt-2 text-sm text-gray-400">Soma do valor em estoque de todos os empreendimentos da emissao.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Recebiveis cedidos</span>
                    <div class="mt-3 text-2xl font-semibold text-white"><?php echo e($formatCurrency($latestSummary['receivables_value'])); ?></div>
                    <p class="mt-2 text-sm text-gray-400">Consolidado a partir do resumo mensal de recebiveis.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Saldo das contas</span>
                    <div class="mt-3 text-2xl font-semibold text-white"><?php echo e($formatCurrency($latestSummary['account_balance_value'])); ?></div>
                    <p class="mt-2 text-sm text-gray-400">Soma dos fundos relacionados a emissao na mesma competencia.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-gray-400">Saldo devedor</span>
                    <div class="mt-3 text-2xl font-semibold text-white"><?php echo e($formatCurrency($latestSummary['outstanding_balance_value'])); ?></div>
                    <p class="mt-2 text-sm text-gray-400">Calculado automaticamente com base no ultimo PU do mes e na quantidade integralizada acumulada.</p>
                </div>

                <div class="rounded-2xl border p-4 <?php echo e($coverageCardClasses); ?>">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] <?php echo e($coverageLabelClasses); ?>">Indice de cobertura</span>
                    <div class="mt-3 text-2xl font-semibold text-white"><?php echo e($formatRatio($latestSummary['coverage_ratio'])); ?></div>
                    <p class="mt-2 text-sm <?php echo e($coverageDescriptionClasses); ?>">Total de garantias: <?php echo e($formatCurrency($latestSummary['total_guarantees_value'])); ?></p>
                </div>
            </div>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($latestSummary['missing_sources']) > 0): ?>
                <div class="border-t border-white/10 px-6 py-4 sm:px-8">
                    <div class="rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                        Dados automaticos ausentes na competencia <?php echo e($latestSummary['reference_month_label']); ?>:
                        <?php echo e(implode(', ', $latestSummary['missing_sources'])); ?>.
                    </div>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php else: ?>
            <div class="px-6 py-6 sm:px-8">
                <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.03] px-4 py-5 text-sm text-gray-400">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($migrationPending): ?>
                        A consolidacao mensal ficara disponivel assim que a migration de <span class="font-medium text-white">guarantee snapshots</span> for aplicada.
                    <?php else: ?>
                        Use <span class="font-medium text-white">Atualizar indicadores mensais</span> para informar o valor das quotas da competencia. O sistema consolida automaticamente saldo devedor, unidades, recebiveis cedidos e saldo das contas.
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </section>

    <?php echo $__env->make('filament.resources.emissions.relation-managers.guarantees-history', [
        'history' => $history,
    ], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>
<?php /**PATH /var/www/html/resources/views/filament/resources/emissions/relation-managers/guarantees-overview.blade.php ENDPATH**/ ?>
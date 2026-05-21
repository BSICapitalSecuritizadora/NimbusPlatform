<?php if (isset($component)) { $__componentOriginal81a506f898233b9e7d58286e6bea3c18 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal81a506f898233b9e7d58286e6bea3c18 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'f4ac99e09542ff494432bc959d4fee61::app','data' => ['title' => __('Dashboard')]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts::app'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(__('Dashboard'))]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

    <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6">
        <section class="bsi-shell-card overflow-hidden bg-[radial-gradient(circle_at_top_right,rgba(212,175,55,0.14),transparent_24%),linear-gradient(135deg,rgba(0,32,91,0.98),rgba(10,23,52,0.98))] text-white dark:border-white/10 dark:bg-[radial-gradient(circle_at_top_right,rgba(212,175,55,0.12),transparent_24%),linear-gradient(135deg,rgba(0,32,91,0.96),rgba(10,23,52,0.96))]">
            <div class="grid gap-6 p-6 lg:grid-cols-[1.35fr_0.65fr] lg:p-8">
                <div class="space-y-5">
                    <div class="bsi-kicker">Ambiente interno</div>
                    <div class="space-y-3">
                        <h1 class="text-3xl font-semibold tracking-[-0.04em] text-white sm:text-4xl">
                            Painel BSI Capital
                        </h1>
                        <p class="max-w-3xl text-sm leading-7 text-white/72 sm:text-base">
                            Um ponto único para navegar entre os principais fluxos do produto, acompanhar documentos públicos, acessar o site institucional e manter a experiência interna alinhada ao mesmo padrão visual da plataforma.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <a href="<?php echo e(route('site.home')); ?>" class="bsi-action-primary">
                            <?php echo e(__('Acessar o site')); ?>

                        </a>
                        <a href="<?php echo e(route('proposal.create')); ?>" class="bsi-action-secondary !border-white/15 !bg-white/8 !text-white hover:!bg-white/12">
                            <?php echo e(__('Enviar proposta')); ?>

                        </a>
                        <a href="<?php echo e(route('profile.edit')); ?>" class="bsi-action-secondary !border-white/15 !bg-transparent !text-white hover:!bg-white/8" wire:navigate>
                            <?php echo e(__('Configurações')); ?>

                        </a>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                    <div class="rounded-[22px] border border-white/10 bg-white/8 p-4 backdrop-blur">
                        <div class="text-[0.7rem] font-bold uppercase tracking-[0.22em] text-gold-400"><?php echo e(__('Fluxo')); ?></div>
                        <div class="mt-2 text-xl font-semibold tracking-[-0.03em]"><?php echo e(__('Operação clara')); ?></div>
                        <div class="mt-2 text-sm text-white/65"><?php echo e(__('Navegação unificada entre institucional, relacionamento e proposta.')); ?></div>
                    </div>
                    <div class="rounded-[22px] border border-white/10 bg-white/8 p-4 backdrop-blur">
                        <div class="text-[0.7rem] font-bold uppercase tracking-[0.22em] text-gold-400"><?php echo e(__('Marca')); ?></div>
                        <div class="mt-2 text-xl font-semibold tracking-[-0.03em]"><?php echo e(__('Identidade única')); ?></div>
                        <div class="mt-2 text-sm text-white/65"><?php echo e(__('Mesma linguagem visual, cores e hierarquia em todo o produto.')); ?></div>
                    </div>
                    <div class="rounded-[22px] border border-white/10 bg-white/8 p-4 backdrop-blur">
                        <div class="text-[0.7rem] font-bold uppercase tracking-[0.22em] text-gold-400"><?php echo e(__('Usuário')); ?></div>
                        <div class="mt-2 text-xl font-semibold tracking-[-0.03em]"><?php echo e(auth()->user()->name); ?></div>
                        <div class="mt-2 text-sm text-white/65"><?php echo e(__('Acesso autenticado ao ambiente operacional da plataforma.')); ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <div class="bsi-shell-card p-6 lg:p-7">
                <div class="mb-5 flex items-end justify-between gap-4">
                    <div>
                        <div class="bsi-kicker mb-2"><?php echo e(__('Atalhos')); ?></div>
                        <h2 class="bsi-heading text-2xl"><?php echo e(__('Ações principais')); ?></h2>
                    </div>
                    <div class="hidden rounded-full border border-brand-100 bg-brand-50 px-4 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-brand-700 dark:border-white/10 dark:bg-white/[0.04] dark:text-brand-100 sm:inline-flex">
                        <?php echo e(__('Uso recorrente')); ?>

                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <a href="<?php echo e(route('site.emissions')); ?>" class="bsi-shell-card-soft block p-5 transition hover:-translate-y-1 hover:shadow-lg">
                        <div class="bsi-kicker mb-2"><?php echo e(__('Mercado')); ?></div>
                        <div class="bsi-heading text-xl"><?php echo e(__('Emissões')); ?></div>
                        <p class="bsi-copy mt-2"><?php echo e(__('Consulta pública de emissões com filtros, leitura organizada e navegação consistente.')); ?></p>
                    </a>

                    <a href="<?php echo e(route('site.ri')); ?>" class="bsi-shell-card-soft block p-5 transition hover:-translate-y-1 hover:shadow-lg">
                        <div class="bsi-kicker mb-2"><?php echo e(__('Transparência')); ?></div>
                        <div class="bsi-heading text-xl"><?php echo e(__('Relações com investidores')); ?></div>
                        <p class="bsi-copy mt-2"><?php echo e(__('Documentos, comunicados e publicações em um fluxo mais claro para consulta.')); ?></p>
                    </a>

                    <a href="<?php echo e(route('site.contact')); ?>" class="bsi-shell-card-soft block p-5 transition hover:-translate-y-1 hover:shadow-lg">
                        <div class="bsi-kicker mb-2"><?php echo e(__('Relacionamento')); ?></div>
                        <div class="bsi-heading text-xl"><?php echo e(__('Contato institucional')); ?></div>
                        <p class="bsi-copy mt-2"><?php echo e(__('Canal centralizado para demandas comerciais, institucionais e corporativas.')); ?></p>
                    </a>

                    <a href="<?php echo e(route('site.vacancies.index')); ?>" class="bsi-shell-card-soft block p-5 transition hover:-translate-y-1 hover:shadow-lg">
                        <div class="bsi-kicker mb-2"><?php echo e(__('Pessoas')); ?></div>
                        <div class="bsi-heading text-xl"><?php echo e(__('Carreiras')); ?></div>
                        <p class="bsi-copy mt-2"><?php echo e(__('Página de vagas com leitura mais objetiva e alinhada à identidade da BSI.')); ?></p>
                    </a>
                </div>
            </div>

            <div class="space-y-6">
                <section class="bsi-shell-card p-6 lg:p-7">
                    <div class="bsi-kicker mb-2"><?php echo e(__('Padrões da plataforma')); ?></div>
                    <h2 class="bsi-heading text-2xl"><?php echo e(__('O que mudou na experiência')); ?></h2>
                    <div class="mt-5 space-y-4">
                        <div class="bsi-shell-card-soft p-4">
                            <div class="font-semibold text-brand-700 dark:text-brand-100"><?php echo e(__('Hierarquia mais clara')); ?></div>
                            <div class="bsi-copy mt-1"><?php echo e(__('Títulos, subtítulos e CTAs seguem o mesmo desenho visual em toda a aplicação.')); ?></div>
                        </div>
                        <div class="bsi-shell-card-soft p-4">
                            <div class="font-semibold text-brand-700 dark:text-brand-100"><?php echo e(__('Componentes coerentes')); ?></div>
                            <div class="bsi-copy mt-1"><?php echo e(__('Cards, botões, formulários e superfícies passaram a operar como um sistema único.')); ?></div>
                        </div>
                        <div class="bsi-shell-card-soft p-4">
                            <div class="font-semibold text-brand-700 dark:text-brand-100"><?php echo e(__('Leitura mais profissional')); ?></div>
                            <div class="bsi-copy mt-1"><?php echo e(__('Menos aparência de kit inicial, mais consistência institucional entre áreas públicas e autenticadas.')); ?></div>
                        </div>
                    </div>
                </section>

                <section class="bsi-shell-card p-6 lg:p-7">
                    <div class="bsi-kicker mb-2"><?php echo e(__('Conta')); ?></div>
                    <h2 class="bsi-heading text-2xl"><?php echo e(__('Preferências e acesso')); ?></h2>
                    <p class="bsi-copy mt-3">
                        Gerencie perfil, preferências e sessão com a mesma linguagem visual do restante do ambiente autenticado.
                    </p>
                    <div class="mt-5">
                        <a href="<?php echo e(route('profile.edit')); ?>" class="bsi-action-primary" wire:navigate>
                            <?php echo e(__('Abrir configurações')); ?>

                        </a>
                    </div>
                </section>
            </div>
        </section>
    </div>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal81a506f898233b9e7d58286e6bea3c18)): ?>
<?php $attributes = $__attributesOriginal81a506f898233b9e7d58286e6bea3c18; ?>
<?php unset($__attributesOriginal81a506f898233b9e7d58286e6bea3c18); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal81a506f898233b9e7d58286e6bea3c18)): ?>
<?php $component = $__componentOriginal81a506f898233b9e7d58286e6bea3c18; ?>
<?php unset($__componentOriginal81a506f898233b9e7d58286e6bea3c18); ?>
<?php endif; ?>
<?php /**PATH /home/desktop/projects/bsi-capital/resources/views/dashboard.blade.php ENDPATH**/ ?>
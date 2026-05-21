<?php $__env->startSection('title', 'Continuar Proposta'); ?>

<?php $__env->startSection('content'); ?>
<section class="py-5" style="min-height: 70vh;">
    <div class="container py-4 py-lg-5">
        <div class="row justify-content-center">
            <div class="col-xl-9">
                <div class="row g-4 align-items-stretch">
                    <div class="col-lg-5">
                        <div class="surface-card h-100 p-4 p-lg-5">
                            <div class="section-kicker mb-2">Acesso seguro</div>
                            <h1 class="h2 fw-bold text-brand mb-3">Continuar proposta</h1>
                            <p class="section-copy mb-4">
                                Valide o acesso com o CNPJ da empresa e o código enviado por e-mail. O processo preserva segurança, rastreabilidade e continuidade do preenchimento.
                            </p>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success')): ?>
                                <div class="alert alert-success border-0 rounded-4"><?php echo e(session('success')); ?></div>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                            <div class="surface-card-soft p-4">
                                <div class="small text-uppercase text-muted fw-semibold mb-2">Proposta</div>
                                <div class="fw-semibold"><?php echo e($proposal->company->name); ?></div>
                                <div class="text-muted"><?php echo e($proposal->contact->name); ?> • <?php echo e($proposal->contact->email); ?></div>
                                <div class="mt-3 small text-muted">CNPJ: <?php echo e($proposal->company->cnpj); ?></div>
                                <div class="small text-muted">Status atual: <?php echo e($proposal->status_label); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="surface-card h-100 p-4 p-lg-5">
                            <div class="section-kicker mb-2">Validação</div>
                            <h2 class="h3 fw-bold text-brand mb-3">Confirme suas credenciais de acesso</h2>
                            <p class="section-copy mb-4">
                                Esta etapa garante que apenas o proponente autorizado consiga retomar o preenchimento da proposta.
                            </p>

                            <form method="POST" action="<?php echo e(route('site.proposal.continuation.verify', $access)); ?>">
                                <?php echo csrf_field(); ?>
                                <div class="mb-3">
                                    <label class="form-label">CNPJ</label>
                                    <input type="text" name="cnpj" id="cnpj" class="form-control <?php $__errorArgs = ['cnpj'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" value="<?php echo e(old('cnpj', $proposal->company->cnpj)); ?>" required>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['cnpj'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <div class="invalid-feedback"><?php echo e($message); ?></div> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label">Código de acesso</label>
                                    <input type="text" name="code" class="form-control <?php $__errorArgs = ['code'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" value="<?php echo e(old('code')); ?>" maxlength="6" required>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['code'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <div class="invalid-feedback"><?php echo e($message); ?></div> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <button type="submit" class="btn btn-brand btn-lg px-5">Acessar continuação</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('scripts'); ?>
<script src="https://unpkg.com/imask"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        IMask(document.getElementById('cnpj'), { mask: '00.000.000/0000-00' });
    });
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/proposal/access.blade.php ENDPATH**/ ?>
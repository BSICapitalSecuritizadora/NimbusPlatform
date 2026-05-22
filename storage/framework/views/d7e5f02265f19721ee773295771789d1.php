<?php $__env->startSection('title', 'Contato — BSI Capital'); ?>


<?php $__env->startSection('content'); ?>
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('<?php echo e(asset('images/compliance.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>
    <div class="container position-relative z-1">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Atendimento institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Entre em contato com a <span style="color: var(--gold);">BSI Capital</span>
                </h1>
                <p class="lead mb-0" style="color: #E6E4E4; max-width: 760px;">
                    Estamos à disposição para avaliar novas teses de operação ou suportar demandas institucionais. Nosso atendimento prioriza o rigor técnico e a viabilidade fiduciária exigidos pelo mercado.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-5">
        <div class="row g-4 align-items-stretch mb-5">
            <div class="col-md-4">
                <div class="surface-card h-100 p-4">
                    <div class="section-kicker mb-2">Canal institucional</div>
                    <h2 class="h4 fw-bold text-brand mb-2">E-mail</h2>
                    <p class="section-copy mb-3">Utilize este canal para demandas institucionais, comerciais e operacionais.</p>
                    <a href="mailto:contato@bsicapital.com.br" class="fw-semibold text-brand text-decoration-none">contato@bsicapital.com.br</a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="surface-card h-100 p-4">
                    <div class="section-kicker mb-2">Atendimento</div>
                    <h2 class="h4 fw-bold text-brand mb-2">Telefone</h2>
                    <p class="section-copy mb-3">Atendimento corporativo e suporte direto em dias úteis, das 09h às 18h.</p>
                    <a href="tel:+551123678793" class="fw-semibold text-brand text-decoration-none">+55 (11) 2367-8793</a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="surface-card h-100 p-4">
                    <div class="section-kicker mb-2">Base operacional</div>
                    <h2 class="h4 fw-bold text-brand mb-2">São Paulo</h2>
                    <p class="section-copy mb-0">
                        Avenida das Nações Unidas, 14.401<br>
                        Tarumã Tower, Salas 712 e 713<br>
                        Chácara Santo Antônio, São Paulo - SP
                    </p>
                </div>
            </div>
        </div>

        <div class="row g-4 align-items-stretch">
            <div class="col-lg-5">
                <div class="surface-card h-100 p-4 p-lg-5">
                    <div class="section-kicker mb-2">Fale conosco</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Atendimento claro e direcionado à área responsável</h2>
                    <p class="section-copy mb-4">
                        Sua demanda será analisada diretamente pelo time responsável. Priorizamos um retorno inicial em até 24 horas úteis, focado em clareza técnica e direcionamento jurídico.
                    </p>

                    <div class="d-flex flex-column gap-3">
                        <div class="surface-card-soft p-3">
                            <div class="small text-uppercase text-muted fw-semibold mb-1">Comercial e novos negócios</div>
                            <div class="fw-semibold">Estruturação, securitização e análise preliminar de operações</div>
                        </div>
                        <div class="surface-card-soft p-3">
                            <div class="small text-uppercase text-muted fw-semibold mb-1">Relacionamento institucional</div>
                            <div class="fw-semibold">Contato com investidores, documentos públicos e comunicações corporativas</div>
                        </div>
                        <div class="surface-card-soft p-3">
                            <div class="small text-uppercase text-muted fw-semibold mb-1">Compliance e ética</div>
                            <div class="fw-semibold">Demandas de conformidade, governança e canais sensíveis</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="surface-card h-100 p-4 p-lg-5">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('contact_success')): ?>
                        <div class="alert alert-success d-flex align-items-center gap-3 mb-4" role="alert">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span>Mensagem enviada com sucesso. Nossa equipe retornará em até 24 horas úteis.</span>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    <div class="mb-4">
                        <div class="section-kicker mb-2">Formulário</div>
                        <h2 class="h3 fw-bold text-brand mb-2">Envie sua mensagem</h2>
                        <p class="section-copy mb-0">As informações abaixo permitem um direcionamento técnico e seguro da sua demanda para a área responsável.</p>
                    </div>

                    <form action="<?php echo e(route('site.contact.submit')); ?>" method="POST" class="row g-3">
                        <?php echo csrf_field(); ?>
                        <div class="col-md-6">
                            <label class="form-label">Nome</label>
                            <input type="text" name="name" class="form-control <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" placeholder="Informe seu nome completo" value="<?php echo e(old('name')); ?>" required>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="invalid-feedback"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" name="email" class="form-control <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" placeholder="Informe seu e-mail corporativo" value="<?php echo e(old('email')); ?>" required>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="invalid-feedback"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Telefone</label>
                            <input type="tel" name="phone" id="phone" class="form-control" placeholder="(00) 00000-0000" value="<?php echo e(old('phone')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assunto</label>
                            <select name="subject" class="form-select <?php $__errorArgs = ['subject'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" required>
                                <option value="" selected disabled>Selecione a área de interesse</option>
                                <option value="Relações com investidores" <?php if(old('subject') === 'Relações com investidores'): echo 'selected'; endif; ?>>Relações com investidores</option>
                                <option value="Comercial e novos negócios" <?php if(old('subject') === 'Comercial e novos negócios'): echo 'selected'; endif; ?>>Comercial e novos negócios</option>
                                <option value="Compliance e canal de ética" <?php if(old('subject') === 'Compliance e canal de ética'): echo 'selected'; endif; ?>>Compliance e canal de ética</option>
                                <option value="Carreiras / Trabalhe conosco" <?php if(old('subject') === 'Carreiras / Trabalhe conosco'): echo 'selected'; endif; ?>>Carreiras / Trabalhe conosco</option>
                                <option value="Assuntos institucionais" <?php if(old('subject') === 'Assuntos institucionais'): echo 'selected'; endif; ?>>Assuntos institucionais</option>
                            </select>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['subject'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="invalid-feedback"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mensagem</label>
                            <textarea name="message" class="form-control <?php $__errorArgs = ['message'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> is-invalid <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>" rows="5" placeholder="Descreva brevemente sua demanda ou tese de operação" required><?php echo e(old('message')); ?></textarea>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['message'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="invalid-feedback"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <div class="col-12 pt-2">
                            <button type="submit" class="btn btn-brand btn-lg px-5 mb-3">Iniciar Atendimento</button>
                            <p class="small text-muted mb-0" style="font-size: 0.75rem; line-height: 1.4;">
                                As informações fornecidas são protegidas por protocolos de sigilo em conformidade com a LGPD e nossa política de integridade corporativa.
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="pb-5">
    <div class="container">
        <div class="surface-card overflow-hidden">
            <div class="row g-0">
                <div class="col-lg-4 p-4 p-lg-5">
                    <div class="section-kicker mb-2">Localização</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Sede Institucional</h2>
                    <p class="section-copy mb-0">
                        Nossa base em São Paulo concentra a inteligência estratégica, operacional e fiduciária da BSI Capital.
                    </p>
                </div>
                <div class="col-lg-8">
                    <iframe
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3655.4502951281343!2d-46.70595342358573!3d-23.624039663899975!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x94ce42360bb98d7f%3A0xa4ab8704821d7133!2sBSI%20Capital%20Securitizadora%20S%2FA!5e0!3m2!1spt-BR!2sbr!4v1774380432797!5m2!1spt-BR!2sbr"
                        width="100%"
                        height="100%"
                        style="border:0; min-height: 420px;"
                        allowfullscreen=""
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
            </div>
        </div>
    </div>
</section>

<?php $__env->startPush('scripts'); ?>
<script nonce="<?php echo e(\Illuminate\Support\Facades\Vite::cspNonce()); ?>">
    document.addEventListener('DOMContentLoaded', function() {
        const phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                let raw = e.target.value.replace(/\D/g, "").substring(0, 11);
                let formatted = raw;
                if (raw.length > 2) {
                    formatted = '(' + raw.substring(0, 2) + ') ' + raw.substring(2);
                }
                if (raw.length > 6) {
                    if (raw.length === 11) {
                        formatted = '(' + raw.substring(0, 2) + ') ' + raw.substring(2, 7) + '-' + raw.substring(7);
                    } else {
                        formatted = '(' + raw.substring(0, 2) + ') ' + raw.substring(2, 6) + '-' + raw.substring(6);
                    }
                }
                e.target.value = formatted;
            });
        }
    });
</script>
<?php $__env->stopPush(); ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/contact.blade.php ENDPATH**/ ?>
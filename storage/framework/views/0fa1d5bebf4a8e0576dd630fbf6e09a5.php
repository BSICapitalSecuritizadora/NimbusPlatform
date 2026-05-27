<?php $__env->startSection('title', 'Cooperativas — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/cooperativas_agro.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Agronegócio</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Capital Estratégico para <span style="color: var(--gold);">Cooperativas</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Estruturas de CRA e CDCA desenhadas para cooperativas, integrando a segurança do Ato Cooperativo à eficiência do mercado de capitais sob as diretrizes da OCB.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Otimizar Funding da Cooperativa
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="<?php echo e(route('site.emissions')); ?>?type=CRA" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="<?php echo e(asset('images/cooperativas_agro.png')); ?>" class="img-fluid" alt="Cooperativas do Agronegócio" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Cooperados</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Fomento direto à cadeia</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Sincronia Operacional e Associativa</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Operações desenhadas para respeitar a dinâmica financeira e associativa, integrando-se perfeitamente aos ciclos de produção e pagamento da cooperativa.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Liquidez da Cadeia</h3>
                    <p class="text-muted mb-0">Antecipação estratégica de recebíveis comerciais, convertendo o crédito mercantil em liquidez imediata para sustentar o giro e o fomento aos cooperados.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Otimização do WACC</h3>
                    <p class="text-muted mb-0">Acesso direto ao mercado de capitais via CRA, reduzindo a exposição bancária e captando recursos com taxas competitivas e aderentes ao balanço.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Rigor Associativo</h3>
                    <p class="text-muted mb-0">Estruturação que respeita a distinção entre ato cooperativo e ato não cooperativo no lastro, com tratamento adequado de sobras e perdas e conformidade com os requisitos da OCB.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Monitoramento pós-fechamento -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Gestão Ativa de um Lastro Pulverizado</h2>
                <p class="text-muted mb-4 lead">
                    Um CRA de cooperativa tem um lastro diferente: os recebíveis vêm de múltiplos cooperados, com perfis distintos e exposição simultânea ao risco de safra. Monitoramos essa pulverização de forma contínua, garantindo que a estrutura reflita a realidade da base associativa.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Verificação da cessão fiduciária dos recebíveis de cooperados com distinção entre ato cooperativo e ato não cooperativo no lastro.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acompanhamento da concentração de risco por cooperado, cultura e região, com alerta precoce de inadimplência na base associativa.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Relatórios periódicos alinhados ao calendário de assembleias e às exigências de transparência da OCB e do agente fiduciário.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/cooperativas_agro.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Ciclo de Liquidez do Cooperado -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <h2 class="h3 fw-bold text-dark mb-4">Eficiência no Fomento Associativo</h2>
                <p class="text-muted mb-4">A securitização funciona como um motor de liquidez que conecta a força da produção cooperada ao vigor do mercado de capitais.</p>
                
                <div class="d-flex flex-column gap-4">
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Captação via CRA/CDCA</h5>
                            <p class="small text-muted mb-0">A cooperativa acessa o investidor final, reduzindo spreads bancários e diversificando as fontes de financiamento.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Suporte ao Cooperado</h5>
                            <p class="small text-muted mb-0">Com caixa reforçado, a cooperativa antecipa pagamentos de safra ou fornece insumos com taxas mais competitivas.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Ratings e Auditoria (Big Four)</h5>
                            <p class="small text-muted mb-0">Estruturamos a operação para atender aos mais altos padrões de transparência exigidos por agências de rating e auditorias externas.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="position-relative p-4 bg-white shadow-sm rounded-4 border">
                    <div class="text-brand fw-bold small text-uppercase mb-3" style="letter-spacing: 0.05em;">Segurança Fiduciária</div>
                    <h3 class="h4 fw-bold mb-3">Preservação do Ato Cooperativo</h3>
                    <p class="text-muted">Nossa modelagem técnica garante que a securitização de recebíveis não descaracterize a natureza do **Ato Cooperativo (Lei 5.764/71)**. Protegemos a eficiência tributária da cooperativa, garantindo que o lastro estruturado em CPRs e CDCAs mantenha a não incidência de impostos sobre a base associativa.</p>
                    <div style="height: 2px; width: 60px; background: var(--gold);" class="my-4"></div>
                    <p class="small text-muted mb-0 font-italic">*Conformidade total com as normas da OCB e regulamentação da CVM.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="py-5 bg-white">
    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-4">
                <div class="pe-lg-4">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Governança Cooperativa</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Excelência no Associativismo</h2>
                    <p class="text-muted mb-4">Esclarecemos os pontos críticos sobre estruturação financeira para o modelo cooperativista.</p>
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-sm px-4 py-2">Falar com Consultoria Agro</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqCooperativa">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Como a BSI garante a segurança fiscal do Ato Cooperativo?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqCooperativa">
                            <div class="accordion-body px-0 text-muted">
                                Realizamos uma blindagem jurídica na estruturação do lastro para garantir que a cessão dos créditos mercantil-cooperativos não seja interpretada como ato comercial visando lucro, preservando assim a não incidência de tributos como PIS e COFINS sobre as transações entre cooperativa e associado.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Qual o papel do CDCA na securitização da cooperativa?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqCooperativa">
                            <div class="accordion-body px-0 text-muted">
                                O Certificado de Direitos Creditórios do Agronegócio (CDCA) é um título emitido pela própria cooperativa que serve de base para o CRA. Ele permite "empacotar" milhares de CPRs dos cooperados de forma eficiente, simplificando a gestão do lastro e facilitando o acesso ao investidor de mercado.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Como lidamos com a pulverização do lastro (centenas de cooperados)?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqCooperativa">
                            <div class="accordion-body px-0 text-muted">
                                Utilizamos tecnologia de monitoramento de risco que analisa a concentração por cultura, região e perfil do associado. Isso permite que mesmo uma carteira com milhares de pequenos produtores tenha um rating de crédito elevado, pois o risco é mitigado pela diversificação da base.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. A BSI auxilia no processo de obtenção de Rating?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqCooperativa">
                            <div class="accordion-body px-0 text-muted">
                                Sim. Atuamos na estruturação da governança e na preparação do fluxo informacional para as agências de risco (Fitch, S&P, Moody's). Uma cooperativa com balanço auditado por Big Four e rating de primeira linha consegue reduzir drasticamente seu custo de capital no mercado.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Outros segmentos do agronegócio -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Outros Segmentos do Agronegócio</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Atuamos em toda a cadeia produtiva do agro com estruturas adaptadas ao perfil de cada tomador e modelo de negócio.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="<?php echo e(route('site.agronegocio.cra')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">CRA e Agronegócio</h3>
                    <p class="text-muted mb-3">Soluções de CRA desenhadas para o ecossistema agro, com lastros auditáveis em CPR, CDCA e CDA/WA e conformidade ao ciclo biológico.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.agronegocio.projetos')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Projetos Agro</h3>
                    <p class="text-muted mb-3">Financiamento estruturado para projetos de expansão rural, armazenagem e infraestrutura logística com lastro em recebíveis do agronegócio.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>
        </div>
    </div>
</section>

<?php $__env->startPush('head'); ?>
<style>
    @keyframes float {
        0% { transform: translateY(0px); }
        50% { transform: translateY(-10px); }
        100% { transform: translateY(0px); }
    }

    .custom-accordion .accordion-button:not(.collapsed) {
        box-shadow: none;
        color: var(--brand);
    }

    .custom-accordion .accordion-button:focus {
        box-shadow: none;
    }

    .custom-accordion .accordion-button::after {
        background-size: 1rem;
    }

    .card-hover:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important;
    }
</style>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/agronegocio/cooperativas.blade.php ENDPATH**/ ?>
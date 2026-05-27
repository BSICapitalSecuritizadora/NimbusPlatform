<?php $__env->startSection('title', 'Projetos — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/projetos_agro.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Agronegócio</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Capex e <br><span style="color: var(--gold);">Investimentos Estratégicos</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Mobilização de capital estratégico para infraestrutura, armazenagem e modernização, com carências e prazos desenhados para o tempo de maturação do seu investimento.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Avaliar Expansão de CAPEX
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
                        <img src="<?php echo e(asset('images/projetos_agro.png')); ?>" class="img-fluid" alt="Projetos do Agronegócio" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">CAPEX Rural</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Estrutura de longo prazo</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Capital para expansão e verticalização</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Soluções de funding estratégico voltadas a ganhos de escala, eficiência tecnológica e modernização da infraestrutura no agronegócio.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="10" width="18" height="11" rx="2" ry="2"></rect><path d="M3 10l9-7 9 7"></path><line x1="12" y1="10" x2="12" y2="21"></line><line x1="7" y1="10" x2="7" y2="21"></line><line x1="17" y1="10" x2="17" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Infraestrutura Produtiva</h3>
                    <p class="text-muted mb-0">Financiamento estratégico para armazenagem, logística e irrigação, com cronogramas de desembolso moldados ao tempo de maturação de ativos fixos.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"></polygon><line x1="8" y1="2" x2="8" y2="18"></line><line x1="16" y1="6" x2="16" y2="22"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Expansão e Consolidação</h3>
                    <p class="text-muted mb-0">Modelagem financeira para aquisição de áreas e reorganização patrimonial, otimizando a estrutura de capital para saltos reais de escala operacional.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Energia e Sustentabilidade</h3>
                    <p class="text-muted mb-0">Crédito para projetos de energia solar, biogás e tecnologias limpas, alinhando a rentabilidade do campo aos padrões globais de sustentabilidade.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Monitoramento de projetos de longo prazo -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Acompanhamento Técnico ao Longo de Toda a Obra</h2>
                <p class="text-muted mb-4 lead">
                    Projetos de CAPEX têm prazo longo e desembolso vinculado a marcos físicos. Via CR ou CRA lastreado em produção futura, a BSI Capital estrutura o instrumento certo e acompanha cada etapa — complementando linhas BNDES, FCO Rural e FINAME onde o mercado de capitais é mais eficiente.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Verificação do avanço físico da obra contra o cronograma de desembolsos, com laudo técnico periódico de vistoria.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento das garantias reais (hipoteca e alienação fiduciária de imóvel rural) durante toda a vigência da operação.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Relatórios periódicos para investidores com posição financeira do projeto, evolução da obra e comparativo com os indicadores de viabilidade originais.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/projetos_agro.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Diferenciais da Securitização de Infraestrutura -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <h2 class="h3 fw-bold text-dark mb-4">Por que securitizar o CAPEX Rural?</h2>
                <p class="text-muted mb-4">Diferente do crédito bancário tradicional, o mercado de capitais oferece a flexibilidade necessária para projetos que levam tempo para gerar retorno.</p>
                
                <div class="d-flex flex-column gap-4">
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Carência Customizada</h5>
                            <p class="small text-muted mb-0">Estruturamos carências de até 2 safras para o pagamento de juros e principal, respeitando o tempo de construção e início da operação.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Garantias Reais Robustas</h5>
                            <p class="small text-muted mb-0">Alienação fiduciária do imóvel rural e das benfeitorias, conferindo ao título um rating de crédito superior e menor custo financeiro.</p>
                        </div>
                    </div>
                    <div class="d-flex gap-3">
                        <div class="flex-shrink-0 bg-brand text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem; font-weight: bold;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div>
                            <h5 class="h6 fw-bold mb-1">Agilidade Complementar</h5>
                            <p class="small text-muted mb-0">Atuamos onde as linhas oficiais (BNDES/FCO) atingem o limite, oferecendo o volume extra necessário para o fechamento do funding.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="position-relative p-4 bg-white shadow-sm rounded-4 border">
                    <div class="text-brand fw-bold small text-uppercase mb-3" style="letter-spacing: 0.05em;">Monitoramento Técnico</div>
                    <h3 class="h4 fw-bold mb-3">Segurança na Execução do Projeto</h3>
                    <p class="text-muted">Projetos de infraestrutura como **Silos, Armazéns e Usinas de Biogás** exigem um controle rigoroso de desembolso. A BSI Capital utiliza auditoria de engenharia independente para validar cada marco físico antes da liberação das tranches de recursos, eliminando o risco de desvio de finalidade e garantindo a entrega do ativo.</p>
                    <div style="height: 2px; width: 60px; background: var(--gold);" class="my-4"></div>
                    <p class="small text-muted mb-0 font-italic">*Suporte em projetos Greenfield (do zero) e Brownfield (expansão).</p>
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
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Consultoria Técnica</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Viabilizando a Infraestrutura Rural</h2>
                    <p class="text-muted mb-4">Esclarecemos os principais mecanismos de estruturação para projetos de longo prazo no agronegócio.</p>
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-sm px-4 py-2">Falar com Estruturação</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqProjetos">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Quais os prazos médios de carência para projetos de infraestrutura?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqProjetos">
                            <div class="accordion-body px-0 text-muted">
                                Para projetos de CAPEX (armazenagem, irrigação, usinas), estruturamos carências que variam de 12 a 24 meses, podendo chegar a 2 safras completas. O objetivo é que o produtor ou agroindústria só comece a amortizar a dívida quando o ativo já estiver operando e gerando ganhos de eficiência ou receita.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Como funciona a estruturação de Garantias Reais Rurais?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqProjetos">
                            <div class="accordion-body px-0 text-muted">
                                Utilizamos a Hipoteca ou Alienação Fiduciária de Imóvel Rural como garantia principal. Realizamos uma avaliação técnica do valor da terra e das benfeitorias (LTV - Loan to Value), garantindo que a operação esteja sobrecolateralizada, o que reduz o risco para o investidor e o custo para o tomador.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. O que são tranches de obra e como elas são liberadas?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqProjetos">
                            <div class="accordion-body px-0 text-muted">
                                Diferente de um empréstimo comum, os recursos do CRA de projeto são liberados em parcelas (tranches) conforme a evolução da obra. Após a vistoria técnica e emissão do laudo de medição, a securitizadora libera o recurso necessário para a próxima etapa do cronograma físico-financeiro.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. Posso usar a securitização para projetos de energia solar ou biogás?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqProjetos">
                            <div class="accordion-body px-0 text-muted">
                                Com certeza. Projetos de energia renovável no campo são ideais para o mercado de capitais, pois possuem fluxos de economia/receita previsíveis e forte apelo ESG, facilitando a captação via CRAs Verdes com taxas extremamente competitivas.
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
                <a href="<?php echo e(route('site.agronegocio.cooperativas')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Cooperativas</h3>
                    <p class="text-muted mb-3">Estruturas de securitização adaptadas ao modelo cooperativista, com cessão fiduciária de recebíveis de cooperados e conformidade com a OCB.</p>
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

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/agronegocio/projetos.blade.php ENDPATH**/ ?>
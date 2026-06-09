<?php $__env->startSection('title', 'Registro e Distribuição — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/registro_distribuicao.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Serviços</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Registro e <span style="color: var(--gold);">Distribuição</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Mais do que protocolo, cuidamos da jornada da sua oferta. Do registro na CVM à liquidação na B3, assumimos a interface com cada agente para garantir que o fechamento ocorra no tempo e na taxa planejada.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Consultar Especialista
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="<?php echo e(route('site.emissions')); ?>" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="<?php echo e(asset('images/registro_distribuicao.png')); ?>" class="img-fluid" alt="Registro e Distribuição" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Do registro ao closing</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Liquidação precisa</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Execução técnica para quem não quer surpresas</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Assumimos a complexidade operacional da oferta, conectando infraestrutura de mercado e investidores para uma liquidação sem fricção.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="3" x2="6" y2="15"></line><circle cx="18" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M18 9a9 9 0 0 1-9 9"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Segurança no Rito Regulatório</h3>
                    <p class="text-muted mb-0">Navegamos pela burocracia da CVM, B3 e ANBIMA por você. Gerenciamos prazos e exigências para que o registro e o depósito da operação sejam etapas fluidas, nunca gargalos.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Distribuição Assertiva</h3>
                    <p class="text-muted mb-0">Conectamos sua tese aos bolsos de liquidez corretos. Coordenamos roadshows e o cronograma de captação focando no alinhamento real entre a oferta e o apetite dos investidores.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Visibilidade do Bookbuilding</h3>
                    <p class="text-muted mb-0">Não espere pelo encerramento para saber o resultado. Acompanhe a formação do livro e a sensibilidade de preço em tempo real para decisões estratégicas de taxa e alocação.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Cronograma da Emissão (Timeline) -->
<section class="py-5 bg-white" style="border-top: 1px solid var(--border);">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-3">Do Registro ao Closing: O Fluxo da Operação</h2>
            <p class="text-muted mx-auto" style="max-width: 700px;">Gerenciamos cada marco crítico da oferta para garantir previsibilidade e agilidade na captação.</p>
        </div>

        <div class="position-relative">
            <!-- Timeline Line (Desktop) -->
            <div class="d-none d-lg-block position-absolute start-0 w-100" style="top: 24px; height: 2px; background: linear-gradient(90deg, var(--gold) 0%, rgba(212,175,55,0.1) 100%); z-index: 0;"></div>
            
            <div class="row g-4 position-relative z-1">
                <!-- Step 1 -->
                <div class="col-lg-3">
                    <div class="text-center">
                        <div class="d-inline-flex align-items-center justify-content-center bg-white border border-gold rounded-circle mb-3 shadow-sm" style="width: 48px; height: 48px;">
                            <span class="fw-bold text-gold">01</span>
                        </div>
                        <h4 class="h6 fw-bold text-dark mb-2">Protocolo e Registro</h4>
                        <p class="smaller text-muted mb-0 px-2">Submissão à CVM (Rito Automático/Ordinário) e depósito na B3.</p>
                    </div>
                </div>
                <!-- Step 2 -->
                <div class="col-lg-3">
                    <div class="text-center">
                        <div class="d-inline-flex align-items-center justify-content-center bg-white border border-gold rounded-circle mb-3 shadow-sm" style="width: 48px; height: 48px;">
                            <span class="fw-bold text-gold">02</span>
                        </div>
                        <h4 class="h6 fw-bold text-dark mb-2">Roadshow e Book</h4>
                        <p class="smaller text-muted mb-0 px-2">Apresentação a investidores e formação do livro de ordens em tempo real.</p>
                    </div>
                </div>
                <!-- Step 3 -->
                <div class="col-lg-3">
                    <div class="text-center">
                        <div class="d-inline-flex align-items-center justify-content-center bg-white border border-gold rounded-circle mb-3 shadow-sm" style="width: 48px; height: 48px;">
                            <span class="fw-bold text-gold">03</span>
                        </div>
                        <h4 class="h6 fw-bold text-dark mb-2">Pricing e Alocação</h4>
                        <p class="smaller text-muted mb-0 px-2">Definição final de taxa, volume e rateio entre os investidores.</p>
                    </div>
                </div>
                <!-- Step 4 -->
                <div class="col-lg-3">
                    <div class="text-center">
                        <div class="d-inline-flex align-items-center justify-content-center bg-white border border-gold rounded-circle mb-3 shadow-sm" style="width: 48px; height: 48px;">
                            <span class="fw-bold text-gold">04</span>
                        </div>
                        <h4 class="h6 fw-bold text-dark mb-2">Liquidação (Closing)</h4>
                        <p class="smaller text-muted mb-0 px-2">Transferência de recursos, entrega dos títulos e anúncio de encerramento.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Alcance da Distribuição e Tecnologia -->
<section class="py-5" style="background-color: #f8fafc; border-top: 1px solid var(--border);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge bg-gold-subtle text-gold mb-3 px-3 py-1 text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Rede e Alcance</span>
                <h2 class="h3 fw-bold text-dark mb-4">Capacidade de Distribuição em Escala</h2>
                <p class="text-muted mb-4">
                    Conectamos sua oferta a um sindicato robusto, garantindo que a tese chegue aos bolsos de liquidez corretos — do varejo de alta renda aos maiores fundos institucionais do país.
                </p>
                
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-bottom border-3 border-gold">
                            <div class="h3 fw-bold text-dark mb-0">+30</div>
                            <div class="smaller text-muted">Plataformas de Investimento Conectadas</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-bottom border-3 border-gold">
                            <div class="h3 fw-bold text-dark mb-0">+100</div>
                            <div class="smaller text-muted">Investidores Institucionais (Assets/Pensões)</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="card border-0 rounded-4 overflow-hidden shadow-lg" style="background-color: var(--brand-strong);">
                    <div class="card-header bg-transparent border-bottom border-white-subtle p-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="bg-gold rounded-circle" style="width: 10px; height: 10px;"></div>
                            <div class="text-white smaller fw-bold">DASHBOARD DE BOOKBUILDING - LIVE DATA</div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <!-- Simulated Dashboard Visual -->
                        <div class="d-flex flex-column gap-3">
                            <div class="bg-white-subtle p-2 rounded d-flex justify-content-between align-items-center">
                                <div class="smaller text-white opacity-75">Volume Demandado</div>
                                <div class="fw-bold text-white">R$ 145.200.000</div>
                            </div>
                            <div class="bg-white-subtle p-2 rounded d-flex justify-content-between align-items-center">
                                <div class="smaller text-white opacity-75">Taxa Média Solicitada</div>
                                <div class="fw-bold text-white">CDI + 2.45%</div>
                            </div>
                            <div class="p-2 border border-gold-subtle rounded">
                                <div class="smaller text-white opacity-75 fw-bold mb-2">Composição do Livro</div>
                                <div class="progress" style="height: 8px; background: rgba(255,255,255,0.1);">
                                    <div class="progress-bar bg-gold" style="width: 65%;"></div>
                                    <div class="progress-bar bg-info" style="width: 25%;"></div>
                                    <div class="progress-bar bg-light" style="width: 10%;"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-2" style="font-size: 0.6rem;">
                                    <span class="text-white opacity-75">Institucional (65%)</span>
                                    <span class="text-white opacity-75">Varejo (25%)</span>
                                </div>
                            </div>
                        </div>
                        <p class="smaller text-white opacity-50 mt-4 mb-0" style="font-style: italic;">
                            * Interface BSI Intel: Transparência total para o emissor acompanhar a captação em tempo real.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Estratégia e Ritos -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5">
            <div class="col-lg-6">
                <h2 class="h4 fw-bold text-dark mb-4">Ritos de Oferta (RCVM 160)</h2>
                <p class="text-muted small mb-4">Definimos o rito regulatório ideal para sua oferta, calibrando o custo e o tempo de aprovação ao apetite do mercado.</p>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark small mb-1">Rito Automático</div>
                            <div class="text-muted smaller">Focado em investidores Profissionais e Qualificados, garantindo agilidade no time-to-market.</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="p-3 bg-white rounded-3 shadow-sm border-start border-4 border-gold">
                            <div class="fw-bold text-dark small mb-1">Rito Ordinário</div>
                            <div class="text-muted smaller">Análise prévia da CVM para ofertas com esforços amplos de distribuição, incluindo o varejo.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <h2 class="h4 fw-bold text-dark mb-4">Rede de Distribuição</h2>
                <p class="text-muted small mb-4">Montamos e coordenamos o sindicato de distribuição, conectando sua tese aos canais de liquidez mais eficientes.</p>
                <div class="bg-white p-4 rounded-4 shadow-sm border border-brand-subtle">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-light p-2 rounded-circle text-gold">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>
                            </div>
                            <div>
                                <div class="fw-bold text-dark small">Plataformas e Corretoras</div>
                                <div class="text-muted smaller">Acesso às maiores prateleiras de investimento do país.</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-light p-2 rounded-circle text-gold">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                            </div>
                            <div>
                                <div class="fw-bold text-dark small">Investidores Institucionais</div>
                                <div class="text-muted smaller">Conexão direta com Assets, Pensões e Family Offices.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Da liquidação ao pós-emissão -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Liquidação Precisa e Pós-Emissão</h2>
                <p class="text-muted mb-4 lead">
                    O closing é apenas o começo. Do acompanhamento da liquidação financeira à emissão dos comunicados pós-oferta, garantimos que a transição para a "vida" da operação ocorra com rigor e sem pendências operacionais.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Confirmamos a entrada dos recursos e coordenamos a transferência de titularidade junto ao escriturador e custodiante.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Cuidamos dos comunicados pós-closing: Fatos Relevantes de encerramento, relatórios de alocação e informes mandatórios.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Transparência total via dashboard para você acompanhar o progresso da oferta e a formação do livro de ordens em tempo real.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramos os primeiros pagamentos (juros e amortizações) para garantir que a operação nasça em total conformidade com a escritura.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/registro_distribuicao.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Outros serviços -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Etapas adjacentes da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">O registro e a distribuição conectam a estrutura jurídica ao monitoramento contínuo da operação — conheça as etapas que precedem e sucedem este serviço.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.estrutura-juridica')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Estrutura Jurídica</h3>
                    <p class="text-muted mb-3">Engenharia documental, validação de lastro e garantias e rigor regulatório — a base jurídica que precede o registro da oferta.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.relatorios')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Relatórios e Monitoramento</h3>
                    <p class="text-muted mb-3">Acompanhamento contínuo da operação pós-closing — relatórios periódicos para investidores, agente fiduciário e demais partes da estrutura.</p>
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
</style>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/servicos/registro-distribuicao.blade.php ENDPATH**/ ?>
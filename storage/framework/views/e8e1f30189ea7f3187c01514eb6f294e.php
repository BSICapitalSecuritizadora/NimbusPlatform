<?php $__env->startSection('title', 'Relatórios — BSI Capital'); ?>

<?php $__env->startSection('content'); ?>
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('<?php echo e(asset('images/relatorios.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Serviços</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Relatórios</span> <br>Gerenciais
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Monitoramos o desempenho do lastro e a saúde das garantias de perto. Entregamos informações claras para investidores e agentes fiduciários por meio de relatórios mensais, trimestrais e alertas imediatos sempre que algo importante acontece.
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
                        <img src="<?php echo e(asset('images/relatorios.png')); ?>" class="img-fluid" alt="Relatórios Gerenciais" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Informação de qualidade</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Controle ponta a ponta</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Transparência e rigor técnico</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossos relatórios vão além da obrigação regulatória. Eles são ferramentas criadas para que você tenha visibilidade real sobre a saúde da sua operação.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Visibilidade de Performance</h3>
                    <p class="text-muted mb-0">Acompanhe a inadimplência, as entradas de caixa e a força das garantias. Nós olhamos cada detalhe para que você não tenha surpresas no fim do mês.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Rigor e Conformidade</h3>
                    <p class="text-muted mb-0">Nossos documentos seguem os modelos exigidos pela ANBIMA, CVM e agentes fiduciários. Você recebe tudo no prazo, com qualidade e rastreabilidade total.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Dados que Ajudam a Decidir</h3>
                    <p class="text-muted mb-0">Traduzimos o dia a dia da operação em indicadores claros, como concentração de risco e taxa de pré-pagamento, para apoiar a sua estratégia.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Entregas e Inteligência -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5 align-items-center">
            <div class="col-lg-7">
                <div class="position-relative p-4 bg-white rounded-4 shadow-sm border border-brand-subtle overflow-hidden">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <span class="badge bg-light text-dark border px-3 py-1 rounded-pill smaller fw-bold">Monitoramento de Gatilhos</span>
                        <div class="d-flex gap-1">
                            <div class="bg-gold rounded-circle" style="width: 8px; height: 8px;"></div>
                            <div class="bg-gold rounded-circle" style="width: 8px; height: 8px; opacity: 0.2;"></div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="bg-light rounded-3 p-3 mb-3 border">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="small fw-bold text-muted">Inadimplência (D+30)</div>
                                    <div class="smaller text-success fw-bold">Dentro do Limite</div>
                                </div>
                                <div style="height: 100px; width: 100%; position: relative;">
                                    <canvas id="inadimplenciaChart"></canvas>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="bg-white border p-2 rounded-2 text-center shadow-xs">
                                        <div class="smaller text-muted">Overcollateral</div>
                                        <div class="small fw-bold text-brand">125.4%</div>
                                        <div class="smaller text-success" style="font-size: 0.6rem;">Min: 115%</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-white border p-2 rounded-2 text-center shadow-xs">
                                        <div class="smaller text-muted">LTV Médio</div>
                                        <div class="small fw-bold text-brand">58.2%</div>
                                        <div class="smaller text-success" style="font-size: 0.6rem;">Max: 70%</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="rounded-3 p-3 text-white h-100 shadow-sm" style="background-color: var(--brand-strong, #091b23);">
                                <div class="small fw-bold opacity-75 mb-3">Status de Covenants</div>
                                <div class="d-flex flex-column gap-3">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="smaller opacity-75">Financeiro</span>
                                        <span class="badge bg-success" style="font-size: 0.5rem;">OK</span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="smaller opacity-75">Garantias</span>
                                        <span class="badge bg-success" style="font-size: 0.5rem;">OK</span>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="smaller opacity-75">Operacional</span>
                                        <span class="badge bg-success" style="font-size: 0.5rem;">OK</span>
                                    </div>
                                    <div class="mt-2 pt-2 border-top text-center" style="border-color: rgba(255,255,255,0.15) !important;">
                                        <div class="smaller fw-bold text-gold" style="color: var(--gold, #d4af37);">LIQUIDAÇÃO: NORMAL</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <h2 class="h4 fw-bold text-dark mb-4">Relatórios que entregamos</h2>
                <div class="d-flex flex-column gap-3">
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Visão do Servicer</div>
                        <div class="text-muted smaller">Balanço diário das cobranças, dinheiro que entrou em caixa e o desempenho dos títulos.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Apoio ao Agente Fiduciário</div>
                        <div class="text-muted smaller">Consolidamos os números importantes para garantir que as regras da operação estão sendo cumpridas.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Documentos Oficiais</div>
                        <div class="text-muted smaller">Produzimos e entregamos os Informes Mensais (IM) e Trimestrais exigidos pela CVM.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Checklist de Relatórios -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h4 fw-bold text-dark">Nosso pacote de informações</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Você recebe um conjunto completo de documentos para entender exatamente onde está pisando.</p>
        </div>
        
        <div class="row g-4 justify-content-center text-center">
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Informe Mensal CVM</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Relatório Fiduciário</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Status de Garantias</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Fluxo de Caixa</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="p-3">
                    <div class="mb-3 text-brand">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    </div>
                    <div class="fw-bold smaller text-dark">Informe de Rendimentos</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Caminho Crítico de Mitigação -->
<section class="py-5 bg-light" style="border-top: 1px solid var(--border);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <h2 class="h3 fw-bold text-dark mb-4">Estamos sempre alerta</h2>
                <p class="text-muted">Nosso monitoramento não descansa. Se algum número sair do eixo ou um prazo for quebrado, nosso plano de ação é acionado na hora para proteger o projeto e manter todos informados.</p>
            </div>
            <div class="col-lg-7">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded-4 border shadow-xs h-100 text-center">
                            <div class="h5 fw-bold text-danger mb-2">01</div>
                            <div class="fw-bold small text-dark mb-1">Detecção Rápida</div>
                            <p class="smaller text-muted mb-0">Encontramos o problema imediatamente através da nossa conciliação diária.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded-4 border shadow-xs h-100 text-center">
                            <div class="h5 fw-bold text-warning mb-2">02</div>
                            <div class="fw-bold small text-dark mb-1">Aviso Transparente</div>
                            <p class="smaller text-muted mb-0">Avisamos as partes envolvidas e o Agente Fiduciário sem demora.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-white rounded-4 border shadow-xs h-100 text-center">
                            <div class="h5 fw-bold text-success mb-2">03</div>
                            <div class="fw-bold small text-dark mb-1">Ação na Prática</div>
                            <p class="smaller text-muted mb-0">Colocamos o plano de correção em prática ou acionamos as garantias.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Ciclo de produção dos relatórios -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Como produzimos seus dados</h2>
                <p class="text-muted mb-4 lead">
                    Nossa rotina é construída em cima de precisão e respeito aos prazos. Revisamos cada número antes de enviar, garantindo que você tenha confiança total na informação que recebe.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Agrupamos as informações de inadimplência e recebimentos, sempre de olho nas regras do contrato.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Revisamos tudo tecnicamente antes de disparar os documentos, respeitando o calendário oficial.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Trocamos dados direto com os prestadores de serviço para agilizar o trabalho e evitar erros.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Disponibilizamos tudo de forma segura no Portal do Investidor, criando um arquivo histórico que não pode ser apagado.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('<?php echo e(asset('images/relatorios.png')); ?>') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Serviços relacionados -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Serviços relacionados</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Os relatórios alimentam o portal e sustentam a conformidade regulatória. Conheça os serviços diretamente conectados a esta entrega.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.portal-investidor')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Portal do Investidor</h3>
                    <p class="text-muted mb-3">O ambiente onde todos os relatórios ficam disponíveis. Oferecemos acesso seguro, envio de notificações automáticas e o histórico completo de cada operação.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.monitoramento-regulatorio')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Monitoramento regulatório</h3>
                    <p class="text-muted mb-3">A equipe que garante que a operação ande na linha. Eles definem os padrões de qualidade e os prazos que os relatórios devem seguir para atender a CVM e a ANBIMA.</p>
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

<?php $__env->startPush('scripts'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script nonce="<?php echo e(\Illuminate\Support\Facades\Vite::cspNonce()); ?>">
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('inadimplenciaChart').getContext('2d');
        
        const brand = '#091b23';
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun'],
                datasets: [{
                    label: 'Inadimplência',
                    data: [1.2, 1.8, 1.6, 2.4, 2.2, 3.4],
                    backgroundColor: [
                        'rgba(160, 110, 40, 0.25)',
                        'rgba(160, 110, 40, 0.25)',
                        'rgba(160, 110, 40, 0.25)',
                        'rgba(160, 110, 40, 0.5)',
                        'rgba(160, 110, 40, 0.5)',
                        'rgba(160, 110, 40, 1)',
                    ],
                    borderRadius: {
                        topLeft: 4,
                        topRight: 4,
                        bottomLeft: 0,
                        bottomRight: 0
                    },
                    borderSkipped: false,
                    barPercentage: 0.9,
                    categoryPercentage: 0.9
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: brand,
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + '%';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        display: false,
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        display: false,
                        beginAtZero: true,
                        grid: {
                            display: false
                        }
                    }
                },
                animation: {
                    duration: 1500,
                    easing: 'easeOutQuart'
                }
            }
        });
    });
</script>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/servicos/relatorios.blade.php ENDPATH**/ ?>
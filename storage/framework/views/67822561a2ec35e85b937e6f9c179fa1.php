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
                    Produzimos relatórios periódicos para agente fiduciário, investidores e escriturador — cobrindo desempenho do lastro, comportamento das garantias e eventos críticos com frequência mensal, trimestral e por evento, em conformidade com a escritura de emissão.
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
                            <div class="text-muted small fw-medium">Relatório fiduciário</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Mensal e por evento</div>
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
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossos relatórios são desenhados para apoiar o acompanhamento das operações com máxima clareza e aderência às exigências do mercado e dos órgãos reguladores.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Visibilidade de Performance</h3>
                    <p class="text-muted mb-0">Consolidamos indicadores vitais como curvas de inadimplência, fluxos de recebimento, substituições de lastro e saúde das garantias reais de cada série emitida — com granularidade por devedor e por período.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Rigor e Conformidade</h3>
                    <p class="text-muted mb-0">Relatórios produzidos em conformidade com os modelos da ANBIMA, as normas do ICVM 480 e as exigências do agente fiduciário — com rastreabilidade completa e histórico auditável de cada versão entregue.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Análise Estratégica</h3>
                    <p class="text-muted mb-0">Analisamos concentração de sacados, curva de pré-pagamento, cobertura de overcollateral e evolução do rating do lastro — transformando dados da carteira em inteligência acionável para o investidor e o agente fiduciário.</p>
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
                        <span class="badge bg-light text-dark border px-3 py-1 rounded-pill smaller fw-bold">Monitoramento de Performance</span>
                        <div class="d-flex gap-1">
                            <div class="bg-gold rounded-circle" style="width: 8px; height: 8px;"></div>
                            <div class="bg-gold opacity-20 rounded-circle" style="width: 8px; height: 8px;"></div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="bg-light rounded-3 p-3 mb-3">
                                <div class="small fw-bold text-muted mb-2">Curva de Inadimplência</div>
                                <div style="height: 100px; width: 100%; position: relative;">
                                    <canvas id="inadimplenciaChart"></canvas>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="bg-brand-subtle p-2 rounded-2 text-center">
                                        <div class="smaller text-muted">Overcollateral</div>
                                        <div class="small fw-bold text-brand">125.4%</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-brand-subtle p-2 rounded-2 text-center">
                                        <div class="smaller text-muted">Fundo de Reserva</div>
                                        <div class="small fw-bold text-brand">R$ 1.2M</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="bg-light rounded-3 p-3 h-100">
                                <div class="small fw-bold text-muted mb-3">Checklist Fiduciário</div>
                                <div class="d-flex flex-column gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="bg-gold rounded-circle" style="width: 6px; height: 6px;"></div>
                                        <div class="smaller text-muted">Covenants</div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="bg-gold rounded-circle" style="width: 6px; height: 6px;"></div>
                                        <div class="smaller text-muted">Gatilhos</div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="bg-gold rounded-circle" style="width: 6px; height: 6px;"></div>
                                        <div class="smaller text-muted">Rating</div>
                                    </div>
                                </div>
                                <div class="mt-4 text-center">
                                    <div class="smaller fw-bold text-gold">Status: OK</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <h2 class="h4 fw-bold text-dark mb-4">Relatórios e Entregas</h2>
                <div class="d-flex flex-column gap-3">
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Relatórios de Servicer</div>
                        <div class="text-muted smaller">Conciliação diária de cobrança, fluxos de recebimento e performance de ativos.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Relatórios do Agente Fiduciário</div>
                        <div class="text-muted smaller">Consolidação de indicadores para verificação de covenants e proteção do investidor.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Informes Regulatórios (CVM/ANBIMA)</div>
                        <div class="text-muted smaller">Geração e protocolo do Informe Mensal (IM) e Trimestral em conformidade legal.</div>
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
                <h2 class="h3 fw-bold text-dark mb-4">Do Dado à Entrega: o Ciclo de Produção</h2>
                <p class="text-muted mb-4 lead">
                    Cada relatório segue um ciclo estruturado de coleta, consolidação, revisão técnica e distribuição — garantindo que as informações cheguem aos destinatários certos, no prazo correto e no formato exigido pela escritura.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Consolidação dos dados operacionais da carteira — inadimplência, recebimentos, substituições e eventos de crédito — em conformidade com o cronograma definido na escritura de emissão.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Revisão técnica e envio ao agente fiduciário, investidores e demais partes dentro dos prazos regulatórios — com confirmação de recebimento e registro de entrega.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Integração nativa com os principais servicers e agentes de cobrança do mercado, permitindo a leitura automática de arquivos de remessa e retorno para conciliação diária do lastro.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Publicação no Portal do Investidor com notificação automática e rastreabilidade completa — cada relatório arquivado no histórico da operação com controle de versões.</span>
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
            <p class="text-muted mx-auto" style="max-width: 560px;">Os relatórios alimentam o portal e sustentam a conformidade regulatória — conheça os serviços diretamente conectados a esta entrega.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.portal-investidor')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Portal do Investidor</h3>
                    <p class="text-muted mb-3">Ambiente centralizado onde os relatórios produzidos são publicados — com acesso seguro, notificações automáticas e histórico completo por operação.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="<?php echo e(route('site.servicos.compliance')); ?>" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Compliance</h3>
                    <p class="text-muted mb-3">Gestão de conformidade regulatória que define os padrões e prazos a que cada relatório deve aderir junto à CVM e à ANBIMA.</p>
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

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/resources/views/site/servicos/relatorios.blade.php ENDPATH**/ ?>
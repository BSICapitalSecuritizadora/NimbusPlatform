<?php $__env->startSection('title', 'Parcerias — BSI Capital'); ?>

<?php $__env->startPush('head'); ?>
<style>
    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50%       { transform: translateY(-10px); }
    }
    .float-card { animation: float 4s ease-in-out infinite; }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->startSection('content'); ?>
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('<?php echo e(asset('images/compliance.png')); ?>') center/cover; mix-blend-mode: luminosity;"></div>
    <div class="container position-relative z-1">
        <div class="row align-items-center g-4">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Ecossistema institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Parcerias estruturadas para ampliar <span style="color: var(--gold);">originação, distribuição e execução</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 520px;">
                    A BSI Capital desenvolve parcerias com originadores, consultorias, assessorias, plataformas e agentes do mercado que buscam uma operação tecnicamente sólida, governança clara e capacidade real de execução.
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg px-4">Falar sobre parcerias</a>
                    <a href="<?php echo e(route('proposal.create')); ?>" class="btn btn-lg px-4" style="background: rgba(255,255,255,0.08); color: #E6E4E4; border: 1px solid rgba(230,228,228,0.25);">Enviar oportunidade</a>
                </div>
            </div>
            <div class="col-lg-6 d-none d-lg-flex justify-content-center">
                <div class="position-relative w-100" style="max-width: 480px;">
                    <img src="<?php echo e(asset('images/compliance.png')); ?>" alt="Parcerias BSI Capital" class="img-fluid rounded-3" style="opacity: 0.85;">
                    <div class="float-card position-absolute bottom-0 start-0 translate-middle-y ms-4 bg-white rounded-3 p-3 shadow-lg d-flex align-items-center gap-3" style="min-width: 220px;">
                        <div class="rounded-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px;background:rgba(160,110,40,0.12);">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#A06E28" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="fw-bold text-dark" style="font-size:0.85rem;">Rede de parceiros</div>
                            <div class="text-muted" style="font-size:0.75rem;">Originação · Distribuição · Execução</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-5">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-4">
                <div class="surface-card h-100 p-4 p-lg-5">
                    <div class="section-kicker mb-2">Modelos de parceria</div>
                    <h2 class="h3 fw-bold text-brand mb-3">Estruturas aderentes ao papel de cada parceiro</h2>
                    <p class="section-copy mb-4">
                        Estruturamos relacionamentos que respeitam a origem da oportunidade, o fluxo comercial, a governança documental e a responsabilidade de cada parte ao longo da operação.
                    </p>

                    <div class="d-flex flex-column gap-3">
                        <div class="surface-card-soft p-3">
                            <div class="small text-uppercase text-muted fw-semibold mb-1">Originação</div>
                            <div class="fw-semibold">Parcerias com consultorias, assessorias e originadores especializados por setor.</div>
                        </div>
                        <div class="surface-card-soft p-3">
                            <div class="small text-uppercase text-muted fw-semibold mb-1">Distribuição</div>
                            <div class="fw-semibold">Integração com canais comerciais e relacionamento com investidores dentro da estratégia da oferta.</div>
                        </div>
                        <div class="surface-card-soft p-3">
                            <div class="small text-uppercase text-muted fw-semibold mb-1">Execução</div>
                            <div class="fw-semibold">Coordenação técnico-operacional, governança da documentação e acompanhamento da estrutura até o pós-emissão.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="surface-card h-100 p-4">
                            <div class="section-kicker mb-2">Quem pode se conectar</div>
                            <h3 class="h4 fw-bold text-brand mb-3">Perfis com aderência à nossa atuação</h3>
                            <p class="section-copy mb-0">
                                Trabalhamos com parceiros que agregam acesso a oportunidades, inteligência setorial, base comercial qualificada ou capacidade complementar de execução.
                            </p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="surface-card h-100 p-4">
                            <div class="section-kicker mb-2">Critérios de entrada</div>
                            <h3 class="h4 fw-bold text-brand mb-3">Clareza comercial e disciplina operacional</h3>
                            <p class="section-copy mb-0">
                                Avaliamos aderência da tese, qualidade das informações, contexto regulatório, maturidade da contraparte e viabilidade de estruturação antes do avanço comercial.
                            </p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="surface-card h-100 p-4">
                            <div class="section-kicker mb-2">Setores atendidos</div>
                            <h3 class="h4 fw-bold text-brand mb-3">Imobiliário, agro, infra e crédito corporativo</h3>
                            <p class="section-copy mb-0">
                                As parcerias podem apoiar operações com lastros e dinâmicas setoriais distintas, desde que exista tese consistente, governança e viabilidade fiduciária.
                            </p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="surface-card h-100 p-4">
                            <div class="section-kicker mb-2">Relacionamento</div>
                            <h3 class="h4 fw-bold text-brand mb-3">Fluxo previsível e comunicação objetiva</h3>
                            <p class="section-copy mb-0">
                                Priorizamos alinhamento de expectativas, papéis bem definidos e comunicação contínua entre as partes desde a triagem até o acompanhamento da operação.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5 section-dark">
    <div class="container py-lg-5">
        <div class="row align-items-end g-4 mb-5">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Como trabalhamos</div>
                <h2 class="display-6 fw-bold mb-3">Um processo simples, com filtros técnicos desde o início</h2>
                <p class="mb-0" style="color: #E6E4E4;">
                    O objetivo é acelerar o que faz sentido e interromper cedo o que não atende aos requisitos mínimos de qualidade, estrutura e aderência regulatória.
                </p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 border-0 p-4">
                    <div class="badge badge-soft d-inline-flex align-items-center justify-content-center mb-3" style="width: 44px; height: 44px;">1</div>
                    <h3 class="h5 fw-bold mb-2">Triagem inicial</h3>
                    <p class="text-muted mb-0">Entendimento da tese, da origem da oportunidade, do ativo e do estágio de maturidade da operação.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 border-0 p-4">
                    <div class="badge badge-soft d-inline-flex align-items-center justify-content-center mb-3" style="width: 44px; height: 44px;">2</div>
                    <h3 class="h5 fw-bold mb-2">Enquadramento técnico</h3>
                    <p class="text-muted mb-0">Avaliação de viabilidade, governança mínima, estrutura regulatória e potencial de execução comercial.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 border-0 p-4">
                    <div class="badge badge-soft d-inline-flex align-items-center justify-content-center mb-3" style="width: 44px; height: 44px;">3</div>
                    <h3 class="h5 fw-bold mb-2">Definição do modelo</h3>
                    <p class="text-muted mb-0">Formalização do fluxo de trabalho, responsabilidades, próximos passos e estratégia conjunta de avanço.</p>
                </div>
            </div>
        </div>
    </div>
</section>

</section>

<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-lg-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91,0.05); letter-spacing: 0.1em; font-weight: 600;">Diferencial Boutique</span>
                <h2 class="h3 fw-bold text-dark mb-4">Por que originadores escolhem a BSI Capital?</h2>
                <p class="text-muted mb-4">No mercado de capitais, agilidade e flexibilidade são os ativos mais escassos. Fugimos da estrutura de "massa" para oferecer um suporte de estruturação próximo e resolutivo.</p>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-brand">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polyline></svg>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1" style="font-size: 1rem; color: #0b1220;">Velocidade de Resposta</h5>
                                <p class="small text-muted mb-0">Decisões rápidas de enquadramento técnico, sem as burocracias de grandes comitês bancários.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-brand">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1" style="font-size: 1rem; color: #0b1220;">Flexibilidade em Ativos</h5>
                                <p class="small text-muted mb-0">Expertise para estruturar ativos complexos ou teses de nicho que fogem do padrão de prateleira.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg p-4 p-lg-5" style="border-radius: 20px; background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);">
                    <h4 class="fw-bold mb-4" style="color: var(--brand); font-size: 1.1rem;">Material de Apoio (Enablement)</h4>
                    <p class="text-muted small mb-4">Ajudamos você a apresentar a BSI Capital para seus clientes com materiais técnicos e lâminas de apoio.</p>
                    <div class="list-group list-group-flush mb-4">
                        <a href="#" class="list-group-item list-group-item-action bg-transparent d-flex align-items-center justify-content-between px-0 py-3 border-bottom">
                            <div class="d-flex align-items-center gap-3">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                <span class="fw-medium text-dark">Apresentação Institucional</span>
                            </div>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </a>
                        <a href="#" class="list-group-item list-group-item-action bg-transparent d-flex align-items-center justify-content-between px-0 py-3 border-bottom">
                            <div class="d-flex align-items-center gap-3">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                <span class="fw-medium text-dark">Checklist de Enquadramento</span>
                            </div>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </a>
                    </div>
                    <div class="p-3 rounded-3" style="background: rgba(160,110,40,0.05); border: 1px dashed var(--gold);">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            <span class="small fw-bold text-dark">Portal do Parceiro</span>
                        </div>
                        <p class="small text-muted mb-0">Em breve: Plataforma exclusiva para submissão e acompanhamento de deals em tempo real.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="card border-0 overflow-hidden" style="background: var(--brand-strong);">
            <div class="row g-0 align-items-center">
                <div class="col-lg-8">
                    <div class="p-4 p-lg-5">
                        <div class="section-kicker mb-2">Próximo passo</div>
                        <h2 class="h2 fw-bold text-white mb-3">Vamos estruturar uma parceria com critério técnico e alinhamento comercial?</h2>
                        <p class="mb-0" style="color: #E6E4E4; max-width: 640px;">
                            Se você tem uma oportunidade, uma base de relacionamento ou uma frente comercial aderente à nossa atuação, podemos avaliar o melhor formato de parceria para avançar com segurança.
                        </p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-4 p-lg-5 d-flex flex-column gap-3">
                        <a href="<?php echo e(route('site.contact')); ?>" class="btn btn-brand btn-lg">Falar sobre parcerias</a>
                        <a href="<?php echo e(route('proposal.create')); ?>" class="btn btn-lg" style="background: rgba(255,255,255,0.08); color: #E6E4E4; border: 1px solid rgba(230,228,228,0.25);">Enviar oportunidade</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('site.layout', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/desktop/projects/bsi-capital/resources/views/site/partnerships.blade.php ENDPATH**/ ?>
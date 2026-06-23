@extends('site.layout')
@section('title', 'Parcerias — BSI Capital')

@push('head')
<style>
    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50%       { transform: translateY(-10px); }
    }
    .float-card { animation: float 4s ease-in-out infinite; }
</style>
@endpush

@section('content')
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('{{ asset('images/compliance.png') }}') center/cover; mix-blend-mode: luminosity;"></div>
    <div class="container position-relative z-1">
        <div class="row align-items-center g-4">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Ecossistema institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Parcerias estratégicas para <span style="color: var(--gold);">originação e estruturação de operações</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 520px;">
                    Desenvolvemos relações institucionais com originadores, assessorias, consultorias e agentes do mercado para avaliar oportunidades de CRI, CRA, CR e crédito estruturado com critério técnico, governança comercial e alinhamento de responsabilidades.
                </p>
                <div class="d-grid gap-3 d-lg-flex justify-content-lg-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg px-4 text-center">Apresentar oportunidade de parceria</a>
                    <a href="{{ route('proposal.create') }}" class="btn btn-lg px-4 text-center" style="background: rgba(255,255,255,0.08); color: #E6E4E4; border: 1px solid rgba(230,228,228,0.25);">Enviar tese de operação</a>
                </div>
            </div>
            <div class="col-lg-6 d-none d-lg-flex justify-content-center">
                <div class="position-relative w-100" style="max-width: 480px;">
                    <img src="{{ asset('images/compliance.png') }}" alt="Parcerias BSI Capital" class="img-fluid rounded-3" style="opacity: 0.85;">
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

<section class="py-5" style="background-color: #f8f9fa;">
    <div class="container py-lg-5">
        <style>
            .premium-card {
                background: #ffffff;
                border: 1px solid rgba(5,26,61,0.06);
                border-radius: 1.5rem;
                transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
                position: relative;
                overflow: hidden;
                box-shadow: 0 4px 15px rgba(0,0,0,0.02);
            }
            .premium-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 20px 40px rgba(5,26,61,0.08) !important;
            }
            .premium-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 4px;
                background: linear-gradient(90deg, #d4af37, #f3e5ab);
                opacity: 0;
                transition: opacity 0.4s ease;
            }
            .premium-card:hover::before {
                opacity: 1;
            }
            
            .premium-card-dark {
                background: linear-gradient(145deg, #020918 0%, #051a3d 100%);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 1.5rem;
                color: #ffffff;
                box-shadow: 0 15px 35px rgba(2, 9, 24, 0.15);
                position: relative;
                overflow: hidden;
            }
            
            .premium-soft-box {
                background: rgba(255,255,255,0.03);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 1rem;
                transition: background 0.3s ease;
            }
            .premium-soft-box:hover {
                background: rgba(255,255,255,0.06);
            }
            .premium-kicker {
                font-size: 0.75rem;
                font-weight: 700;
                letter-spacing: 0.15em;
                color: #d4af37;
                text-transform: uppercase;
            }
            .premium-list-item {
                position: relative;
                padding-left: 1.25rem;
                margin-bottom: 0.5rem;
            }
            .premium-list-item::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0.55rem;
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: #d4af37;
            }
        </style>

        <div class="row g-4 align-items-stretch">
            <div class="col-lg-5">
                <div class="premium-card-dark h-100 p-4 p-lg-5 d-flex flex-column">
                    <!-- Decor element -->
                    <div class="position-absolute" style="top: -50px; right: -50px; width: 150px; height: 150px; background: radial-gradient(circle, rgba(212,175,55,0.15) 0%, rgba(0,0,0,0) 70%); border-radius: 50%;"></div>
                    
                    <div class="position-relative z-1">
                        <div class="premium-kicker mb-3">Modelos de parceria</div>
                        <h2 class="h2 fw-bold text-white mb-4" style="line-height: 1.2;">Estruturas aderentes ao papel de cada parceiro</h2>
                        <p class="mb-5" style="color: rgba(255,255,255,0.7); font-size: 1.05rem; line-height: 1.6;">
                            Estruturamos relacionamentos que respeitam a origem da oportunidade, o fluxo comercial, a governança documental e a responsabilidade de cada parte ao longo da operação.
                        </p>
                    </div>

                    <div class="d-flex flex-column gap-3 mt-auto position-relative z-1">
                        <div class="premium-soft-box p-4">
                            <div class="premium-kicker mb-2">Originação</div>
                            <div style="color: #f8f9fa; font-weight: 500; line-height: 1.5; font-size: 0.95rem;">Relacionamento com originadores, consultorias e assessorias que atuam na identificação de oportunidades aderentes a CRI, CRA, CR ou estruturas sob medida.</div>
                        </div>
                        <div class="premium-soft-box p-4">
                            <div class="premium-kicker mb-2">Distribuição</div>
                            <div style="color: #f8f9fa; font-weight: 500; line-height: 1.5; font-size: 0.95rem;">Interlocução com canais comerciais e parceiros de relacionamento, sempre conforme estratégia da oferta, perfil dos investidores e requisitos regulatórios.</div>
                        </div>
                        <div class="premium-soft-box p-4">
                            <div class="premium-kicker mb-2">Execução</div>
                            <div style="color: #f8f9fa; font-weight: 500; line-height: 1.5; font-size: 0.95rem;">Apoio técnico-operacional na organização da documentação, governança de informações e acompanhamento da estrutura ao longo do ciclo da operação.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="row g-4 h-100">
                    <div class="col-md-6">
                        <div class="premium-card h-100 p-4 p-lg-5 d-flex flex-column">
                            <div class="premium-kicker mb-3" style="color: #051a3d;">Quem pode se conectar</div>
                            <h3 class="h4 fw-bold mb-4" style="color: #020918;">Perfis de parceiros com aderência à BSI</h3>
                            <ul class="list-unstyled mb-0 mt-auto text-muted" style="line-height: 1.6; font-size: 0.95rem;">
                                <li class="premium-list-item">Originadores setoriais</li>
                                <li class="premium-list-item">Assessorias e consultorias</li>
                                <li class="premium-list-item">Boutiques de M&amp;A</li>
                                <li class="premium-list-item">Plataformas e canais comerciais</li>
                                <li class="premium-list-item">Parceiros técnicos e operacionais</li>
                                <li class="premium-list-item">Agentes com relacionamento qualificado</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="premium-card h-100 p-4 p-lg-5 d-flex flex-column">
                            <div class="premium-kicker mb-3" style="color: #051a3d;">Avaliação</div>
                            <h3 class="h4 fw-bold mb-4" style="color: #020918;">Critérios para avaliação de parcerias</h3>
                            <p class="text-muted mb-4" style="line-height: 1.6; font-size: 0.95rem;">
                                Avaliações consideram a qualidade da oportunidade, maturidade, governança e aderência aos critérios da BSI Capital.
                            </p>
                            <ul class="list-unstyled mb-0 mt-auto text-muted" style="line-height: 1.6; font-size: 0.95rem;">
                                <li class="premium-list-item">Aderência ao mercado de capitais</li>
                                <li class="premium-list-item">Lastro e fluxo identificáveis</li>
                                <li class="premium-list-item">Viabilidade de estruturação</li>
                                <li class="premium-list-item">Alinhamento e governança</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="premium-card h-100 p-4 p-lg-5 d-flex flex-column">
                            <div class="premium-kicker mb-3" style="color: #051a3d;">Critérios de entrada</div>
                            <h3 class="h4 fw-bold mb-4" style="color: #020918;">Clareza comercial e disciplina operacional</h3>
                            <p class="text-muted mb-0 mt-auto" style="line-height: 1.6; font-size: 0.95rem;">
                                Avaliamos aderência da tese, qualidade das informações, contexto regulatório e viabilidade de estruturação antes de avançar para etapas comerciais.
                            </p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="premium-card h-100 p-4 p-lg-5 d-flex flex-column">
                            <div class="premium-kicker mb-3" style="color: #051a3d;">Relacionamento</div>
                            <h3 class="h4 fw-bold mb-4" style="color: #020918;">Fluxo previsível e comunicação objetiva</h3>
                            <p class="text-muted mb-0 mt-auto" style="line-height: 1.6; font-size: 0.95rem;">
                                Priorizamos alinhamento de expectativas, papéis bem definidos e comunicação contínua entre as partes desde a triagem até o acompanhamento.
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
                <h2 class="display-6 fw-bold mb-3">Processo de avaliação com critérios técnicos desde o início</h2>
                <p class="mb-0" style="color: #E6E4E4;">
                    O objetivo é priorizar oportunidades aderentes à atuação da BSI e direcionar, com transparência, aquelas que não atendam aos critérios técnicos, comerciais ou regulatórios aplicáveis.
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
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91,0.05); letter-spacing: 0.1em; font-weight: 600;">Atuação próxima e criteriosa</span>
                <h2 class="h3 fw-bold text-dark mb-4">Por que parceiros estruturam com a BSI Capital?</h2>
                <p class="text-muted mb-4">No mercado de capitais, proximidade técnica na originação e governança documental são diferenciais. Oferecemos interlocução direta com equipe técnica para discussões de viabilidade desde o início da tese.</p>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-brand">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polyline></svg>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1" style="font-size: 1rem; color: #0b1220;">Análise objetiva de enquadramento</h5>
                                <p class="small text-muted mb-0">Avaliamos a aderência inicial da oportunidade com base em critérios técnicos, comerciais e regulatórios, buscando dar clareza sobre os próximos passos.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-brand">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1" style="font-size: 1rem; color: #0b1220;">Modelagem técnica adequada</h5>
                                <p class="small text-muted mb-0">Experiência na avaliação de ativos, fluxos e estruturas que exigem modelagem específica, desde que haja lastro, documentação e viabilidade técnica compatíveis.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg p-4 p-lg-5" style="border-radius: 20px; background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);">
                    <h4 class="fw-bold mb-4" style="color: var(--brand); font-size: 1.1rem;">Materiais de apoio institucional</h4>
                    <p class="text-muted small mb-4">Disponibilizamos materiais institucionais e informações de apoio para parceiros autorizados apresentarem a atuação da BSI Capital de forma alinhada, técnica e consistente.</p>
                    <div class="list-group list-group-flush mb-4">
                        <div class="list-group-item bg-transparent d-flex align-items-center justify-content-between px-0 py-3 border-bottom" style="cursor: default;">
                            <div class="d-flex align-items-center gap-3">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                <span class="fw-medium text-dark">Apresentação Institucional</span>
                            </div>
                            <span class="badge bg-light text-muted fw-normal ms-auto me-2" style="font-size: 0.7rem; letter-spacing: 0.05em; border: 1px solid rgba(0,0,0,0.05);">Em breve</span>
                        </div>
                        <div class="list-group-item bg-transparent d-flex align-items-center justify-content-between px-0 py-3 border-bottom" style="cursor: default;">
                            <div class="d-flex align-items-center gap-3">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                <span class="fw-medium text-dark">Checklist de Enquadramento</span>
                            </div>
                            <span class="badge bg-light text-muted fw-normal ms-auto me-2" style="font-size: 0.7rem; letter-spacing: 0.05em; border: 1px solid rgba(0,0,0,0.05);">Em breve</span>
                        </div>
                    </div>
                    <div class="p-3 rounded-3" style="background: rgba(160,110,40,0.05); border: 1px dashed var(--gold);">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            <span class="small fw-bold text-dark">Portal do Parceiro</span>
                        </div>
                        <p class="small text-muted mb-0">Em desenvolvimento: ambiente para submissão, organização e acompanhamento de oportunidades de parceria, conforme critérios de acesso e governança definidos pela BSI Capital.</p>
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
                            Se você representa uma oportunidade, uma base de relacionamento ou uma frente comercial aderente à atuação da BSI, podemos avaliar o formato de parceria mais adequado conforme critérios técnicos, comerciais e regulatórios.
                        </p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-4 p-lg-5 d-flex flex-column gap-3">
                        <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg">Apresentar oportunidade de parceria</a>
                        <a href="{{ route('proposal.create') }}" class="btn btn-lg" style="background: rgba(255,255,255,0.08); color: #E6E4E4; border: 1px solid rgba(230,228,228,0.25);">Enviar tese de operação</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@endsection

@extends('site.layout')

@section('title', 'Trabalhe Conosco — BSI Capital')

@section('content')
<section class="hero position-relative d-flex align-items-center" style="min-height: 40vh;">
    <div class="container position-relative">
        <div class="row g-4 align-items-end">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase">Carreiras</span>
                <h1 class="display-4 fw-bold mb-3">Carreiras na <span style="color: var(--gold);">BSI Capital</span></h1>
                <p class="lead mb-0" style="max-width: 760px;">
                    Buscamos profissionais com rigor técnico, senso de responsabilidade e interesse pelo mercado de capitais para atuar em um ambiente de alta complexidade, governança fiduciária e execução operacional.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5 bg-light" style="background-color: #f8f9fa !important; border-top: 1px solid rgba(5,26,61,0.05); border-bottom: 1px solid rgba(5,26,61,0.05);">
    <div class="container py-lg-4">
        <div class="row g-4 align-items-end mb-5">
            <div class="col-lg-8">
                <div style="font-size: 0.75rem; font-weight: 700; letter-spacing: 0.15em; color: #d4af37; text-transform: uppercase; margin-bottom: 0.5rem;">Ambiente de Operações</div>
                <h2 class="h2 fw-bold mb-3" style="color: #020918;">Desenvolvimento técnico no mercado de securitização</h2>
                <p class="mb-0" style="color: #4a5568; font-size: 1.05rem; line-height: 1.6; max-width: 800px;">
                    Na BSI Capital, o desenvolvimento profissional ocorre em contato direto com operações estruturadas, rotinas fiduciárias, documentação, tecnologia e mercado de capitais. Valorizamos profissionais analíticos, responsáveis e comprometidos com qualidade de execução.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end d-none d-lg-block">
                <div style="width: 60px; height: 4px; background: linear-gradient(90deg, #d4af37, #f3e5ab); display: inline-block; border-radius: 2px;"></div>
            </div>
        </div>

        <div class="row g-4 mb-5 pb-5 border-bottom" style="border-color: rgba(5,26,61,0.05) !important;">
            <div class="col-md-6 col-lg-3">
                <div class="premium-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(5,26,61,0.05); display: flex; align-items: center; justify-content: center;">
                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #d4af37;"></div>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2" style="color: #051a3d;">Estruturação e originação</h4>
                    <p class="small text-muted mb-0 mt-auto" style="line-height: 1.5;">Modelagem de operações, prospecção e avaliação técnica inicial.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="premium-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(5,26,61,0.05); display: flex; align-items: center; justify-content: center;">
                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #d4af37;"></div>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2" style="color: #051a3d;">Operações e fiduciário</h4>
                    <p class="small text-muted mb-0 mt-auto" style="line-height: 1.5;">Acompanhamento e governança das emissões (CRI, CRA, CR).</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="premium-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(5,26,61,0.05); display: flex; align-items: center; justify-content: center;">
                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #d4af37;"></div>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2" style="color: #051a3d;">Relações com investidores</h4>
                    <p class="small text-muted mb-0 mt-auto" style="line-height: 1.5;">Comunicação e governança de informações públicas ao mercado.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="premium-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(5,26,61,0.05); display: flex; align-items: center; justify-content: center;">
                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #d4af37;"></div>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2" style="color: #051a3d;">Compliance e riscos</h4>
                    <p class="small text-muted mb-0 mt-auto" style="line-height: 1.5;">Monitoramento regulatório, diligências e controles internos.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="premium-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(5,26,61,0.05); display: flex; align-items: center; justify-content: center;">
                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #d4af37;"></div>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2" style="color: #051a3d;">Tecnologia e dados</h4>
                    <p class="small text-muted mb-0 mt-auto" style="line-height: 1.5;">Desenvolvimento de sistemas internos, automações, integrações e ferramentas para apoiar governança documental e acompanhamento das operações.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="premium-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(5,26,61,0.05); display: flex; align-items: center; justify-content: center;">
                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #d4af37;"></div>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2" style="color: #051a3d;">Jurídico e documentação</h4>
                    <p class="small text-muted mb-0 mt-auto" style="line-height: 1.5;">Apoio em contratos, governança societária e instrumentos da securitização.</p>
                </div>
            </div>
            <div class="col-md-12 col-lg-4">
                <div class="premium-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(5,26,61,0.05); display: flex; align-items: center; justify-content: center;">
                            <div style="width: 8px; height: 8px; border-radius: 50%; background: #d4af37;"></div>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2" style="color: #051a3d;">Administrativo e financeiro</h4>
                    <p class="small text-muted mb-0 mt-auto" style="line-height: 1.5;">Controles internos, tesouraria e rotinas administrativas da securitizadora.</p>
                </div>
            </div>
        </div>

        <div class="row g-4 align-items-center">
            <div class="col-lg-4">
                <div style="font-size: 0.75rem; font-weight: 700; letter-spacing: 0.15em; color: #d4af37; text-transform: uppercase; margin-bottom: 0.5rem;">Perfil procurado</div>
                <h3 class="h3 fw-bold mb-3" style="color: #020918;">O que valorizamos</h3>
                <p class="text-muted mb-0" style="line-height: 1.6;">Busca por alinhamento cultural focado em entregas de qualidade.</p>
            </div>
            <div class="col-lg-8">
                <style>
                    .premium-pill {
                        background: #ffffff;
                        color: #051a3d;
                        border: 1px solid rgba(5,26,61,0.08);
                        padding: 0.6rem 1.25rem;
                        border-radius: 50rem;
                        font-weight: 500;
                        font-size: 0.9rem;
                        transition: all 0.3s ease;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
                        display: inline-block;
                        cursor: default;
                    }
                    .premium-pill:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 8px 15px rgba(5,26,61,0.05);
                        border-color: rgba(212,175,55,0.4);
                        color: #d4af37;
                    }
                </style>
                <div class="d-flex flex-wrap gap-2">
                    <span class="premium-pill">Rigor técnico</span>
                    <span class="premium-pill">Ética e responsabilidade</span>
                    <span class="premium-pill">Atenção a detalhes</span>
                    <span class="premium-pill">Colaboração entre áreas</span>
                    <span class="premium-pill">Confidencialidade</span>
                    <span class="premium-pill">Pensamento analítico</span>
                    <span class="premium-pill">Aprendizado prático e contínuo</span>
                    <span class="premium-pill">Interesse por mercado de capitais</span>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-lg-4">
        <div class="row g-4 align-items-end mb-4">
            <div class="col-lg-8">
                <h2 class="h3 fw-bold text-brand mb-1">Oportunidades abertas</h2>
            </div>
        </div>        <style>
            .premium-empty-state {
                background: linear-gradient(to right, rgba(5,26,61,0.02), rgba(5,26,61,0.05), rgba(5,26,61,0.02));
                border: 1px dashed rgba(5,26,61,0.15);
                border-radius: 1.5rem;
                transition: all 0.3s ease;
            }
            .premium-empty-state:hover {
                background: linear-gradient(to right, rgba(5,26,61,0.03), rgba(5,26,61,0.06), rgba(5,26,61,0.03));
                border-color: rgba(212,175,55,0.4);
            }
            
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
            
            .step-badge {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: rgba(212,175,55,0.15);
                color: #d4af37;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 0.9rem;
                border: 1px solid rgba(212,175,55,0.3);
            }
        </style>

        <div class="row g-4 mb-5">
            @forelse($vacancies as $vacancy)
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 border-0 shadow-sm card-hover" style="border-radius: 1rem; border: 1px solid rgba(5,26,61,0.05) !important;">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-3 gap-3">
                                <span class="badge" style="background: rgba(5,26,61,0.05); color: #051a3d; padding: 0.5rem 1rem; border-radius: 50rem; font-weight: 600;">{{ $vacancy->department ?? 'Geral' }}</span>
                                <span class="small text-muted fw-semibold">{{ $vacancy->type }}</span>
                            </div>
                            <h3 class="h4 fw-bold text-brand mb-3">{{ $vacancy->title }}</h3>
                            <div class="d-flex align-items-center gap-2 text-muted small mb-4">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                {{ $vacancy->location }}
                            </div>
                            <div class="mt-auto">
                                <a href="{{ route('site.vacancies.show', $vacancy->slug) }}" class="btn w-100" style="border: 1px solid #051a3d; color: #051a3d; border-radius: 0.5rem; transition: all 0.3s ease;" onmouseover="this.style.background='#051a3d'; this.style.color='#fff';" onmouseout="this.style.background='transparent'; this.style.color='#051a3d';">Conhecer oportunidade</a>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="premium-empty-state p-5 text-center">
                        <div class="mb-4 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; border-radius: 50%; background: #ffffff; box-shadow: 0 10px 20px rgba(0,0,0,0.03);">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#d4af37" stroke-width="1.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        </div>
                        <h4 class="h4 fw-bold text-brand mb-3">No momento, não há vagas abertas</h4>
                        <p class="text-muted mx-auto mb-4" style="max-width: 500px; font-size: 1.1rem; line-height: 1.6;">
                            Cadastre seu perfil em nosso banco de talentos para futuras oportunidades na BSI Capital.
                        </p>
                        <div class="d-flex justify-content-center">
                            <a href="mailto:contato@bsicapital.com.br" class="btn btn-lg px-5" style="background: linear-gradient(135deg, #020918 0%, #051a3d 100%); color: #fff; border: none; border-radius: 0.5rem; box-shadow: 0 4px 15px rgba(2,9,24,0.2);">Cadastrar currículo</a>
                        </div>
                    </div>
                </div>
            @endforelse
        </div>

        <div class="row g-4 align-items-stretch">
            <div class="col-lg-6">
                <div class="premium-card-dark h-100 p-4 p-lg-5 d-flex flex-column">
                    <!-- Decor element -->
                    <div class="position-absolute" style="top: -30px; right: -30px; width: 120px; height: 120px; background: radial-gradient(circle, rgba(212,175,55,0.15) 0%, rgba(0,0,0,0) 70%); border-radius: 50%;"></div>
                    
                    <div class="position-relative z-1 d-flex align-items-center gap-3 mb-4">
                        <div class="p-3 rounded" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d4af37" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                        <h3 class="h4 fw-bold mb-0 text-white">Banco de talentos</h3>
                    </div>
                    
                    <p class="mb-4 position-relative z-1" style="color: rgba(255,255,255,0.75); line-height: 1.7; font-size: 1.05rem;">
                        Mesmo quando não há vagas abertas, a BSI Capital mantém canais para conhecer profissionais interessados em atuar com securitização, crédito estruturado, tecnologia, operações e governança.
                    </p>
                    
                    <div class="mt-auto position-relative z-1">
                        <a href="mailto:contato@bsicapital.com.br" class="btn" style="background: rgba(255,255,255,0.08); color: #fff; border: 1px solid rgba(255,255,255,0.2); padding: 0.75rem 1.5rem; border-radius: 0.5rem; transition: all 0.3s ease;" onmouseover="this.style.background='rgba(255,255,255,0.15)';" onmouseout="this.style.background='rgba(255,255,255,0.08)';">Cadastrar currículo</a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="premium-card h-100 p-4 p-lg-5 d-flex flex-column">
                    <h3 class="h4 fw-bold mb-4 text-brand">Como funciona o processo seletivo</h3>
                    
                    <div class="d-flex flex-column gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="step-badge">1</div>
                            <div>
                                <span class="fw-semibold text-dark d-block" style="font-size: 1.05rem;">Cadastro ou envio do currículo</span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="step-badge">2</div>
                            <div>
                                <span class="fw-semibold text-dark d-block" style="font-size: 1.05rem;">Triagem curricular</span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="step-badge">3</div>
                            <div>
                                <span class="fw-semibold text-dark d-block" style="font-size: 1.05rem;">Conversas técnicas e comportamentais</span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="step-badge">4</div>
                            <div>
                                <span class="fw-semibold text-dark d-block" style="font-size: 1.05rem;">Avaliação final e retorno</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-auto pt-4 mt-4" style="border-top: 1px dashed rgba(5,26,61,0.1);">
                        <p class="small text-muted mb-0" style="line-height: 1.5;">
                            *As etapas podem variar conforme a área, senioridade e perfil da vaga. Os dados enviados serão tratados conforme a Política de Privacidade da BSI Capital, as normas aplicáveis e as rotinas internas de recrutamento e seleção.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
</section>
@endsection

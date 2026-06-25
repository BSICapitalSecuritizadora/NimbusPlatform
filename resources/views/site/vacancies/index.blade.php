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

@push('head')
<style>
    .careers-page {
        background: #E6E4E4;
        color: #091B23;
    }
    
    .careers-eyebrow {
        color: #A06E28;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        font-size: 0.75rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    
    .careers-section-title {
        color: #091B23;
    }
    
    .careers-section-text {
        color: rgba(9, 27, 35, 0.72);
        line-height: 1.6;
    }
    
    .careers-area-card {
        background: #FFFFFF;
        border: 1px solid rgba(9, 27, 35, 0.10);
        border-radius: 16px;
        box-shadow: 0 14px 34px rgba(9, 27, 35, 0.06);
        transition: border-color 180ms ease, transform 180ms ease, box-shadow 180ms ease;
    }
    
    .careers-area-card:hover {
        border-color: rgba(160, 110, 40, 0.40);
        transform: translateY(-2px);
        box-shadow: 0 18px 40px rgba(9, 27, 35, 0.09);
    }
    
    .careers-area-card__icon {
        color: #A06E28;
        background: rgba(160, 110, 40, 0.08);
        border: 1px solid rgba(160, 110, 40, 0.18);
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .careers-area-card__title {
        color: #091B23;
    }
    
    .careers-area-card__text {
        color: rgba(9, 27, 35, 0.70);
    }
    
    .careers-chip {
        background: #FFFFFF;
        border: 1px solid rgba(9, 27, 35, 0.12);
        color: #091B23;
        padding: 0.6rem 1.25rem;
        border-radius: 50rem;
        font-weight: 500;
        font-size: 0.9rem;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        display: inline-block;
        cursor: default;
    }
    
    .careers-chip:hover {
        background: rgba(160, 110, 40, 0.10);
        border-color: rgba(160, 110, 40, 0.35);
        color: #A06E28;
        transform: translateY(-2px);
        box-shadow: 0 8px 15px rgba(9, 27, 35, 0.05);
    }
    
    .careers-empty-state {
        background: rgba(255, 255, 255, 0.62);
        border: 1px dashed rgba(160, 110, 40, 0.35);
        border-radius: 18px;
    }
    
    .careers-dark-card {
        background: linear-gradient(135deg, #091B23 0%, #0B2029 60%, #091B23 100%);
        border: 1px solid rgba(160, 110, 40, 0.35);
        border-radius: 1.5rem;
        color: #E6E4E4;
        position: relative;
        overflow: hidden;
    }
    
    .careers-dark-card p {
        color: rgba(230, 228, 228, 0.72);
    }
    
    .careers-light-card {
        background: #FFFFFF;
        border: 1px solid rgba(9, 27, 35, 0.10);
        border-radius: 1.5rem;
        color: #091B23;
    }
    
    .careers-step-badge {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: rgba(160, 110, 40, 0.08);
        color: #A06E28;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        border: 1px solid rgba(160, 110, 40, 0.18);
    }
</style>
@endpush

<section class="py-5 careers-page">
    <div class="container py-lg-4">
        <!-- Section: Desenvolvimento técnico -->
        <div class="row g-4 align-items-end mb-5">
            <div class="col-lg-8">
                <div class="careers-eyebrow">Ambiente de Operações</div>
                <h2 class="h2 fw-bold mb-3 careers-section-title">Desenvolvimento técnico no mercado de securitização</h2>
                <p class="mb-0 careers-section-text" style="font-size: 1.05rem; max-width: 800px;">
                    Na BSI Capital, o desenvolvimento profissional ocorre em contato direto com operações estruturadas, rotinas fiduciárias, documentação, tecnologia e mercado de capitais. Valorizamos profissionais analíticos, responsáveis e comprometidos com qualidade de execução.
                </p>
            </div>
        </div>

        <div class="row g-4 mb-5 pb-5 border-bottom" style="border-color: rgba(9, 27, 35, 0.08) !important;">
            <div class="col-md-6 col-lg-3">
                <div class="careers-area-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div class="careers-area-card__icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2 careers-area-card__title">Estruturação e originação</h4>
                    <p class="small mb-0 mt-auto careers-area-card__text" style="line-height: 1.5;">Modelagem de operações, prospecção e avaliação técnica inicial.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="careers-area-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div class="careers-area-card__icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2 careers-area-card__title">Operações e fiduciário</h4>
                    <p class="small mb-0 mt-auto careers-area-card__text" style="line-height: 1.5;">Acompanhamento e governança das emissões (CRI, CRA, CR).</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="careers-area-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div class="careers-area-card__icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2 careers-area-card__title">Relações com investidores</h4>
                    <p class="small mb-0 mt-auto careers-area-card__text" style="line-height: 1.5;">Comunicação e governança de informações públicas ao mercado.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="careers-area-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div class="careers-area-card__icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2 careers-area-card__title">Compliance e riscos</h4>
                    <p class="small mb-0 mt-auto careers-area-card__text" style="line-height: 1.5;">Monitoramento regulatório, diligências e controles internos.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="careers-area-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div class="careers-area-card__icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2 careers-area-card__title">Tecnologia e dados</h4>
                    <p class="small mb-0 mt-auto careers-area-card__text" style="line-height: 1.5;">Desenvolvimento de sistemas internos, automações, integrações e ferramentas para apoiar governança documental e acompanhamento das operações.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="careers-area-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div class="careers-area-card__icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2 careers-area-card__title">Jurídico e documentação</h4>
                    <p class="small mb-0 mt-auto careers-area-card__text" style="line-height: 1.5;">Apoio em contratos, governança societária e instrumentos da securitização.</p>
                </div>
            </div>
            <div class="col-md-12 col-lg-4">
                <div class="careers-area-card h-100 p-4 d-flex flex-column">
                    <div class="mb-3">
                        <div class="careers-area-card__icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                        </div>
                    </div>
                    <h4 class="h6 fw-bold mb-2 careers-area-card__title">Administrativo e financeiro</h4>
                    <p class="small mb-0 mt-auto careers-area-card__text" style="line-height: 1.5;">Controles internos, tesouraria e rotinas administrativas da securitizadora.</p>
                </div>
            </div>
        </div>

        <!-- Section: O que valorizamos -->
        <div class="row g-4 align-items-center mb-5 pb-5">
            <div class="col-lg-4">
                <div class="careers-eyebrow">Perfil procurado</div>
                <h3 class="h3 fw-bold mb-3 careers-section-title">O que valorizamos</h3>
                <p class="mb-0 careers-section-text">Busca por alinhamento cultural focado em entregas de qualidade.</p>
            </div>
            <div class="col-lg-8">
                <div class="d-flex flex-wrap gap-2">
                    <span class="careers-chip">Rigor técnico</span>
                    <span class="careers-chip">Ética e responsabilidade</span>
                    <span class="careers-chip">Atenção a detalhes</span>
                    <span class="careers-chip">Colaboração entre áreas</span>
                    <span class="careers-chip">Confidencialidade</span>
                    <span class="careers-chip">Pensamento analítico</span>
                    <span class="careers-chip">Aprendizado prático e contínuo</span>
                    <span class="careers-chip">Interesse por mercado de capitais</span>
                </div>
            </div>
        </div>

        <!-- Section: Oportunidades abertas -->
        <div class="row g-4 align-items-end mb-4">
            <div class="col-lg-8">
                <h2 class="h3 fw-bold mb-1 careers-section-title">Oportunidades abertas</h2>
            </div>
        </div>

        <div class="row g-4 mb-5 pb-4">
            @forelse($vacancies as $vacancy)
                <div class="col-md-6 col-xl-4">
                    <div class="careers-area-card h-100 d-flex flex-column border-0" style="padding: 1px;">
                        <div class="card-body p-4 d-flex flex-column bg-white" style="border-radius: 15px;">
                            <div class="d-flex justify-content-between align-items-start mb-3 gap-3">
                                <span class="badge" style="background: rgba(160, 110, 40, 0.08); color: #A06E28; padding: 0.5rem 1rem; border-radius: 50rem; font-weight: 600; border: 1px solid rgba(160, 110, 40, 0.18);">{{ $vacancy->department ?? 'Geral' }}</span>
                                <span class="small fw-semibold careers-section-text">{{ $vacancy->type }}</span>
                            </div>
                            <h3 class="h4 fw-bold mb-3 careers-area-card__title">{{ $vacancy->title }}</h3>
                            <div class="d-flex align-items-center gap-2 small mb-4 careers-section-text">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                {{ $vacancy->location }}
                            </div>
                            <div class="mt-auto">
                                <a href="{{ route('site.vacancies.show', $vacancy->slug) }}" class="btn w-100" style="border: 1px solid #091B23; color: #091B23; border-radius: 0.5rem; transition: all 0.3s ease;" onmouseover="this.style.background='#091B23'; this.style.color='#E6E4E4';" onmouseout="this.style.background='transparent'; this.style.color='#091B23';">Conhecer oportunidade</a>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="careers-empty-state p-5 text-center">
                        <div class="mb-4 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; border-radius: 50%; background: #ffffff; box-shadow: 0 10px 20px rgba(9,27,35,0.04);">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#A06E28" stroke-width="1.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        </div>
                        <h4 class="h4 fw-bold mb-3" style="color: #091B23;">No momento, não há vagas abertas</h4>
                        <p class="mx-auto mb-4" style="max-width: 500px; font-size: 1.1rem; line-height: 1.6; color: rgba(9, 27, 35, 0.68);">
                            Cadastre seu perfil em nosso banco de talentos para futuras oportunidades na BSI Capital.
                        </p>
                        <div class="d-flex justify-content-center">
                            <a href="mailto:contato@bsicapital.com.br" class="btn btn-lg px-5" style="background: #091B23; color: #E6E4E4; border: none; border-radius: 0.5rem; box-shadow: 0 4px 15px rgba(9,27,35,0.15); transition: background 0.3s ease;" onmouseover="this.style.background='#A06E28';" onmouseout="this.style.background='#091B23';">Cadastrar currículo</a>
                        </div>
                    </div>
                </div>
            @endforelse
        </div>

        <!-- Section: Banco de talentos e Processo Seletivo -->
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-6">
                <div class="careers-dark-card h-100 p-4 p-lg-5 d-flex flex-column">
                    <!-- Decor element -->
                    <div class="position-absolute" style="top: -30px; right: -30px; width: 120px; height: 120px; background: radial-gradient(circle, rgba(160,110,40,0.15) 0%, rgba(0,0,0,0) 70%); border-radius: 50%;"></div>
                    
                    <div class="position-relative z-1 d-flex align-items-center gap-3 mb-4">
                        <div class="p-3 rounded" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(160,110,40,0.20);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#A06E28" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                        <h3 class="h4 fw-bold mb-0 text-white">Banco de talentos</h3>
                    </div>
                    
                    <p class="mb-4 position-relative z-1" style="line-height: 1.7; font-size: 1.05rem;">
                        Mesmo quando não há vagas abertas, a BSI Capital mantém canais para conhecer profissionais interessados em atuar com securitização, crédito estruturado, tecnologia, operações e governança.
                    </p>
                    
                    <div class="mt-auto position-relative z-1">
                        <a href="mailto:contato@bsicapital.com.br" class="btn" style="background: #E6E4E4; color: #091B23; padding: 0.75rem 1.5rem; border-radius: 0.5rem; transition: all 0.3s ease;" onmouseover="this.style.background='#A06E28'; this.style.color='#091B23';" onmouseout="this.style.background='#E6E4E4'; this.style.color='#091B23';">Cadastrar currículo</a>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="careers-light-card h-100 p-4 p-lg-5 d-flex flex-column">
                    <h3 class="h4 fw-bold mb-4 careers-section-title">Como funciona o processo seletivo</h3>
                    
                    <div class="d-flex flex-column gap-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="careers-step-badge">1</div>
                            <div>
                                <span class="fw-semibold d-block" style="font-size: 1.05rem; color: #091B23;">Cadastro ou envio do currículo</span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="careers-step-badge">2</div>
                            <div>
                                <span class="fw-semibold d-block" style="font-size: 1.05rem; color: #091B23;">Triagem curricular</span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="careers-step-badge">3</div>
                            <div>
                                <span class="fw-semibold d-block" style="font-size: 1.05rem; color: #091B23;">Conversas técnicas e comportamentais</span>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="careers-step-badge">4</div>
                            <div>
                                <span class="fw-semibold d-block" style="font-size: 1.05rem; color: #091B23;">Avaliação final e retorno</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-auto pt-4 mt-4" style="border-top: 1px dashed rgba(9, 27, 35, 0.08);">
                        <p class="small mb-0 careers-section-text" style="line-height: 1.5;">
                            *As etapas podem variar conforme a área, senioridade e perfil da vaga. Os dados enviados serão tratados conforme a Política de Privacidade da BSI Capital, as normas aplicáveis e as rotinas internas de recrutamento e seleção.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
</section>
@endsection

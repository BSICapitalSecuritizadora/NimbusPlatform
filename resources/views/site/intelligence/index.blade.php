@extends('site.layout')
@section('title', 'BSI Intelligence - Relatórios e Análises de Mercado')

@section('content')
<section class="hero-small position-relative overflow-hidden" style="background: linear-gradient(135deg, #020918 0%, #051a3d 100%); padding: 100px 0 60px;">
    <div class="container position-relative z-1">
        <div class="row">
            <div class="col-lg-8">
                <div class="kicker text-gold mb-3">Relatórios e análises de mercado</div>
                <h1 class="display-4 fw-bold text-white mb-4">BSI Intelligence</h1>
                <p class="lead text-white-50 mb-0">
                    Conteúdos, relatórios e leituras de mercado sobre securitização, crédito estruturado, CRI, CRA, CR e temas regulatórios, produzidos para apoiar o acompanhamento técnico do mercado de capitais.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-4">
        <div class="mb-5">
            <h2 class="h4 fw-bold text-brand mb-4">Temas acompanhados pela BSI Intelligence</h2>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Mercado de CRI</span>
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Mercado de CRA</span>
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Certificados de Recebíveis</span>
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Crédito estruturado</span>
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Agronegócio</span>
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Mercado imobiliário</span>
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Regulação CVM</span>
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Tendências de funding</span>
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Governança e transparência</span>
                <span class="badge px-3 py-2 text-brand" style="background: rgba(9,27,35,0.04); border: 1px solid rgba(9,27,35,0.08); font-size: 0.9rem; font-weight: 500;">Dados de emissões</span>
            </div>
        </div>

        <style>
            .filter-pill {
                background-color: #fff;
                border: 1px solid rgba(5,26,61,0.1);
                color: #051a3d;
                border-radius: 50rem;
                padding: 0.5rem 1.25rem;
                font-weight: 500;
                font-size: 0.9rem;
                box-shadow: 0 2px 10px rgba(0,0,0,0.02);
                cursor: not-allowed;
            }
            .report-card {
                transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
                border: 1px solid rgba(5,26,61,0.08);
                border-radius: 1.25rem;
                background: #ffffff;
            }
            .report-card:hover {
                transform: translateY(-6px);
                box-shadow: 0 20px 40px rgba(5,26,61,0.08) !important;
                border-color: rgba(212,175,55,0.4);
            }
            .report-img-wrapper {
                border-radius: 1rem;
                overflow: hidden;
                margin: 0.75rem;
                background: #f8f9fa;
            }
            .report-img-wrapper img {
                transition: transform 0.6s cubic-bezier(0.165, 0.84, 0.44, 1);
            }
            .report-card:hover .report-img-wrapper img {
                transform: scale(1.08);
            }
            .badge-category {
                background: rgba(5,26,61,0.06);
                color: #051a3d;
                border: 1px solid rgba(5,26,61,0.1);
                font-weight: 600;
                letter-spacing: 0.2px;
                padding: 0.4em 0.8em;
            }
            .badge-type {
                background: rgba(212,175,55,0.1);
                color: #b5952f;
                border: 1px solid rgba(212,175,55,0.25);
                font-weight: 600;
                padding: 0.4em 0.8em;
            }
            .btn-em-breve {
                background: rgba(5,26,61,0.03);
                color: rgba(5,26,61,0.5);
                border: 1px solid rgba(5,26,61,0.1);
                border-radius: 50rem;
                font-weight: 500;
                padding: 0.35rem 1rem;
                font-size: 0.85rem;
                cursor: not-allowed;
            }
        </style>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-5 pb-4 border-bottom" style="border-color: rgba(5,26,61,0.08) !important;">
            <div class="d-flex flex-wrap gap-2">
                <select class="form-select filter-pill w-auto" disabled>
                    <option selected>Categoria</option>
                </select>
                <select class="form-select filter-pill w-auto" disabled>
                    <option selected>Setor</option>
                </select>
                <select class="form-select filter-pill w-auto" disabled>
                    <option selected>Tipo de conteúdo</option>
                </select>
            </div>
            <div class="position-relative" style="min-width: 280px;">
                <i class="bi bi-search position-absolute top-50 translate-middle-y text-muted" style="left: 1.25rem;"></i>
                <input type="text" class="form-control filter-pill w-100" placeholder="Buscar palavra-chave..." disabled style="padding-left: 2.75rem;">
            </div>
        </div>

        <div class="row g-4">
            {{-- Relatório Placeholder 1 --}}
            <div class="col-lg-6">
                <div class="report-card d-flex flex-column flex-sm-row h-100 shadow-sm text-decoration-none">
                    <div class="col-sm-5 d-flex flex-column">
                        <div class="report-img-wrapper flex-grow-1 position-relative" style="min-height: 220px;">
                            <img src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=600&auto=format&fit=crop" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" alt="Panorama Mercado">
                            <div class="position-absolute top-0 start-0 w-100 h-100" style="background: linear-gradient(to top, rgba(2,9,24,0.3) 0%, transparent 60%);"></div>
                        </div>
                    </div>
                    <div class="col-sm-7 d-flex flex-column justify-content-between p-4 ps-sm-2">
                        <div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <span class="badge rounded-pill badge-category">CRI / Imobiliário</span>
                                <span class="badge rounded-pill badge-type">Panorama</span>
                            </div>
                            <h3 class="h5 fw-bold mb-3" style="color: #020918; line-height: 1.4;">Panorama BSI: Mercado de CRI</h3>
                            <p class="small text-muted mb-4" style="line-height: 1.6; font-size: 0.9rem;">Leitura de mercado sobre o volume de emissões e a evolução das normas CVM aplicáveis ao setor imobiliário.</p>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-3 small text-muted mb-3" style="font-size: 0.85rem;">
                                <div class="d-flex align-items-center"><i class="bi bi-clock me-2 text-brand"></i> Em breve</div>
                                <div class="d-flex align-items-center"><i class="bi bi-building me-2 text-brand"></i> Intelligence</div>
                            </div>
                            <div class="btn-em-breve d-inline-block">Conteúdo em breve</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Relatório Placeholder 2 --}}
            <div class="col-lg-6">
                <div class="report-card d-flex flex-column flex-sm-row h-100 shadow-sm text-decoration-none">
                    <div class="col-sm-5 d-flex flex-column">
                        <div class="report-img-wrapper flex-grow-1 position-relative" style="min-height: 220px;">
                            <img src="https://images.unsplash.com/photo-1573164713988-8665fc963095?q=80&w=600&auto=format&fit=crop" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" alt="Estudo de setor">
                            <div class="position-absolute top-0 start-0 w-100 h-100" style="background: linear-gradient(to top, rgba(2,9,24,0.3) 0%, transparent 60%);"></div>
                        </div>
                    </div>
                    <div class="col-sm-7 d-flex flex-column justify-content-between p-4 ps-sm-2">
                        <div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <span class="badge rounded-pill badge-category">CRA / Agronegócio</span>
                                <span class="badge rounded-pill badge-type">Estudo Setorial</span>
                            </div>
                            <h3 class="h5 fw-bold mb-3" style="color: #020918; line-height: 1.4;">Crédito para o Agronegócio: Dinâmica do CRA</h3>
                            <p class="small text-muted mb-4" style="line-height: 1.6; font-size: 0.9rem;">Acompanhamento sobre como as estruturas de securitização apoiam o financiamento de produtores rurais.</p>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-3 small text-muted mb-3" style="font-size: 0.85rem;">
                                <div class="d-flex align-items-center"><i class="bi bi-clock me-2 text-brand"></i> Em breve</div>
                                <div class="d-flex align-items-center"><i class="bi bi-building me-2 text-brand"></i> Intelligence</div>
                            </div>
                            <div class="btn-em-breve d-inline-block">Conteúdo em breve</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Newsletter Signup --}}
        <div class="mt-5 p-4 p-lg-5 rounded-4 position-relative overflow-hidden" style="background: linear-gradient(135deg, #020918 0%, #051a3d 100%); border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 20px 40px rgba(2, 9, 24, 0.2);">
            <!-- Decorative gradient orbs -->
            <div class="position-absolute" style="top: -50px; left: -50px; width: 250px; height: 250px; background: radial-gradient(circle, rgba(212,175,55,0.15) 0%, rgba(0,0,0,0) 70%); border-radius: 50%;"></div>
            <div class="position-absolute" style="bottom: -100px; right: -50px; width: 300px; height: 300px; background: radial-gradient(circle, rgba(255,255,255,0.03) 0%, rgba(0,0,0,0) 70%); border-radius: 50%;"></div>
            
            <div class="position-relative z-1 text-center">
                <span class="badge mb-3 px-3 py-2 rounded-pill" style="background: rgba(212,175,55,0.1); color: #d4af37; border: 1px solid rgba(212,175,55,0.2); font-weight: 500; letter-spacing: 0.5px;">Newsletter</span>
                <h3 class="h3 fw-bold mb-3 text-white" style="letter-spacing: -0.5px;">Receba novos conteúdos da BSI Intelligence</h3>
                <p class="mb-4 mx-auto" style="max-width: 600px; color: rgba(255,255,255,0.7); font-size: 1.05rem;">
                    Acompanhe atualizações técnicas, resumos regulatórios e estudos setoriais para apoiar suas decisões e manter-se informado.
                </p>
                <style>
                    .newsletter-input::placeholder {
                        color: rgba(255,255,255,0.4) !important;
                    }
                    .newsletter-input:focus {
                        background-color: rgba(255,255,255,0.05) !important;
                        border-color: #d4af37 !important;
                        box-shadow: 0 0 0 0.25rem rgba(212,175,55,0.25) !important;
                        color: #fff !important;
                    }
                    .btn-newsletter {
                        background: linear-gradient(135deg, #d4af37 0%, #b5952f 100%);
                        color: #020918;
                        border: none;
                        transition: all 0.3s ease;
                    }
                    .btn-newsletter:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 6px 20px rgba(212,175,55,0.4);
                        background: linear-gradient(135deg, #e5c355 0%, #c5a439 100%);
                        color: #020918;
                    }
                </style>
                <form class="d-flex flex-column flex-sm-row gap-2 justify-content-center mx-auto mb-4" style="max-width: 550px;">
                    <div class="position-relative flex-grow-1">
                        <input type="email" class="form-control form-control-lg newsletter-input w-100" placeholder="Seu endereço de e-mail" required style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); color: #fff; border-radius: 8px;">
                    </div>
                    <button type="button" class="btn btn-lg px-4 fw-semibold btn-newsletter" style="border-radius: 8px;">
                        Cadastrar e-mail
                    </button>
                </form>
                <p class="small mx-auto mb-0" style="max-width: 550px; font-size: 0.8rem; color: rgba(255,255,255,0.4);">
                    Ao cadastrar seu e-mail, você concorda em receber conteúdos institucionais da BSI Capital. O tratamento dos dados seguirá a <a href="#" style="color: rgba(255,255,255,0.6); text-decoration: underline; transition: color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.6)'">Política de Privacidade</a>.
                </p>
            </div>
        </div>

        <div class="mt-4 p-4 text-muted small" style="background: rgba(9,27,35,0.02); border-radius: 12px; border: 1px solid rgba(9,27,35,0.05);">
            <strong>Aviso Institucional:</strong> Os conteúdos da BSI Intelligence têm finalidade exclusivamente informativa e institucional. As informações não constituem recomendação de investimento, oferta pública, consultoria de valores mobiliários ou análise individualizada. Decisões de investimento devem considerar os documentos da operação, o perfil do investidor e a avaliação de assessores especializados.
        </div>
    </div>
</section>
@endsection

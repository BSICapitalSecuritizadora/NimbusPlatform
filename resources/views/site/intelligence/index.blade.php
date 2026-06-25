@extends('site.layout')
@section('title', 'BSI Intelligence - Relatórios e Análises de Mercado')

@section('content')
<section class="intelligence-hero position-relative overflow-hidden">
    <div class="container position-relative z-1">
        <div class="row">
            <div class="col-lg-8">
                <div class="intelligence-eyebrow fw-bold mb-3">Relatórios e análises de mercado</div>
                <h1 class="display-4 fw-bold intelligence-hero-title mb-4">BSI Intelligence</h1>
                <p class="lead intelligence-hero-text mb-0">
                    Conteúdos, relatórios e leituras de mercado sobre securitização, crédito estruturado, CRI, CRA, CR e temas regulatórios, produzidos para apoiar o acompanhamento técnico do mercado de capitais.
                </p>
            </div>
        </div>
    </div>
</section>

<div class="intelligence-page py-5">
    <div class="container py-4">
        {{-- Temas --}}
        <div class="mb-5 pb-3">
            <h2 class="h4 fw-bold mb-4" style="color: #091B23;">Temas acompanhados pela BSI Intelligence</h2>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Mercado de CRI</span>
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Mercado de CRA</span>
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Certificados de Recebíveis</span>
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Crédito estruturado</span>
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Agronegócio</span>
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Mercado imobiliário</span>
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Regulação CVM</span>
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Tendências de funding</span>
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Governança e transparência</span>
                <span class="badge px-3 py-2 rounded-pill intelligence-chip fw-medium">Dados de emissões</span>
            </div>
        </div>

        {{-- Filtros e Busca --}}
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-5 pb-4 border-bottom" style="border-color: rgba(9, 27, 35, 0.12) !important;">
            <div class="d-flex flex-wrap gap-2">
                <select class="form-select intelligence-filter rounded-pill px-4" style="width: auto;" disabled>
                    <option selected>Categoria</option>
                </select>
                <select class="form-select intelligence-filter rounded-pill px-4" style="width: auto;" disabled>
                    <option selected>Setor</option>
                </select>
                <select class="form-select intelligence-filter rounded-pill px-4" style="width: auto;" disabled>
                    <option selected>Tipo de conteúdo</option>
                </select>
            </div>
            <div class="position-relative" style="min-width: 280px;">
                <i class="bi bi-search position-absolute top-50 translate-middle-y" style="left: 1.25rem; color: rgba(9, 27, 35, 0.48);"></i>
                <input type="text" class="form-control intelligence-search-input rounded-pill w-100" placeholder="Buscar palavra-chave..." disabled style="padding-left: 2.75rem;">
            </div>
        </div>

        {{-- Grid de Cards --}}
        <div class="row g-4">
            {{-- Relatório Placeholder 1 --}}
            <div class="col-lg-6">
                <div class="intelligence-card d-flex flex-column flex-sm-row h-100 text-decoration-none">
                    <div class="col-sm-5 d-flex flex-column">
                        <div class="flex-grow-1 position-relative m-3 rounded-3 overflow-hidden" style="min-height: 220px; background: #E6E4E4;">
                            <img src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=600&auto=format&fit=crop" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" style="transition: transform 0.6s ease;" alt="Panorama Mercado" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                        </div>
                    </div>
                    <div class="col-sm-7 d-flex flex-column justify-content-between p-4 ps-sm-2">
                        <div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <span class="badge rounded-pill intelligence-badge-category">CRI / Imobiliário</span>
                                <span class="badge rounded-pill intelligence-badge-category">Panorama</span>
                            </div>
                            <h3 class="h5 intelligence-card-title mb-3">Panorama BSI: Mercado de CRI</h3>
                            <p class="intelligence-card-text mb-4" style="font-size: 0.95rem;">Leitura de mercado sobre o volume de emissões e a evolução das normas CVM aplicáveis ao setor imobiliário.</p>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <span class="intelligence-badge-status"><i class="bi bi-clock me-1"></i> Em breve</span>
                            </div>
                            <div class="intelligence-card-btn">Conteúdo em breve</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Relatório Placeholder 2 --}}
            <div class="col-lg-6">
                <div class="intelligence-card d-flex flex-column flex-sm-row h-100 text-decoration-none">
                    <div class="col-sm-5 d-flex flex-column">
                        <div class="flex-grow-1 position-relative m-3 rounded-3 overflow-hidden" style="min-height: 220px; background: #E6E4E4;">
                            <img src="https://images.unsplash.com/photo-1573164713988-8665fc963095?q=80&w=600&auto=format&fit=crop" class="position-absolute top-0 start-0 w-100 h-100 object-fit-cover" style="transition: transform 0.6s ease;" alt="Estudo de setor" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                        </div>
                    </div>
                    <div class="col-sm-7 d-flex flex-column justify-content-between p-4 ps-sm-2">
                        <div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <span class="badge rounded-pill intelligence-badge-category">CRA / Agronegócio</span>
                                <span class="badge rounded-pill intelligence-badge-category">Estudo Setorial</span>
                            </div>
                            <h3 class="h5 intelligence-card-title mb-3">Crédito para o Agronegócio: Dinâmica do CRA</h3>
                            <p class="intelligence-card-text mb-4" style="font-size: 0.95rem;">Acompanhamento sobre como as estruturas de securitização apoiam o financiamento de produtores rurais.</p>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <span class="intelligence-badge-status"><i class="bi bi-clock me-1"></i> Em breve</span>
                            </div>
                            <div class="intelligence-card-btn">Conteúdo em breve</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Newsletter Signup --}}
        <div class="mt-5 p-4 p-lg-5 text-center intelligence-newsletter">
            <span class="badge mb-3 px-3 py-2 rounded-pill intelligence-newsletter-eyebrow fw-medium" style="letter-spacing: 0.5px;">Newsletter</span>
            <h3 class="h3 fw-bold mb-3 intelligence-newsletter-title" style="letter-spacing: -0.5px;">Receba novos conteúdos da BSI Intelligence</h3>
            <p class="mb-4 mx-auto intelligence-newsletter-text" style="max-width: 600px; font-size: 1.05rem;">
                Acompanhe atualizações técnicas, resumos regulatórios e estudos setoriais para apoiar suas decisões e manter-se informado.
            </p>
            <form class="d-flex flex-column flex-sm-row gap-3 justify-content-center mx-auto mb-4" style="max-width: 550px;">
                <div class="position-relative flex-grow-1">
                    <input type="email" class="form-control form-control-lg intelligence-newsletter-input w-100" placeholder="Seu endereço de e-mail" required style="border-radius: 8px;">
                </div>
                <button type="button" class="btn btn-lg px-4 fw-semibold intelligence-newsletter-button" style="border-radius: 8px;">
                    Cadastrar e-mail
                </button>
            </form>
            <p class="small mx-auto mb-0" style="max-width: 550px; font-size: 0.85rem; color: rgba(230, 228, 228, 0.48);">
                Ao cadastrar seu e-mail, você concorda em receber conteúdos institucionais da BSI Capital. O tratamento dos dados seguirá a <a href="#" style="color: rgba(230, 228, 228, 0.72); text-decoration: underline; transition: color 0.2s;" onmouseover="this.style.color='#E6E4E4'" onmouseout="this.style.color='rgba(230, 228, 228, 0.72)'">Política de Privacidade</a>.
            </p>
        </div>

        {{-- Disclaimer --}}
        <div class="mt-5 p-4 small intelligence-disclaimer">
            <strong class="intelligence-disclaimer-title fw-bold">Aviso Institucional:</strong> Os conteúdos da BSI Intelligence têm finalidade exclusivamente informativa e institucional. As informações não constituem recomendação de investimento, oferta pública, consultoria de valores mobiliários ou análise individualizada. Decisões de investimento devem considerar os documentos da operação, o perfil do investidor e a avaliação de assessores especializados.
        </div>
    </div>
</div>

@push('head')
<style>
    .intelligence-page {
        background: #E6E4E4;
        color: #091B23;
    }
    .intelligence-hero {
        background: linear-gradient(135deg, #091B23 0%, #0B2029 55%, #091B23 100%);
        border-bottom: 1px solid rgba(160, 110, 40, 0.35);
        padding: 100px 0 60px;
    }
    .intelligence-eyebrow {
        color: #A06E28;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        font-size: 0.85rem;
    }
    .intelligence-hero-title {
        color: #E6E4E4;
    }
    .intelligence-hero-text {
        color: rgba(230, 228, 228, 0.72);
    }
    .intelligence-chip {
        background: rgba(255, 255, 255, 0.70);
        border: 1px solid rgba(9, 27, 35, 0.12);
        color: rgba(9, 27, 35, 0.78);
        transition: all 180ms ease;
        font-size: 0.9rem;
    }
    .intelligence-chip:hover,
    .intelligence-chip.is-active {
        background: rgba(160, 110, 40, 0.10) !important;
        border-color: rgba(160, 110, 40, 0.35) !important;
        color: #A06E28 !important;
    }
    .intelligence-filter {
        background: #FFFFFF;
        border: 1px solid rgba(9, 27, 35, 0.12);
        color: #091B23;
        font-size: 0.95rem;
    }
    .intelligence-filter:focus {
        border-color: #A06E28;
        box-shadow: 0 0 0 2px rgba(160, 110, 40, 0.2);
        outline: none;
    }
    .intelligence-search-input {
        border: 1px solid rgba(9, 27, 35, 0.12);
        color: #091B23;
        background: #FFFFFF;
        font-size: 0.95rem;
    }
    .intelligence-search-input::placeholder {
        color: rgba(9, 27, 35, 0.48);
    }
    .intelligence-search-input:focus {
        border-color: #A06E28;
        box-shadow: 0 0 0 2px rgba(160, 110, 40, 0.2);
        outline: none;
    }
    .intelligence-card {
        background: #FFFFFF;
        border: 1px solid rgba(9, 27, 35, 0.10);
        box-shadow: 0 16px 34px rgba(9, 27, 35, 0.06);
        border-radius: 16px;
        transition: border-color 180ms ease, transform 180ms ease, box-shadow 180ms ease;
        overflow: hidden;
    }
    .intelligence-card:hover {
        border-color: rgba(160, 110, 40, 0.40);
        transform: translateY(-2px);
        box-shadow: 0 20px 42px rgba(9, 27, 35, 0.09);
    }
    .intelligence-card-title {
        color: #091B23;
        font-weight: 700;
        line-height: 1.4;
    }
    .intelligence-card-text {
        color: rgba(9, 27, 35, 0.70);
    }
    .intelligence-badge-category {
        color: #A06E28;
        background: rgba(160, 110, 40, 0.08);
        border: 1px solid rgba(160, 110, 40, 0.22);
        font-weight: 600;
        padding: 6px 12px;
    }
    .intelligence-badge-status {
        background: rgba(160, 110, 40, 0.10);
        color: #A06E28;
        font-weight: 600;
        padding: 4px 12px;
        border-radius: 50rem;
        font-size: 0.85rem;
    }
    .intelligence-card-btn {
        border: 1px solid rgba(160, 110, 40, 0.35);
        color: #A06E28;
        border-radius: 50rem;
        padding: 8px 24px;
        font-weight: 600;
        font-size: 0.9rem;
        background: transparent;
        transition: all 180ms ease;
        display: inline-block;
        text-align: center;
        text-decoration: none;
    }
    .intelligence-card-btn:hover {
        background: #A06E28;
        color: #091B23;
    }
    .intelligence-newsletter {
        background: linear-gradient(135deg, #091B23 0%, #0B2029 100%);
        border: 1px solid rgba(160, 110, 40, 0.35);
        border-radius: 16px;
    }
    .intelligence-newsletter-eyebrow {
        color: #A06E28;
        background: rgba(160, 110, 40, 0.10);
        border: 1px solid rgba(160, 110, 40, 0.20);
    }
    .intelligence-newsletter-title {
        color: #E6E4E4;
    }
    .intelligence-newsletter-text {
        color: rgba(230, 228, 228, 0.72);
    }
    .intelligence-newsletter-input {
        background: rgba(230, 228, 228, 0.06);
        border: 1px solid rgba(230, 228, 228, 0.18);
        color: #E6E4E4;
    }
    .intelligence-newsletter-input::placeholder {
        color: rgba(230, 228, 228, 0.48);
    }
    .intelligence-newsletter-input:focus {
        border-color: #A06E28;
        box-shadow: 0 0 0 2px rgba(160, 110, 40, 0.2);
        outline: none;
        background: rgba(230, 228, 228, 0.08);
        color: #E6E4E4;
    }
    .intelligence-newsletter-button {
        background: #A06E28;
        color: #091B23;
        border: none;
        transition: all 180ms ease;
    }
    .intelligence-newsletter-button:hover {
        background: #E6E4E4;
        color: #091B23;
    }
    .intelligence-disclaimer {
        background: rgba(255, 255, 255, 0.72);
        border: 1px solid rgba(9, 27, 35, 0.10);
        color: rgba(9, 27, 35, 0.72);
        border-radius: 12px;
    }
    .intelligence-disclaimer-title {
        color: #091B23;
    }
</style>
@endpush
@endsection

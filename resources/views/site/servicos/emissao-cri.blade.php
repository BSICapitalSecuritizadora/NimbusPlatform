@extends('site.layout')

@section('title', 'Emissão e Coordenação de CRI | BSI Capital')

@section('uses_flux', '1')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/cri_real_estate2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Estruturação</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Emissão e <br><span style="color: var(--gold);">Coordenação de CRI</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Serviço completo de gestão, documentação e acompanhamento para que emissões imobiliárias acessem o mercado com rigor técnico e diligência.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.imobiliario.cri') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Conhecer a solução CRI
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/cri_real_estate.jpg') }}" class="img-fluid" alt="Emissão e Coordenação de CRI" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle text-primary" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Rastreabilidade</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Gestão Securitizadora</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Visão Geral -->
<section class="py-5" style="background-color: #ffffff;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Visão Geral do Serviço</span>
                <h2 class="h3 fw-bold text-dark mb-4">Governança técnica para sua operação imobiliária</h2>
                <p class="text-muted mb-4 lead">
                    O serviço de Emissão e Coordenação de CRI da BSI Capital é voltado para estruturadores e emissores que demandam uma securitizadora que entenda da essência imobiliária, garantindo agilidade sem abrir mão do controle de riscos.
                </p>
                <p class="text-muted mb-4">
                    Da formalização inicial dos Termos de Securitização (TS) à liquidação e acompanhamento periódico, nós gerenciamos as obrigações para que os direitos creditórios sejam transformados em CRI com total segurança fiduciária.
                </p>
            </div>
            <div class="col-lg-6">
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Conformidade Legal</h3>
                            <p class="small text-muted mb-0">Gestão e análise rigorosa de toda a documentação que compõe o lastro da operação.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Monitoramento Contínuo</h3>
                            <p class="small text-muted mb-0">Acompanhamento periódico da saúde da carteira de recebíveis e manutenção das garantias.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Público-Alvo Section -->
<section class="py-5 bg-light border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Público-Alvo</span>
            <h2 class="h3 fw-bold text-dark mb-3">A quem se destina este serviço</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Organizações que buscam estruturar ou que já originam operações imobiliárias e precisam de uma securitizadora de excelência.</p>
        </div>
        <div class="row g-4 justify-content-center">
            @foreach([
                ['Originadores e Gestoras', 'Que necessitam emitir CRIs para seus fundos (FIIs) ou distribuição a terceiros.'],
                ['Incorporadoras e Loteadoras', 'Para coordenação de securitização de suas carteiras performadas ou a performar.'],
                ['Detentores de Ativos BTS', 'Companhias com contratos de Built-to-Suit ou Sale-Leaseback.'],
                ['Assessorias Financeiras', 'Escritórios e estruturadores que buscam um parceiro tecnológico e seguro para emissão.']
            ] as $target)
            <div class="col-md-6 col-lg-3">
                <div class="d-flex flex-column p-4 rounded-4 h-100" style="background: #ffffff; border: 1px solid var(--border);">
                    <div class="bg-gold p-2 rounded-circle mb-3 align-self-start" style="width: 12px; height: 12px;"></div>
                    <h4 class="h6 fw-bold mb-2">{{ $target[0] }}</h4>
                    <p class="text-muted small mb-0">{{ $target[1] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

<!-- Etapas / Fluxo -->
<section class="py-5">
    <div class="container py-5">
        <div class="text-center mb-5">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Coordenação Fiduciária</span>
            <h2 class="h3 fw-bold text-dark">Como prestamos o serviço de Emissão</h2>
        </div>

        <div class="row g-0 position-relative">
            <div class="d-none d-lg-block position-absolute top-50 start-0 w-100 border-top border-2" style="border-color: rgba(212,175,55, 0.3) !important; z-index: 1;"></div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">1</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Engajamento</h3>
                <p class="small text-muted mb-0">Análise prévia da documentação e aderência regulatória dos recebíveis imobiliários.</p>
            </div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">2</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Estruturação Securitizadora</h3>
                <p class="small text-muted mb-0">Elaboração do Termo de Securitização e formalização das garantias associadas.</p>
            </div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">3</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Emissão e Liquidação</h3>
                <p class="small text-muted mb-0">Coordenação junto aos agentes depositários, liquidantes e registros da B3.</p>
            </div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">4</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Acompanhamento</h3>
                <p class="small text-muted mb-0">Gestão contínua, covenant tracking e relatórios no Portal do Investidor.</p>
            </div>
        </div>
    </div>
</section>

<!-- Diferenciais BSI Capital -->
<section class="py-5 bg-white border-top border-bottom">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diferenciais BSI</span>
                <h2 class="h3 fw-bold text-dark mb-4">Experiência imobiliária com tecnologia</h2>
                <p class="text-muted mb-4">
                    Nossa equipe combina décadas de atuação no setor imobiliário com uma plataforma tecnológica robusta, proporcionando controle absoluto sobre os fluxos operacionais sem sacrificar a agilidade da emissão.
                </p>
            </div>
            <div class="col-lg-7">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="d-flex gap-3">
                            <div class="text-gold flex-shrink-0">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold">Tecnologia</h4>
                                <p class="small text-muted">Acompanhamento e rastreabilidade documental em ambiente auditável.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex gap-3">
                            <div class="text-gold flex-shrink-0">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold">Rigor Fiduciário</h4>
                                <p class="small text-muted">Garantimos a manutenção fiel dos lastros e o reporte transparente para todos os investidores.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Final -->
<section class="py-5 position-relative" style="background: var(--brand-strong); overflow: hidden;">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('{{ asset('images/cri_real_estate.jpg') }}') center/cover;"></div>
    <div class="container py-5 position-relative z-1 text-center">
        <h2 class="h3 fw-bold text-white mb-3">Necessita emitir e coordenar um CRI?</h2>
        <p class="text-light mb-4 mx-auto" style="max-width: 600px;">
            Descubra as vantagens da nossa solução de securitização ou entre em contato com nossa equipe para emissão.
        </p>
        <div class="d-flex flex-wrap gap-3 justify-content-center">
            <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg px-4 py-3 shadow-lg" style="transition: all 0.3s ease;">
                Falar com a equipe
            </a>
            <a href="{{ route('site.imobiliario.cri') }}" class="btn btn-outline-light btn-lg px-4 py-3" style="transition: all 0.3s ease;">
                Ver Solução CRI
            </a>
        </div>
    </div>
</section>

@endsection

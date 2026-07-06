@extends('site.layout')

@section('title', 'Emissão e Coordenação de CRA | BSI Capital')

@section('uses_flux', '1')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/cra_agronegocio2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Estruturação</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Emissão e <br><span style="color: var(--gold);">Coordenação de CRA</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Securitização especializada para o agronegócio. Coordenamos toda a trilha de documentação, lastro e garantias respeitando o ciclo da safra e a dinâmica setorial.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.agronegocio.cra') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Conhecer a solução CRA
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/cra_agronegocio.jpg') }}" class="img-fluid" alt="Emissão e Coordenação de CRA" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle text-primary" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Conformidade</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Análise de Safra</div>
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
                <h2 class="h3 fw-bold text-dark mb-4">Securitização alinhada à realidade do campo</h2>
                <p class="text-muted mb-4 lead">
                    O serviço de Emissão e Coordenação de CRA exige uma securitizadora que entenda os tempos e movimentos do produtor rural, das cooperativas e da agroindústria.
                </p>
                <p class="text-muted mb-4">
                    Assumimos o papel fiduciário na conversão de recebíveis do agronegócio (como CPRs, Duplicatas e CDAs) em títulos acessíveis ao mercado de capitais. Nossa coordenação assegura aderência regulatória rigorosa junto às exigências específicas desse setor.
                </p>
            </div>
            <div class="col-lg-6">
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Modelagem Agrícola</h3>
                            <p class="small text-muted mb-0">Compreensão de fluxos atrelados a safras, insumos e sazonalidade climática.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Elegibilidade</h3>
                            <p class="small text-muted mb-0">Validação criteriosa de recebíveis aderentes às regulamentações do CRA.</p>
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
            <p class="text-muted mx-auto" style="max-width: 600px;">Organizações da cadeia agroindustrial que buscam estruturar operações de securitização confiáveis.</p>
        </div>
        <div class="row g-4 justify-content-center">
            @foreach([
                ['Cooperativas Agropecuárias', 'Que desejam financiar seus cooperados através do mercado de capitais.'],
                ['Agroindústrias', 'Buscando securitizar recebíveis de venda ou viabilizar operações de fomento.'],
                ['Fornecedores de Insumos', 'Revendas e distribuidoras que necessitam organizar e emitir CPRs em massa.'],
                ['Fundos Fiagro e Gestoras', 'Estruturadores que buscam agilidade e controle fiduciário para suas emissões.']
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
                <h3 class="h6 fw-bold mb-2">Diagnóstico de Lastro</h3>
                <p class="small text-muted mb-0">Análise dos direitos creditórios do agronegócio e validação do caráter agro da operação.</p>
            </div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">2</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Estruturação e CPRs</h3>
                <p class="small text-muted mb-0">Formalização do Termo de Securitização, emissão de CPRs financeiras ou físicas e registro.</p>
            </div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">3</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Liquidação da Emissão</h3>
                <p class="small text-muted mb-0">Coordenação entre distribuidores, liquidantes e a B3 para finalização da captação.</p>
            </div>

            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">4</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Monitoramento Periódico</h3>
                <p class="small text-muted mb-0">Acompanhamento contínuo da safra, garantias e reporte ao mercado.</p>
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
                <h2 class="h3 fw-bold text-dark mb-4">Especialistas na dinâmica do setor</h2>
                <p class="text-muted mb-4">
                    Nossa atuação respeita o fluxo de caixa do campo. Atuamos de forma independente para proteger o investidor sem engessar as operações comerciais das empresas e produtores do agronegócio.
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
                                <h4 class="h6 fw-bold">Gestão Documental</h4>
                                <p class="small text-muted">Controle eficiente de notas fiscais, CPRs, garantias pignoratícias e hipotecárias.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex gap-3">
                            <div class="text-gold flex-shrink-0">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold">Integração B3</h4>
                                <p class="small text-muted">Processo fluído para emissão eletrônica e conformidade com a regulamentação atual.</p>
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
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('{{ asset('images/cra_agronegocio.jpg') }}') center/cover;"></div>
    <div class="container py-5 position-relative z-1 text-center">
        <h2 class="h3 fw-bold text-white mb-3">Sua operação agro no mercado de capitais</h2>
        <p class="text-light mb-4 mx-auto" style="max-width: 600px;">
            Fale com nossos especialistas em securitização de CRA para coordenar a sua próxima emissão.
        </p>
        <div class="d-flex flex-wrap gap-3 justify-content-center">
            <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg px-4 py-3 shadow-lg" style="transition: all 0.3s ease;">
                Falar com a equipe
            </a>
            <a href="{{ route('site.agronegocio.cra') }}" class="btn btn-outline-light btn-lg px-4 py-3" style="transition: all 0.3s ease;">
                Ver Solução CRA
            </a>
        </div>
    </div>
</section>

@endsection

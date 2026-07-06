@extends('site.layout')

@section('title', 'Captação de Recursos | BSI Capital')

@section('uses_flux', '1')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/captacao-recursos.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>
    
    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Estruturação</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Captação de <br><span style="color: var(--gold);">Recursos</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Análise e coordenação da estrutura financeira adequada para acesso ao funding estratégico, alinhando as necessidades da sua empresa ao apetite do mercado.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Avaliar sua estrutura
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/captacao-recursos.jpg') }}" class="img-fluid" alt="Captação de Recursos" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle text-primary" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Soluções inteligentes</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Acesso ao Mercado</div>
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
                <h2 class="h3 fw-bold text-dark mb-4">A ponte entre o seu projeto e o mercado de capitais</h2>
                <p class="text-muted mb-4 lead">
                    Nossa atuação no serviço de captação consiste em diagnosticar a real necessidade financeira da sua operação e desenhar o caminho viável dentro das alternativas que oferecemos (CRI, CRA, Debêntures, Notas Comerciais).
                </p>
                <p class="text-muted mb-4">
                    Atuamos coordenando as partes para que a sua empresa atinja o grau de governança necessário, preparando a documentação e modelagem financeira para se conectar com os recursos de mercado de maneira transparente e prudente.
                </p>
            </div>
            <div class="col-lg-6">
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Preparação</h3>
                            <p class="small text-muted mb-0">Organização das informações, auditorias e garantias para suportar o rating da operação.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Coordenação</h3>
                            <p class="small text-muted mb-0">Gerenciamento ágil de todas as etapas, desde a due diligence até a liquidação.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Quando faz sentido -->
<section class="py-5 bg-light border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Cenários de Aplicação</span>
            <h2 class="h3 fw-bold text-dark mb-3">Quando a Captação Estruturada faz sentido</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossas soluções de captação de recursos apoiam empresas maduras com visão estratégica de longo prazo.</p>
        </div>
        <div class="row g-4 justify-content-center">
            @foreach([
                ['Reestruturação de Dívidas', 'Substituição de financiamentos caros de curto prazo por dívida alongada.'],
                ['Expansão e CAPEX', 'Investimento em novas instalações, plantas industriais ou expansão de landbank.'],
                ['Capital de Giro Estruturado', 'Antecipação de recebíveis já performados para acelerar a geração de caixa.'],
                ['Aquisições Estratégicas', 'Funding para fusões e aquisições alavancadas pela emissão corporativa.']
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
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Nosso Processo</span>
            <h2 class="h3 fw-bold text-dark">Como conduzimos a sua Captação</h2>
        </div>
        
        <div class="row g-0 position-relative">
            <div class="d-none d-lg-block position-absolute top-50 start-0 w-100 border-top border-2" style="border-color: rgba(212,175,55, 0.3) !important; z-index: 1;"></div>
            
            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">1</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Análise de Necessidade</h3>
                <p class="small text-muted mb-0">Entendimento do prazo e custo necessários para o projeto.</p>
            </div>
            
            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">2</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Definição do Instrumento</h3>
                <p class="small text-muted mb-0">Escolha da estrutura adequada (CRI, CRA, Debênture).</p>
            </div>
            
            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">3</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Adequação e Garantias</h3>
                <p class="small text-muted mb-0">Preparação das documentações, rating e garantias reais.</p>
            </div>
            
            <div class="col-lg-3 px-4 py-4 py-lg-0 text-center position-relative z-2">
                <div class="bg-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-4 border border-2 shadow-sm" style="width: 60px; height: 60px; border-color: var(--gold) !important; color: var(--brand);">
                    <span class="fs-5 fw-bold">4</span>
                </div>
                <h3 class="h6 fw-bold mb-2">Coordenação da Emissão</h3>
                <p class="small text-muted mb-0">Trabalho conjunto com estruturadores parceiros até a captação.</p>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios / Diferenciais -->
<section class="py-5 bg-white border-top border-bottom">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diferenciais BSI</span>
                <h2 class="h3 fw-bold text-dark mb-4">Acompanhamento end-to-end</h2>
                <p class="text-muted mb-4">
                    Não fazemos promessas irrealistas de condições financeiras. Nosso foco é organizar a sua empresa e a sua operação de forma robusta e auditável para que ela seja naturalmente atrativa ao mercado e passe por rigorosos crivos regulatórios.
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
                                <h4 class="h6 fw-bold">Organização Documental</h4>
                                <p class="small text-muted">Apoio na preparação de toda trilha exigida por investidores qualificados.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex gap-3">
                            <div class="text-gold flex-shrink-0">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold">Governança</h4>
                                <p class="small text-muted">Aprimoramento do monitoramento interno que perdurará por toda vida da operação.</p>
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
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('{{ asset('images/about_hero.jpg') }}') center/cover;"></div>
    <div class="container py-5 position-relative z-1 text-center">
        <h2 class="h3 fw-bold text-white mb-3">Busca captação estratégica?</h2>
        <p class="text-light mb-4 mx-auto" style="max-width: 600px;">
            Apresente sua necessidade para avaliarmos a viabilidade de uma operação estruturada.
        </p>
        <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
            Falar com a BSI Capital
        </a>
    </div>
</section>

@endsection

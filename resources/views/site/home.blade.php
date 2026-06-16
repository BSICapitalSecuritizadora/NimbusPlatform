@extends('site.layout')
@section('title', 'BSI Capital Securitizadora')

@section('content')
<section class="hero position-relative overflow-hidden">
    <video autoplay loop muted playsinline class="position-absolute w-100 h-100 object-fit-cover" style="top: 0; left: 0; z-index: 0; opacity: 0.18; pointer-events: none;">
        <source src="{{ asset('videos/logo-animacao-bsi.mp4') }}" type="video/mp4">
    </video>

    <div class="container py-4 position-relative">
        <div class="row align-items-center g-5">
            <div class="col-xl-7">
                <div class="kicker mb-3">Securitização • Mercado de Capitais • Crédito Estruturado</div>
                <h1 class="display-3 fw-bold mb-4">
                    A securitizadora que fica na operação do início ao fim.
                </h1>
                <p class="lead mb-4" style="max-width: 720px;">
                    Estruturação, emissão e gestão fiduciária de CRI, CRA e CR. Protegemos os interesses de investidores e emissores com controle tecnológico e diligência técnica, da originação até a liquidação final.
                </p>

                <div class="d-grid d-sm-flex gap-3 mb-4">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg px-5">Submeter Operação para Análise</a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-light btn-lg px-5">Ver Emissões</a>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Histórico</div>
                            <div class="hero-metric-value fw-bold text-white" style="font-size: 1.5rem">Desde 2009</div>
                            <div class="small text-white-50">Sólida trajetória no mercado de securitização e crédito estruturado.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Escala</div>
                            <div class="hero-metric-value fw-bold text-white" style="font-size: 1.5rem">R$ [X] Bilhões</div>
                            <div class="small text-white-50">Volume emitido e sob gestão em diversas classes de ativos.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Regulação</div>
                            <div class="hero-metric-value fw-bold text-white" style="font-size: 1.5rem">CVM</div>
                            <div class="small text-white-50">Companhia aberta registrada e aderente aos rigorosos padrões de compliance.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="surface-card-dark p-4 p-lg-5">
                    <div class="kicker mb-3">ATUAÇÃO PONTA A PONTA</div>
                    <h2 class="h3 fw-bold mb-3 text-white">Da estruturação à gestão: cobertura integral da operação</h2>
                    <p class="text-white-50 mb-4" style="text-align: justify;">
                        Atuamos desde a concepção jurídico-financeira até o acompanhamento pós-emissão, com processos definidos, documentação controlada e fluxo de informações estruturado entre emissores, investidores e partes envolvidas.
                    </p>

                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">1</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Estruturação e Modelagem</div>
                                <div class="small text-white-50" style="text-align: justify;">Desenho da tese, modelagem financeira, estruturação jurídico-regulatória e coordenação da oferta.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">2</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Monitoramento e Governança</div>
                                <div class="small text-white-50" style="text-align: justify;">Acompanhamento de covenants, garantias, indicadores da operação e eventos de crédito.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">3</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Transparência e Controle</div>
                                <div class="small text-white-50" style="text-align: justify;">Gestão de documentos, trilha de auditoria, relatórios e visibilidade para investidores.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- Trust Bar --}}
<section class="py-4 border-bottom border-top" style="background: var(--surface-light);">
    <div class="container">
        <div class="row align-items-center justify-content-between g-4">
            <div class="col-lg-3">
                <div class="section-kicker mb-0">Conectados ao mercado</div>
                <div class="small text-muted">Ecossistema de integração institucional</div>
            </div>
            <div class="col-lg-9">
                <div class="d-flex flex-wrap justify-content-center justify-content-lg-end align-items-center gap-4 gap-lg-5 opacity-50 grayscale">
                    {{-- Placeholders para logos de parceiros (Auditoria, Ratings, etc) --}}
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.1em;">Auditoria Big 4</div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.1em;">Agências de Rating</div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.1em;">Agentes Fiduciários</div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.1em;">Custodiantes</div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-4">
        <div class="row align-items-end g-4 mb-5">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Atuação setorial</div>
                <h2 class="display-6 fw-bold mb-3 text-brand">Estruturas alinhadas ao ativo, ao setor e ao fluxo da operação</h2>
                <p class="section-copy mb-0">
                    Cada setor possui dinâmica própria de geração de caixa, risco e lastro. A BSI Capital estrutura operações sob medida, considerando as características do ativo, a natureza do negócio e os requisitos de cada emissão.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="section-divider ms-lg-auto"></div>
            </div>
        </div>

        @php
            $industries = [
                ['Imobiliário', 'Operações de CRI e crédito imobiliário estruturado, desenvolvidas a partir de ativos, recebíveis e portfólios imobiliários, com controle documental, governança e monitoramento da carteira.', 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=800&auto=format&fit=crop', '/imobiliario/cri-real-estate'],
                ['Agronegócio', 'Operações de CRA e crédito estruturado para o agronegócio, alinhadas ao ciclo produtivo, às garantias e à dinâmica de geração de caixa do setor.', 'https://images.unsplash.com/photo-1500382017468-9049fed747ef?q=80&w=800&auto=format&fit=crop', '/agronegocio/cra'],
                ['Infra & Empresas', 'Operações de crédito corporativo estruturado, incluindo debêntures, notas comerciais e recebíveis empresariais, para apoiar expansão, capex e reorganização de passivos.', 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?q=80&w=800&auto=format&fit=crop', '/infra-empresas/cr-futuro'],
            ];
        @endphp

        <div class="row g-4">
            @foreach($industries as [$industryTitle, $desc, $img, $link])
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 overflow-hidden position-relative border-0 shadow-sm card-hover" style="min-height: 420px;">
                        <img src="{{ $img }}" class="position-absolute w-100 h-100 object-fit-cover" alt="{{ $industryTitle }}">
                        <div class="position-absolute w-100 h-100" style="background: linear-gradient(180deg, rgba(2, 9, 24, 0.05) 0%, rgba(0, 18, 51, 0.82) 74%, rgba(0, 18, 51, 0.96) 100%);"></div>
                        <div class="position-relative h-100 d-flex flex-column justify-content-end p-4 text-white">
                            <h3 class="h4 fw-bold mb-3 text-white">{{ $industryTitle }}</h3>
                            <p class="mb-4 text-white-50">{{ $desc }}</p>
                            <div>
                                <a href="{{ $link }}" class="btn btn-light px-4">Explorar Solução</a>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>




<section class="py-5 section-dark">
    <div class="container py-5">
        <div class="row align-items-end g-4 mb-5">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Governança em Execução</div>
                <h2 class="display-6 fw-bold mb-3">BSI Sentinel: Tecnologia e diligência aplicadas na prática</h2>
                <p class="text-muted mb-0">
                    Da estruturação ao monitoramento pós-emissão, atuamos com processos rigorosos e a plataforma <span class="text-brand-light">BSI Sentinel</span>, nossa tecnologia proprietária para garantir transparência, rastreabilidade e segurança operacional.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="section-divider ms-lg-auto"></div>
            </div>
        </div>

        @php
            $cases = [
                [
                    'title' => 'Estruturação de CRI com Gestão Integral de Lastro e Reporting',
                    'desc' => 'Modelagem técnica integral, coordenação jurídica e financeira, controle de lastro e monitoramento ativo via BSI Sentinel até o vencimento final.',
                    'img' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=1000&auto=format&fit=crop',
                    'slug' => 'estruturacao-cri',
                ],
                [
                    'title' => 'Monitoramento Ativo e Governança Digital de Ativos',
                    'desc' => 'Ecosistema digital BSI Sentinel com acessos dedicados para emissores e investidores, integrando custódia de documentos e trilha de auditoria fiduciária.',
                    'img' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?q=80&w=1000&auto=format&fit=crop',
                    'slug' => 'gestao-de-documentos',
                ],
            ];
        @endphp

        <div class="row g-4">
            @foreach($cases as $case)
                <div class="col-lg-6">
                    <div class="card h-100 border-0 overflow-hidden">
                        <div class="row g-0 h-100">
                            <div class="col-md-5">
                                <img src="{{ $case['img'] }}" class="w-100 h-100 object-fit-cover" alt="{{ $case['title'] }}" style="min-height: 280px;">
                            </div>
                            <div class="col-md-7">
                                <div class="p-4 p-lg-5 d-flex flex-column h-100">
                                    <div class="section-kicker mb-2">Estudo de Caso</div>
                                    <h3 class="h3 fw-bold mb-3" style="font-size: 1.4rem;">{{ $case['title'] }}</h3>
                                    <p class="text-muted mb-4">{{ $case['desc'] }}</p>
                                    <div class="mt-auto">
                                        <a href="{{ route('site.cases.show', $case['slug']) }}" class="btn btn-outline-gold px-4">Analisar Caso</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="py-4" style="background: color-mix(in srgb, var(--surface) 94%, var(--brand) 6%); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Nossa missão</div>
                <p class="fw-semibold mb-3" style="font-size: 1.05rem; color: var(--text); line-height: 1.6;">
                    Viabilizar o acesso ao mercado de capitais por meio de estruturas de crédito robustas, atuando como o elo de segurança e governança entre quem produz e quem investe.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="result-chip">Governança corporativa</span>
                    <span class="result-chip">Transparência absoluta</span>
                    <span class="result-chip">Diligência fiduciária</span>
                    <span class="result-chip">Parcerias sólidas</span>
                </div>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="{{ route('site.about') }}" class="btn btn-outline-brand px-4">Conheça a BSI</a>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="card border-0 overflow-hidden" style="background: linear-gradient(135deg, var(--brand-strong), #0b1f4f);">
            <div class="row g-0 align-items-center">
                <div class="col-lg-8">
                    <div class="p-4 p-lg-5">
                        <div class="section-kicker mb-2">Relacionamento institucional</div>
                        <h2 class="h2 fw-bold text-white mb-3">Entre em contato com a BSI Capital</h2>
                        <p class="text-white-50 mb-0" style="max-width: 640px;">
                            Traga sua operação para a BSI Capital. Converse diretamente com nossos especialistas em estruturação e gestão de risco.
                        </p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-4 p-lg-5 d-flex flex-column gap-3 align-items-start align-items-lg-stretch">
                        <a href="{{ route('proposal.create') }}" class="btn btn-light btn-lg">Solicitar Análise de Estruturação</a>
                        <a href="{{ route('site.contact') }}" class="text-white-50 text-decoration-none small" style="padding: 0.25rem 0;">
                            ou avaliar viabilidade →
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

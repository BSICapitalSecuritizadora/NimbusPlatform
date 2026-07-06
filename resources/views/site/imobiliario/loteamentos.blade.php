@extends('site.layout')

@section('title', 'Loteamentos — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/loteamento2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Imobiliário</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Securitização para <br><span style="color: var(--gold);">Loteamentos e Expansão Urbana</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Estruturamos operações para loteadoras e urbanizadoras que buscam transformar recebíveis imobiliários em funding estratégico, com governança, controle documental e monitoramento ativo do lastro.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Apresentar projeto de loteamento
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}?type=CRI" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/loteamento.jpg') }}" class="img-fluid" alt="Loteamentos" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Financiamento de obra</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Infraestrutura Urbana</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@include('site.partials.imobiliario-stats')

<!-- Público-Alvo Section -->
<section class="py-5" style="background-color: #ffffff;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Público-Alvo</span>
                <h2 class="h3 fw-bold text-dark mb-4">Para quem a estrutura é indicada</h2>
                <p class="text-muted mb-4 lead">
                    A securitização para loteamentos é o veículo ideal para empresas que precisam destravar valor retido em recebíveis de longo prazo para acelerar sua expansão urbana.
                </p>
                <div class="d-flex flex-column gap-3">
                    @foreach([
                        'Loteadoras e Urbanizadoras buscando escalar lançamentos.',
                        'Incorporadoras com projetos horizontais de alto padrão.',
                        'Empresas com carteira madura de recebíveis de lotes performados.',
                        'Originadores de recebíveis imobiliários em polos de crescimento urbano.',
                        'Estruturadores e parceiros do mercado de capitais.',
                        'Empresas que buscam funding estruturado independente de crédito bancário.'
                    ] as $item)
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-gold p-1 rounded-circle" style="width: 8px; height: 8px;"></div>
                            <span class="text-dark fw-medium">{{ $item }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="col-lg-7">
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Funding para Expansão</h3>
                            <p class="small text-muted mb-0">Transforme recebíveis futuros em caixa imediato, viabilizando novos projetos sem comprometer seu equity ou depender do sistema bancário tradicional.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Gestão Documental</h3>
                            <p class="small text-muted mb-0">Organizamos o lastro e o controle documental dos contratos, trazendo governança e segurança para carteiras com centenas ou milhares de mutuários.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Soluções para cada dimensão da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Modelos financeiros desenhados com total aderência ao cronograma físico-financeiro, respeitando a dinâmica comercial e a curva de garantias de cada empreendimento.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Liquidez e Monetização</h3>
                    <p class="text-muted mb-0">Superamos o longo ciclo de recebimento dos contratos antecipando fluxos de vendas, o que otimiza o capital de giro e reduz a dependência de crédito bancário tradicional.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Infraestrutura e Obras</h3>
                    <p class="text-muted mb-0">Resolvemos a necessidade de capital intensivo inicial captando recursos alinhados à evolução física do projeto, garantindo fluxo de caixa para as metas de infraestrutura.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Organização do Lastro</h3>
                    <p class="text-muted mb-0">Transformamos carteiras pulverizadas e complexas em estruturas organizadas com alienação fiduciária e garantias reais, trazendo segurança institucional à operação.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Monitoramento durante o ciclo da obra -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Acompanhamento Ativo Durante o Ciclo da Obra</h2>
                <p class="text-muted mb-4 lead">
                    Em loteamentos, o lastro evolui conforme novas unidades são vendidas e a infraestrutura avança. Monitoramos continuamente esse processo, garantindo que investidores e agente fiduciário tenham visibilidade total em cada etapa.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Verificação contínua do lastro: contratos de PCV performados e carteira em comercialização.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acompanhamento de marcos construtivos com liberação de recursos vinculada ao avanço físico via drone e medição independente.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Relatórios periódicos para investidores e agente fiduciário com rastreabilidade documental completa.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/estruturacao_projetos.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); filter: grayscale(20%);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Gestão do Pulverizado e Inteligência Tecnológica -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <div class="pe-lg-5">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Gestão Pulverizada</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Inteligência na Gestão de Recebíveis</h2>
                    <p class="text-muted mb-4 lead">
                        O maior desafio de um CRI de loteamento é a gestão da carteira pulverizada. A BSI Capital utiliza tecnologia proprietária para garantir a integridade do fluxo de caixa.
                    </p>
                    <div class="row g-4 mb-4">
                        <div class="col-sm-6">
                            <h4 class="h6 fw-bold text-brand mb-2">Monitoramento de Inadimplência</h4>
                            <p class="small text-muted mb-0">Alertas automáticos e dashboards em acompanhamento contínuo para controle da saúde da carteira.</p>
                        </div>
                        <div class="col-sm-6">
                            <h4 class="h6 fw-bold text-brand mb-2">Conta Centralizadora</h4>
                            <p class="small text-muted mb-0">Segregação total dos fluxos, garantindo que o repasse aos investidores seja a prioridade institucional.</p>
                        </div>
                    </div>
                    <p class="text-muted small">Mesmo em cenários de inadimplência pontual, nossa estrutura de **Série Subordinada** e **Fundo de Reserva** garante a continuidade dos pagamentos sem interrupções.</p>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-4 p-lg-5 rounded-4 border bg-white shadow-lg">
                    <h3 class="h5 fw-bold text-dark mb-4 text-center">Presença em todo o território nacional</h3>
                    <p class="text-muted text-center mb-5">Atuamos com especialistas locais em polos de desenvolvimento urbano de Norte a Sul do Brasil.</p>

                    <div class="row g-3 text-center">
                        <div class="col-4">
                            <div class="fw-bold h4 mb-0 text-brand">7+</div>
                            <div class="small text-muted">Estados Atendidos</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold h4 mb-0 text-brand">R$ 700Mi+</div>
                            <div class="small text-muted">VGV Estruturado</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold h4 mb-0 text-brand">100+</div>
                            <div class="small text-muted">Loteamentos</div>
                        </div>
                    </div>

                    <hr class="my-4" style="opacity: 0.1;">

                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        @foreach(['AL', 'MT', 'MG', 'PE', 'RJ', 'SP', 'TO'] as $uf)
                            <span class="badge bg-light text-muted border px-3 py-2">{{ $uf }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Guia Rápido de Securitização (Fluxo) -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Ciclo da Operação Estruturada</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossa jornada de estruturação conecta o loteador ao mercado de capitais de forma ágil e segura.</p>
        </div>

        <div class="row g-4 flow-container position-relative">
            <!-- Step 1 -->
            <div class="col-md-2 col-sm-4 col-6 flow-item">
                <div class="p-3 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm d-flex flex-column align-items-center justify-content-center">
                    <div class="mb-2 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 40px; height: 40px; background: var(--brand-strong) !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <div class="small fw-bold text-uppercase" style="color: var(--brand-strong);">Originação</div>
                </div>
            </div>
            <!-- Step 2 -->
            <div class="col-md-2 col-sm-4 col-6 flow-item">
                <div class="p-3 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm d-flex flex-column align-items-center justify-content-center">
                    <div class="mb-2 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 40px; height: 40px; background: var(--brand-strong) !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <div class="small fw-bold text-uppercase" style="color: var(--brand-strong);">Estruturação</div>
                </div>
            </div>
            <!-- Step 3 -->
            <div class="col-md-2 col-sm-4 col-6 flow-item">
                <div class="p-3 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm d-flex flex-column align-items-center justify-content-center">
                    <div class="mb-2 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 40px; height: 40px; background: var(--brand-strong) !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1m4 0h1m-5 4h1m4 0h1M9 16h1m4 0h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path></svg>
                    </div>
                    <div class="small fw-bold text-uppercase" style="color: var(--brand-strong);">Emissão</div>
                </div>
            </div>
            <!-- Step 4 -->
            <div class="col-md-2 col-sm-4 col-6 flow-item">
                <div class="p-3 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm d-flex flex-column align-items-center justify-content-center">
                    <div class="mb-2 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 40px; height: 40px; background: var(--brand-strong) !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <div class="small fw-bold text-uppercase" style="color: var(--brand-strong);">Captação</div>
                </div>
            </div>
            <!-- Step 5 -->
            <div class="col-md-2 col-sm-4 col-6 flow-item">
                <div class="p-3 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm d-flex flex-column align-items-center justify-content-center">
                    <div class="mb-2 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 40px; height: 40px; background: var(--brand-strong) !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    </div>
                    <div class="small fw-bold text-uppercase" style="color: var(--brand-strong);">Monitoramento</div>
                </div>
            </div>
            <!-- Step 6 -->
            <div class="col-md-2 col-sm-4 col-6 flow-item">
                <div class="p-3 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm d-flex flex-column align-items-center justify-content-center">
                    <div class="mb-2 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 40px; height: 40px; background: var(--brand-strong) !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <div class="small fw-bold text-uppercase" style="color: var(--brand-strong);">Pagamento</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Mecânica de Securitização para Loteadores -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Estrutura de Garantias</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Entenda como estruturamos a operação para maximizar sua liquidez mantendo a segurança institucional e o controle do lastro.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0">
                    <div class="text-brand fw-bold mb-3 fs-4">01.</div>
                    <h4 class="h5 fw-bold mb-3">Série Sênior e Subordinada</h4>
                    <p class="small text-muted mb-0">Dividimos o CRI em séries. A Sênior é destinada ao mercado, enquanto a Subordinada (retida pelo loteador) funciona como um colchão de garantia, absorvendo as primeiras inadimplências e alinhando interesses institucionais.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0">
                    <div class="text-brand fw-bold mb-3 fs-4">02.</div>
                    <h4 class="h5 fw-bold mb-3">Fundo de Reserva (PMT)</h4>
                    <p class="small text-muted mb-0">Estruturamos fundos que retêm frações do serviço da dívida. Isso garante que, mesmo em meses de sazonalidade nas vendas, o pagamento ao investidor ocorra sem sobressaltos, protegendo o fluxo estruturado.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="h-100 p-4 bg-white rounded-4 shadow-sm border-0">
                    <div class="text-brand fw-bold mb-3 fs-4">03.</div>
                    <h4 class="h5 fw-bold mb-3">Alienação Fiduciária</h4>
                    <p class="small text-muted mb-0">O lastro é composto pela cessão dos recebíveis imobiliários e, em muitos casos, pela alienação fiduciária do próprio terreno. Isso confere ao título robustez e alinhamento com as melhores práticas de governança.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="py-5 bg-white">
    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-4">
                <div class="pe-lg-4">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Ciclo de Capital</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Aspectos Estratégicos da Securitização para Loteamentos</h2>
                    <p class="text-muted mb-4">Esclarecemos os pontos críticos para quem busca acelerar o desenvolvimento de loteamentos via mercado de capitais.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2 shadow-sm">Solicitar análise de viabilidade</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqLoteamento">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. Posso securitizar lotes ainda não vendidos (Unperformed)?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqLoteamento">
                            <div class="accordion-body px-0 text-muted">
                                Sim. Chamamos essa operação de CRI de Desenvolvimento. O recurso é captado para custear as obras de infraestrutura e a liberação é feita mediante medição de obra por empresa independente. O lastro inicial é a própria área, que vai sendo substituído pelos contratos de venda conforme o projeto avança.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Qual o volume mínimo de carteira para viabilizar um CRI?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqLoteamento">
                            <div class="accordion-body px-0 text-muted">
                                Geralmente, operações de securitização tornam-se eficientes a partir de R$ 10 milhões em VGV (Valor Geral de Vendas) para emissão.Volumes menores podem ser avaliados dependendo da praça e do estágio de maturação do loteamento.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Qual a diferença entre Loteamento Aberto e Fechado para a BSI?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqLoteamento">
                            <div class="accordion-body px-0 text-muted">
                                Loteamentos fechados (acesso controlado) costumam ter taxas de juros mais atrativas no mercado devido à menor inadimplência histórica e melhor conservação das áreas comuns. No entanto, estruturamos ambos os modelos, aplicando critérios de monitoramento de lastro específicos para cada realidade urbana.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. Como funciona o repasse das parcelas pagas pelos clientes?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqLoteamento">
                            <div class="accordion-body px-0 text-muted">
                                Os pagamentos dos clientes são direcionados para uma conta centralizadora controlada pela securitizadora. Nós processamos o pagamento dos juros e amortização aos investidores do CRI e o excedente (spread do loteador) é liberado para a conta da loteadora mensalmente.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Outros segmentos imobiliários -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Outros Segmentos do Imobiliário</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Atuamos em diferentes frentes do mercado imobiliário com estruturas adaptadas à natureza de cada ativo.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.imobiliario.cri') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1m4 0h1m-5 4h1m4 0h1M9 16h1m4 0h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">CRI e Real Estate</h3>
                    <p class="text-muted mb-3">Estruturação e gestão de CRI com segurança jurídica, monitoramento rigoroso do lastro e governança ativa da carteira.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Solicitar análise de estruturação →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.imobiliario.incorporacao') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Incorporação Imobiliária</h3>
                    <p class="text-muted mb-3">Estruturação de CRI lastreados em créditos imobiliários oriundos de projetos de incorporação residencial e comercial.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Submeter projeto para avaliação →</span>
                </a>
            </div>
        </div>
    </div>
</section>

@push('head')
<style>
    @keyframes float {
        0% { transform: translateY(0px); }
        50% { transform: translateY(-10px); }
        100% { transform: translateY(0px); }
    }

    .custom-accordion .accordion-button:not(.collapsed) {
        box-shadow: none;
        color: var(--brand);
    }

    .custom-accordion .accordion-button:focus {
        box-shadow: none;
    }

    .custom-accordion .accordion-button::after {
        background-size: 1rem;
    }

    .card-hover:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important;
    }
</style>
@endpush
@endsection

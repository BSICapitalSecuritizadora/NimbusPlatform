@extends('site.layout')

@section('title', 'CRI e Real Estate — BSI Capital')

@section('uses_flux', '1')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/cri_real_estate2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Imobiliário</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Securitização de Recebíveis <span style="color: var(--gold);">Imobiliários (CRI)</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Transformamos ativos imobiliários em liquidez estratégica através de estruturas de CRI sob medida, com governança ativa e monitoramento rigoroso do lastro.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Apresentar Operação Imobiliária
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}?type=CRI" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <!-- Image Card -->
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/cri_real_estate.jpg') }}" class="img-fluid" alt="CRI Real Estate" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <!-- Floating Data Box -->
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle text-primary" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Crédito estruturado</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Execução com controle</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@include('site.partials.imobiliario-stats')

<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row justify-content-center mb-5">
            <div class="col-lg-8 text-center">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Análise Preliminar</span>
                <h2 class="h3 fw-bold text-dark mb-3">Simule a viabilidade da sua operação</h2>
                <p class="text-muted mb-0">Use os parâmetros iniciais de prazo, indexador e taxa alvo para estimar o potencial de captação do projeto.</p>
            </div>
        </div>

        <livewire:imobiliario.viability-simulator />
    </div>
</section>

<!-- Público-Alvo Section -->
<section class="py-5" style="background-color: #ffffff;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Público-Alvo</span>
                <h2 class="h3 fw-bold text-dark mb-4">Para quem é o Certificado de Recebíveis Imobiliários?</h2>
                <p class="text-muted mb-4 lead">
                    O CRI é o instrumento ideal para empresas que buscam funding estruturado no mercado de capitais, permitindo antecipar recebíveis e otimizar o capital de giro.
                </p>
                <div class="d-flex flex-column gap-3">
                    @foreach([
                        'Incorporadoras e Loteadoras buscando financiamento de obra ou liquidez de estoque.',
                        'Detentores de contratos de aluguel atípicos (Built-to-Suit ou Sale-Leaseback).',
                        'Empresas com fluxos de recebíveis imobiliários pulverizados ou concentrados.',
                        'Shopping centers e empreendimentos comerciais para expansão ou retrofit.',
                        'Originadores e estruturadores que necessitam de uma securitizadora sólida.',
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
                            <h3 class="h6 fw-bold text-brand mb-3">Funding Estruturado</h3>
                            <p class="small text-muted mb-0">Substitua dívidas bancárias caras por emissões no mercado de capitais com taxas competitivas e prazos adequados.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Antecipação de Recebíveis</h3>
                            <p class="small text-muted mb-0">Transforme sua carteira de vendas a prazo em caixa imediato para novos lançamentos e investimentos.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Eficiência Tributária</h3>
                            <p class="small text-muted mb-0">Aproveite a isenção de IR para investidores pessoa física, tornando a captação via CRI altamente atrativa.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Governança e Solidez</h3>
                            <p class="small text-muted mb-0">Estruturas com Patrimônio Separado e monitoramento ativo, garantindo segurança jurídica institucional.</p>
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
            <h2 class="h3 fw-bold text-dark mb-3">Inteligência técnica em cada fase da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Estruturamos e gerimos o CRI com governança ativa, documentação controlada e fluxo informacional contínuo entre todas as partes.</p>
        </div>

        <div class="row g-4">
            <!-- Diferencial 1 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Segurança Jurídica e Colaterais</h3>
                    <p class="text-muted mb-0">Rigor na formalização de garantias reais e cessão fiduciária, desenhando veículos de securitização com conformidade normativa institucional.</p>
                </div>
            </div>

            <!-- Diferencial 2 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Monitoramento e Diligência</h3>
                    <p class="text-muted mb-0">Diligência contínua do lastro e dos covenants financeiros, com prontidão no reporte de eventos de crédito ao mercado.</p>
                </div>
            </div>

            <!-- Diferencial 3 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Engenharia Financeira</h3>
                    <p class="text-muted mb-0">Modelagem de fluxos de caixa complexos com indexadores moldados à natureza do ativo e ao perfil estratégico da carteira.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Process Flow Section -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Como funciona o fluxo de Securitização</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Uma jornada estruturada para transformar ativos imobiliários em liquidez imediata com total segurança jurídica.</p>
        </div>

        <div class="row g-4 flow-container position-relative">
            <!-- Step 1 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Originação</h4>
                    <p class="small text-muted mb-0">Emissor imobiliário busca antecipação de recebíveis ou funding estruturado.</p>
                </div>
            </div>
            <!-- Step 2 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Estruturação</h4>
                    <p class="small text-muted mb-0">Securitizadora empacota a operação, registra na CVM e formaliza garantias.</p>
                </div>
            </div>
            <!-- Step 3 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Distribuição</h4>
                    <p class="small text-muted mb-0">Emissão do CRI e captação de recursos junto a investidores no mercado de capitais.</p>
                </div>
            </div>
            <!-- Step 4 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Monitoramento</h4>
                    <p class="small text-muted mb-0">Controle contínuo do lastro, garantias e repasse de proventos aos investidores.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Emissões em destaque -->
@if(isset($featuredEmissions) && $featuredEmissions->isNotEmpty())
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-5">
            <div>
                <h2 class="h3 fw-bold text-dark mb-2">Emissões de CRI Estruturadas</h2>
                <p class="text-muted mb-0">Operações públicas disponíveis para consulta técnica detalhada.</p>
            </div>
            <a href="{{ route('site.emissions') }}?type=CRI" class="btn btn-outline-brand btn-sm px-4 flex-shrink-0">Ver todas as emissões</a>
        </div>

        <div class="row g-4">
            @foreach($featuredEmissions as $e)
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 border-0 shadow-sm emission-card overflow-hidden">
                        <div style="height: 4px; background: linear-gradient(90deg, var(--brand), var(--gold), var(--brand));"></div>
                        <div class="card-body p-3 p-lg-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div class="flex-grow-1">
                                    <div class="small text-uppercase text-muted fw-semibold mb-2">{{ $e->if_code ?? 'CRI' }}</div>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        @if($e->type)
                                            <span class="badge badge-type-{{ strtolower($e->type) }} px-3 py-2">{{ $e->type }}</span>
                                        @endif
                                        @if($e->status_label)
                                            <span class="badge badge-status-{{ $e->status }} px-3 py-2">{{ $e->status_label }}</span>
                                        @endif
                                    </div>
                                    <h3 class="h5 fw-bold text-brand mb-0" style="line-height: 1.45; word-wrap: break-word;">{{ $e->name }}</h3>
                                </div>
                                <div class="d-flex align-items-center justify-content-center flex-shrink-0 p-2" style="width: 64px; height: 64px; border-radius: 14px; background: rgba(0,32,91,0.06); color: var(--brand);">
                                    @if($e->logo_path)
                                        <img src="{{ Storage::disk($e->logo_storage_disk)->url($e->logo_path) }}" alt="{{ $e->name }}" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                                    @else
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M9 8h1m4 0h1m-5 4h1m4 0h1M9 16h1m4 0h1M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path></svg>
                                    @endif
                                </div>
                            </div>

                            <div class="row g-2 small mb-3">
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Emissão</div>
                                    <div class="fw-semibold">{{ $e->emission_number ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Série</div>
                                    <div class="fw-semibold">{{ $e->series ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Data de emissão</div>
                                    <div class="fw-semibold">{{ optional($e->issue_date)->format('d/m/Y') ?? '—' }}</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Vencimento</div>
                                    <div class="fw-semibold">{{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}</div>
                                </div>
                                <div class="col-12">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Remuneração</div>
                                    <div class="fw-semibold">{{ $e->formatted_remuneration ?? '—' }}</div>
                                </div>
                                <div class="col-12">
                                    <div class="text-uppercase text-muted fw-semibold mb-1">Emissor</div>
                                    <div class="fw-semibold">{{ $e->issuer ?? '—' }}</div>
                                </div>
                            </div>

                            @if($e->documents->isNotEmpty())
                            <div class="border-top pt-3">
                                <div class="text-uppercase text-muted fw-bold mb-2" style="font-size: 0.65rem; letter-spacing: 0.05em;">Acesso Rápido aos Documentos</div>
                                <div class="d-flex flex-column gap-2">
                                    @php
                                        $termo = $e->documents->first(fn($doc) => str_contains(strtolower($doc->title), 'termo') || $doc->category === 'documentos_operacao');
                                        $relatorio = $e->documents->first(fn($doc) => str_contains(strtolower($doc->title), 'relatório') || str_contains(strtolower($doc->title), 'mensal'));
                                    @endphp

                                    @if($termo)
                                        <a href="{{ route('site.documents.download', $termo->id) }}" class="d-flex align-items-center gap-2 text-decoration-none py-1" style="color: var(--brand); font-size: 0.85rem;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                            <span class="fw-medium text-truncate">Termo de Securitização</span>
                                        </a>
                                    @endif

                                    @if($relatorio)
                                        <a href="{{ route('site.documents.download', $relatorio->id) }}" class="d-flex align-items-center gap-2 text-decoration-none py-1" style="color: var(--brand); font-size: 0.85rem;">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                            <span class="fw-medium text-truncate">Último Relatório de Monitoramento</span>
                                        </a>
                                    @endif
                                </div>
                            </div>
                            @endif
                        </div>
                        <div class="card-footer bg-transparent border-0 p-3 p-lg-4 pt-0 d-grid">
                            <a href="{{ route('site.emissions.show', $e->if_code) }}" class="btn btn-outline-brand btn-sm w-100">Consultar Operação Completa</a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

<!-- Additional Context or Stats -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <div class="mb-4">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Tecnologia & Transparência</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Gestão Digital e Transparência Pós-Fechamento</h2>
                </div>
                <p class="text-muted mb-4 lead">
                    Asseguramos a perenidade da operação através de uma gestão ativa apoiada por tecnologia. Nossa plataforma integra o controle de fluxos de caixa e o monitoramento em acompanhamento contínuo.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-5">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Relatórios granulares e automáticos de desempenho da carteira.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento contínuo de lastro e covenants com alertas preventivos.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acesso dedicado via <strong>Portal do Investidor</strong> com trilha de auditoria completa.</span>
                    </li>
                </ul>
                <div class="d-flex gap-3">
                    <a href="{{ route('investor.login') }}" class="btn btn-brand px-4 py-2">Acessar Portal</a>
                </div>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div class="position-relative">
                    <div style="background: url('{{ asset('images/gestao.jpg') }}') center/cover; height: 450px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); filter: grayscale(20%); mix-blend-mode: multiply;"></div>
                    <div class="position-absolute bg-white p-4 rounded-4 shadow-lg border" style="bottom: 30px; right: -20px; max-width: 280px;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="bg-success bg-opacity-10 p-2 rounded-circle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline><polyline points="16 7 22 7 22 13"></polyline></svg>
                            </div>
                            <div class="fw-bold small">Monitoramento Ativo</div>
                        </div>
                        <div class="text-muted smaller">Covenants financeiros e performance de lastro atualizados diariamente em nossa plataforma.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- FAQ Section -->
<section class="py-5 bg-light">
    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-4">
                <div class="pe-lg-4">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Dúvidas Estratégicas</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Aspectos Estratégicos da Securitização</h2>
                    <p class="text-muted mb-4">Entenda os fundamentos técnicos da securitização e como estruturamos operações de alta complexidade.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Consultar especialista em estruturação</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqCRI">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. O que é um CRI e qual sua finalidade?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqCRI">
                            <div class="accordion-body px-0 text-muted">
                                O Certificado de Recebíveis Imobiliários (CRI) é um título de renda fixa que representa a promessa de pagamento de créditos imobiliários. Sua finalidade é antecipar o fluxo de caixa para empresas do setor (incorporadoras, loteadoras), transformando recebíveis de longo prazo em capital imediato para novos investimentos.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. Quais são as principais vantagens para o Emissor?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqCRI">
                            <div class="accordion-body px-0 text-muted">
                                A securitização oferece taxas competitivas frente ao crédito bancário tradicional, prazos alongados e a possibilidade de desalavancagem do balanço. Além disso, permite a estruturação "sob medida", adequando o fluxo de pagamento do CRI ao cronograma de recebimento dos ativos imobiliários.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Como é apoiada a segurança do lastro na BSI Capital?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqCRI">
                            <div class="accordion-body px-0 text-muted">
                                Através de um rigoroso processo de auditoria e governança ativa. Utilizamos cessão fiduciária de direitos creditórios, alienação fiduciária de imóveis e monitoramento contínuo via Portal do Investidor, garantindo que os covenants financeiros e a integridade das garantias sejam preservados durante toda a vida da operação.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. O que é o Patrimônio Separado em um CRI?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqCRI">
                            <div class="accordion-body px-0 text-muted">
                                É um mecanismo legal que isola os ativos que lastreiam o CRI do patrimônio da securitizadora. Isso significa que os recebíveis e garantias da operação não respondem por dívidas da BSI Capital, conferindo segurança jurídica institucional para os investidores em caso de qualquer eventualidade com a instituição.
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
                <a href="{{ route('site.imobiliario.incorporacao') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Incorporação Imobiliária</h3>
                    <p class="text-muted mb-3">Estruturação de CRI lastreados em créditos imobiliários oriundos de projetos de incorporação residencial e comercial.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Submeter projeto para avaliação →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.imobiliario.loteamentos') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Loteamentos</h3>
                    <p class="text-muted mb-3">Securitização de recebíveis de loteamentos urbanos e fechados com lastro em contratos de promessa de compra e venda.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Apresentar projeto de loteamento →</span>
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

    .emission-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .emission-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important;
    }

    /* Flow Diagram Arrows */
    @media (min-width: 768px) {
        .flow-container .flow-item:not(:last-child)::after {
            content: "→";
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.5rem;
            color: var(--brand-strong);
            opacity: 0.2;
            z-index: 0;
        }
    }
</style>
@endpush
@endsection

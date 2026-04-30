@extends('site.layout')
@section('title', 'BSI Capital - Securitizadora')

@section('content')
<section class="hero position-relative overflow-hidden">
    <video autoplay loop muted playsinline class="position-absolute w-100 h-100 object-fit-cover" style="top: 0; left: 0; z-index: 0; opacity: 0.18; pointer-events: none;">
        <source src="https://opea.com.br/wp-content/themes/opeacapital/assets/video/nova_intro.mp4" type="video/mp4">
    </video>

    <div class="container py-4 position-relative">
        <div class="row align-items-center g-5">
            <div class="col-xl-7">
                <div class="kicker mb-3">Securitização • Mercado de Capitais • Crédito Estruturado</div>
                <h1 class="display-3 fw-bold mb-4">
                    Securitização e crédito estruturado com excelência técnica, governança rigorosa e presença ativa em todo o ciclo de vida da operação.
                </h1>
                <p class="lead mb-4" style="max-width: 720px;">
                    A BSI Capital viabiliza o acesso ao mercado de capitais por meio de modelagens financeiras precisas e gestão de alta proximidade, conectando emissores e investidores com transparência operacional e consistência regulatória.
                </p>

                <div class="d-flex flex-wrap gap-3 mb-4">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg px-5">Consultar Viabilidade</a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-light btn-lg px-5">Pipeline de Emissões</a>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Desde</div>
                            <div class="hero-metric-value fw-bold">2009</div>
                            <div class="small text-white-50">Sólida atuação no mercado de capitais brasileiro.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Governança</div>
                            <div class="hero-metric-value fw-bold">CVM</div>
                            <div class="small text-white-50">Companhia aberta registrada e aderente aos padrões de compliance.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="surface-card-dark p-4 h-100">
                            <div class="kicker mb-2">Gestão de Ativos</div>
                            <div class="hero-metric-value fw-bold">Controle</div>
                            <div class="small text-white-50">Monitoramento de lastro, covenants e transparência contínua.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="surface-card-dark p-4 p-lg-5">
                    <div class="kicker mb-3">Leitura rápida</div>
                    <h2 class="h3 fw-bold mb-3 text-white">Da estruturação à gestão: cobertura em todas as fases</h2>
                    <p class="text-white-50 mb-4">
                        Atuamos desde a concepção jurídico-financeira até o acompanhamento pós-emissão, com processos definidos, documentação controlada e fluxo de informação entre as partes.
                    </p>

                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">1</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Arquitetura e Modelagem</div>
                                <div class="small text-white-50">Desenho estratégico da tese, estruturação jurídico-financeira e coordenação integral da oferta.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">2</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Monitoramento de Riscos</div>
                                <div class="small text-white-50">Gestão ativa de colaterais, acompanhamento de covenants e reporte de eventos de crédito.</div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 align-items-start">
                            <div class="badge badge-soft px-3 py-2">3</div>
                            <div>
                                <div class="fw-semibold text-white mb-1">Infraestrutura e Transparência</div>
                                <div class="small text-white-50">Plataforma própria para controle de dados, trilha de auditoria e visibilidade para investidores.</div>
                            </div>
                        </div>
                    </div>
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
                <h2 class="display-6 fw-bold mb-3 text-brand">Atuação por setor, com aderência ao ativo e à operação</h2>
                <p class="section-copy mb-0">
                    Cada setor exige uma lógica própria de ativo e fluxo de caixa. Estruturamos operações com inteligência setorial sob medida, respeitando as particularidades de cada negócio.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="section-divider ms-lg-auto"></div>
            </div>
        </div>

        @php
            $industries = [
                ['Imobiliário', 'Estruturas de CRI moldadas ao perfil do ativo, abrangendo incorporação, loteamento, built-to-suit e portfólio, com gestão documental e monitoramento ativo da carteira.', 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=800&auto=format&fit=crop', '/imobiliario/cri-real-estate'],
                ['Agronegócio', 'Operações de CRA e crédito estruturado para produtores e cooperativas, respeitando a sazonalidade e as garantias específicas do agronegócio com agilidade e rigor técnico.', 'https://images.unsplash.com/photo-1500382017468-9049fed747ef?q=80&w=800&auto=format&fit=crop', '/agronegocio/cra'],
                ['Infra & Empresas', 'Estruturas de capital inteligentes para Capex, expansão ou recomposição de passivo, utilizando fluxos futuros ou recebíveis performados como lastro estratégico.', 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?q=80&w=800&auto=format&fit=crop', '/infra-empresas/cr-futuro'],
            ];
        @endphp

        <div class="row g-4">
            @foreach($industries as [$title, $desc, $img, $link])
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 overflow-hidden position-relative border-0 shadow-sm card-hover" style="min-height: 420px;">
                        <img src="{{ $img }}" class="position-absolute w-100 h-100 object-fit-cover" alt="{{ $title }}">
                        <div class="position-absolute w-100 h-100" style="background: linear-gradient(180deg, rgba(2, 9, 24, 0.05) 0%, rgba(0, 18, 51, 0.82) 74%, rgba(0, 18, 51, 0.96) 100%);"></div>
                        <div class="position-relative h-100 d-flex flex-column justify-content-end p-4 text-white">
                            <h3 class="h4 fw-bold mb-3 text-white">{{ $title }}</h3>
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
                <div class="section-kicker mb-2">Experiência Aplicada</div>
                <h2 class="display-6 fw-bold mb-3">Execução com padrão institucional, da estruturação ao monitoramento</h2>
                <p class="text-muted mb-0">
                    Governança, tecnologia e processos rigorosos para que cada etapa da operação seja rastreável, auditável e reportada com precisão aos stakeholders.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="section-divider ms-lg-auto"></div>
            </div>
        </div>

        @php
            $cases = [
                [
                    'title' => 'Estruturação de CRI',
                    'desc' => 'Modelagem técnica integral, coordenação jurídica e financeira, controle de lastro e monitoramento ativo da operação até o vencimento final.',
                    'img' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=1000&auto=format&fit=crop',
                    'slug' => 'estruturacao-cri',
                ],
                [
                    'title' => 'Gestão de Ativos e Documentos',
                    'desc' => 'Ecosistema digital com acessos dedicados para emissores e investidores, integrando custódia de documentos e trilha de auditoria com total transparência.',
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
                                    <h3 class="h3 fw-bold mb-3">{{ $case['title'] }}</h3>
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

<section class="py-5">
    <div class="container py-4">
        <div class="row g-4 mb-4 align-items-end">
            <div class="col-lg-6">
                <div class="section-kicker mb-2">Transparência e Mercado</div>
                <h2 class="h2 fw-bold text-brand mb-3">Emissões e informações regulatórias com acesso centralizado</h2>
                <p class="section-copy mb-0">
                    Disponibilizamos dados operacionais, documentos regulatórios e comunicados oficiais por meio de um portal de transparência robusto e intuitivo.
                </p>
            </div>
            <div class="col-lg-6 text-lg-end">
                <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a class="btn btn-outline-brand" href="{{ route('site.emissions') }}">Explorar Emissões</a>
                    <a class="btn btn-outline-brand" href="{{ route('site.ri') }}">Portal de R.I.</a>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <div class="row g-4">
                    @forelse($emissions as $e)
                        <div class="col-md-6">
                            <a href="{{ route('site.emissions.show', $e->if_code ?? $e->id) }}" class="text-decoration-none d-block h-100">
                                <div class="card h-100 overflow-hidden emission-card">
                                    <div style="height: 4px; background: linear-gradient(90deg, var(--brand), var(--gold), var(--brand));"></div>
                                    <div class="p-4">
                                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                            <div style="min-width: 0;">
                                                @if($e->logo_path)
                                                    <img src="{{ Storage::disk($e->logo_storage_disk)->url($e->logo_path) }}" alt="{{ $e->name }}" style="max-height: 40px; max-width: 170px; object-fit: contain;" loading="lazy">
                                                @else
                                                    <h3 class="h5 fw-bold mb-0 text-brand">{{ $e->name }}</h3>
                                                @endif
                                            </div>
                                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                                @if($e->status)
                                                    @php
                                                        $statusColors = [
                                                            'active' => ['bg' => 'rgba(34,197,94,0.1)', 'border' => 'rgba(34,197,94,0.25)', 'text' => '#16a34a', 'label' => 'Ativa'],
                                                            'closed' => ['bg' => 'rgba(239,68,68,0.1)', 'border' => 'rgba(239,68,68,0.25)', 'text' => '#dc2626', 'label' => 'Encerrada'],
                                                        ];
                                                        $sc = $statusColors[$e->status] ?? ['bg' => 'rgba(245,158,11,0.1)', 'border' => 'rgba(245,158,11,0.25)', 'text' => '#d97706', 'label' => ucfirst($e->status)];
                                                    @endphp
                                                    <span class="badge d-inline-flex align-items-center gap-1" style="background: {{ $sc['bg'] }}; border: 1px solid {{ $sc['border'] }}; color: {{ $sc['text'] }}; font-size: 0.72rem; padding: 0 12px; height: 28px;">
                                                        <span style="width: 6px; height: 6px; border-radius: 50%; background: {{ $sc['text'] }}; display: inline-block;"></span>
                                                        {{ $sc['label'] }}
                                                    </span>
                                                @endif
                                                @if($e->type)
                                                    <span class="badge badge-soft d-inline-flex align-items-center" style="font-size: 0.72rem; white-space: nowrap; padding: 0 12px; height: 28px;">{{ $e->type }}</span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="d-flex flex-column gap-3">
                                            <div>
                                                <div class="small text-uppercase text-muted fw-semibold mb-1">Emissor</div>
                                                <div class="fw-semibold">{{ $e->issuer ?? '—' }}</div>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-6">
                                                    <div class="small text-uppercase text-muted fw-semibold mb-1">Vencimento</div>
                                                    <div class="fw-semibold">{{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}</div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="small text-uppercase text-muted fw-semibold mb-1">Valor emitido</div>
                                                    <div class="fw-semibold">{{ $e->issued_volume ? 'R$ ' . number_format($e->issued_volume, 2, ',', '.') : '—' }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    @empty
                        <div class="col-12">
                            <div class="card p-5 text-center" style="border-style: dashed;">
                                <div class="text-muted">No momento, não há emissões públicas disponíveis.</div>
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card overflow-hidden">
                    <div class="p-4 p-lg-5 border-bottom border-brand-subtle">
                        <div class="section-kicker mb-2">Relações com investidores</div>
                        <h3 class="h3 fw-bold text-brand mb-2">Divulgações ao mercado</h3>
                        <p class="section-copy mb-0">Asseguramos a integridade da operação com a publicação rigorosa de fatos relevantes e relatórios periódicos, em total aderência às normas regulatórias e de autorregulação.</p>
                    </div>
                    <div class="list-group list-group-flush">
                        @forelse($riDocuments as $d)
                            <div class="list-group-item p-4 ri-item" style="background: transparent; border-color: var(--border);">
                                <div class="d-flex gap-3 align-items-start">
                                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 46px; height: 46px; border-radius: 14px; background: rgba(0, 32, 91, 0.06); color: var(--brand);">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold mb-1">{{ $d->title }}</div>
                                        <div class="d-flex flex-wrap gap-3 small text-muted">
                                            <span>{{ $d->category_label ?? 'Documento' }}</span>
                                            <span>{{ optional($d->published_at)->format('d/m/Y') ?? '—' }}</span>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="{{ Storage::disk($d->resolved_storage_disk)->url($d->file_path) }}" target="_blank" class="btn btn-light btn-sm px-3">Abrir</a>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="list-group-item p-5 text-center text-muted" style="background: transparent; border-color: var(--border);">
                                No momento, não há documentos públicos disponíveis.
                            </div>
                        @endforelse
                    </div>
                </div>
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
                            Nossa equipe técnica está pronta para atuar na estruturação de operações, coordenação de ofertas ou relações com investidores, garantindo agilidade e suporte consultivo em cada contato.
                        </p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-4 p-lg-5 d-flex flex-column gap-3">
                        <a href="{{ route('site.contact') }}" class="btn btn-light btn-lg">Solicitar contato</a>
                        <a href="{{ route('proposal.create') }}" class="btn btn-outline-brand btn-lg" style="background: rgba(255,255,255,0.05); color: #fff; border-color: rgba(255,255,255,0.18);">Enviar proposta</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

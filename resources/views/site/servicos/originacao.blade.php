@extends('site.layout')

@section('title', 'Originação Estratégica | BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/originacao.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Serviços</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Originação Estratégica <br><span style="color: var(--gold);">de Operações</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Avaliamos ativos, recebíveis e teses de crédito para identificar operações com potencial de estruturação via mercado de capitais. Combinamos análise de lastro, viabilidade financeira e aderência regulatória desde o nosso diagnóstico inicial.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Solicitar análise de originação
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões estruturadas
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/originacao.png') }}" class="img-fluid" alt="Originação de Operações" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Análise de Viabilidade</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Filtro Técnico Ágil</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- BSI em Números (Social Proof) -->
<section class="py-5" style="background: linear-gradient(135deg, var(--brand-strong), var(--brand));">
    <div class="container py-4">
        <div class="row g-4 text-center">
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">[X]+</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Projetos analisados</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">R$ [X]</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Em operações avaliadas</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">[X]+</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Parceiros estratégicos</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">Até [X]h</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Resposta preliminar</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Para quem a originação é indicada -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Para quem a originação é indicada</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Avaliamos diferentes perfis e estruturas, indicando caminhos possíveis para acessar o mercado de capitais.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Novos Emissores</h4>
                        <p class="text-muted small mb-0">Empresas buscando a primeira operação no mercado de capitais.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Mercado Imobiliário</h4>
                        <p class="text-muted small mb-0">Incorporadoras, loteadoras e empresas com recebíveis imobiliários.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Agronegócio</h4>
                        <p class="text-muted small mb-0">Agroindústrias, cooperativas e originadores agro.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Crédito Corporativo</h4>
                        <p class="text-muted small mb-0">Empresas com contratos recorrentes ou recebíveis corporativos futuros.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Parceiros Estratégicos</h4>
                        <p class="text-muted small mb-0">Originadores, assessorias, Investment banks e boutiques de M&A.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Instrumentos Estruturados</h4>
                        <p class="text-muted small mb-0">Empresas avaliando acesso a CRI, CRA, CR ou estruturas de securitização sob medida.</p>
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
            <h2 class="h3 fw-bold text-dark mb-3">Sua operação começa aqui</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">A fase inicial é o alicerce da estruturação. Analisamos a documentação inicial e o lastro para que a operação avance com previsibilidade técnica e institucional.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Validação de Teses</h3>
                    <p class="text-muted mb-0">Avaliamos a qualidade dos recebíveis e critérios de elegibilidade, realizando uma análise de lastro profunda para identificar viabilidade e riscos estruturais antes de seguir adiante.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Fluxo de Caixa Real</h3>
                    <p class="text-muted mb-0">Avaliamos a capacidade de pagamento, testamos cenários de estresse e dimensionamos a estrutura de garantias para adequar a operação à realidade financeira do emissor.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5L6 9H2v6h4l5 4V5z"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Posicionamento de Mercado</h3>
                    <p class="text-muted mb-0">Apoiamos na definição de mitigadores de riscos e preparamos a tese com foco na aderência ao investidor, alinhando as expectativas regulatórias e comerciais.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Segmentos de Especialidade -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Ecossistemas de Atuação</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossa inteligência é transversal, com profundo conhecimento técnico em setores estratégicos.</p>
        </div>

        <div class="row g-4 text-center justify-content-center">
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 shadow-sm h-100 d-flex flex-column align-items-center justify-content-center card-hover transition-all">
                    <div class="mb-3 text-gold">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    </div>
                    <h4 class="h5 fw-bold mb-3">Imobiliário</h4>
                    <p class="text-muted small mb-0">Estruturação de recebíveis imobiliários, análise aprofundada de lastro, constituição de garantias hipotecárias/alienação fiduciária e estudo de viabilidade do empreendimento.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 shadow-sm h-100 d-flex flex-column align-items-center justify-content-center card-hover transition-all">
                    <div class="mb-3 text-gold">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                    </div>
                    <h4 class="h5 fw-bold mb-3">Agronegócio</h4>
                    <p class="text-muted small mb-0">Mapeamento do ciclo de caixa, avaliação de safra, cadeia produtiva e estruturação baseada em CPRs com foco em garantias rurais (CRA, CDCA, etc).</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 bg-white rounded-4 shadow-sm h-100 d-flex flex-column align-items-center justify-content-center card-hover transition-all">
                    <div class="mb-3 text-gold">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                    </div>
                    <h4 class="h5 fw-bold mb-3">Infra & Empresas</h4>
                    <p class="text-muted small mb-0">Desconto de recebíveis futuros, monetização de contratos recorrentes, financiamento de CAPEX e originação de crédito corporativo para diversos setores.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Perfis de Atendimento -->
<section class="py-5 bg-white">
    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-6">
                <div class="p-5 rounded-4 h-100" style="background: #f8f9fa; border: 1px solid #eee;">
                    <span class="text-brand fw-bold small text-uppercase mb-3 d-block">Para Emissores</span>
                    <h3 class="fw-bold mb-4">Empresas e Incorporadoras</h3>
                    <p class="text-muted mb-4">Oferecemos diagnóstico financeiro completo para empresas que buscam sua primeira emissão ou desejam otimizar estruturas atuais.</p>
                    <ul class="list-unstyled d-flex flex-column gap-2">
                        <li class="d-flex align-items-center gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> <span class="small fw-medium">Avaliação de custo de oportunidade</span></li>
                        <li class="d-flex align-items-center gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> <span class="small fw-medium">Estruturação de garantias reais</span></li>
                        <li class="d-flex align-items-center gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> <span class="small fw-medium">Suporte na obtenção de Rating</span></li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-5 rounded-4 h-100" style="background: var(--brand); color: white;">
                    <span class="text-gold fw-bold small text-uppercase mb-3 d-block">Para Parceiros</span>
                    <h3 class="fw-bold mb-4">Originadores e Assessorias</h3>
                    <p class="text-white-50 mb-4">Atuamos como o braço fiduciário ágil para assessorias de M&A e Investment Banks que precisam viabilizar teses complexas.</p>
                    <ul class="list-unstyled d-flex flex-column gap-2">
                        <li class="d-flex align-items-center gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> <span class="small fw-medium text-white">White-label de tecnologia fiduciária</span></li>
                        <li class="d-flex align-items-center gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> <span class="small fw-medium text-white">Feedback de risco em até 48 horas</span></li>
                        <li class="d-flex align-items-center gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> <span class="small fw-medium text-white">Coordenação regulatória dedicada</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Da tese ao mandato -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Do Diagnóstico ao Mandato</h2>
                <p class="text-muted mb-4 lead">
                    Nossa originação prepara o terreno para avaliar o potencial de estruturação. Trabalhamos para indicar caminhos possíveis com viabilidade técnica e regulatória.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Realizamos um diagnóstico inicial rigoroso e a pré-validação de riscos para sugerir o enquadramento regulatório adequado.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Unimos as frentes de análise financeira e análise jurídica preliminar para fundamentar a tese de mercado.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Finalizamos com a preparação para mandato, desenhando uma estruturação sujeita à validação jurídica, financeira e regulatória.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/originacao.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Leadership Quote -->
<section class="py-5" style="background: var(--bg);">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card border-0 shadow-sm p-4 p-md-5 position-relative overflow-hidden" style="border-radius: 30px; background: white;">
                    <div class="position-absolute top-0 end-0 p-4" style="opacity: 0.05; pointer-events: none;">
                        <svg width="120" height="120" viewBox="0 0 24 24" fill="var(--brand)"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
                    </div>
                    <div class="row align-items-center g-4 position-relative z-1">
                        <div class="col-md-3 text-center">
                            <div class="rounded-circle mx-auto mb-3" style="width: 120px; height: 120px; background: url('{{ asset('images/avatar-placeholder.png') }}') center/cover; border: 4px solid var(--gold-soft);"></div>
                        </div>
                        <div class="col-md-9">
                            <blockquote class="blockquote mb-0">
                                <p class="fs-4 fw-medium text-dark mb-4 italic" style="line-height: 1.6;">
                                    "Uma boa operação começa no detalhe. Nosso papel é traduzir a força do seu negócio para o mercado de capitais, encontrando o equilíbrio entre o que a empresa precisa e o que o investidor busca."
                                </p>
                                <footer class="blockquote-footer mt-2">
                                    <span class="fw-bold text-brand fs-5 d-block">Diretoria de Novos Negócios</span>
                                    <cite title="BSI Capital" class="small text-muted text-uppercase fw-bold" style="letter-spacing: 0.1em;">BSI Capital Securitizadora</cite>
                                </footer>
                            </blockquote>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Outros serviços -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Próximas etapas da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">A originação é o ponto de partida. Conheça os serviços que levam a operação do mandato ao fechamento.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.servicos.estrutura-juridica') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Estrutura Jurídica</h3>
                    <p class="text-muted mb-3">Cuidamos de toda a parte documental e dos contratos, garantindo que o lastro e as garantias da operação estejam juridicamente blindados.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.servicos.registro-distribuicao') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Registro e Distribuição</h3>
                    <p class="text-muted mb-3">Gerenciamos o fluxo junto aos órgãos reguladores e definimos a melhor estratégia para colocar os títulos no mercado com sucesso.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
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

    .card-hover:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important;
    }
    
    .transition-all {
        transition: all 0.3s ease;
    }
</style>
@endpush
@endsection

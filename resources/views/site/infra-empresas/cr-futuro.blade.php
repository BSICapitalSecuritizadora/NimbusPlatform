@extends('site.layout')

@section('title', 'CR | Certificado de Recebíveis | BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/cr_futuro.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Infra & Empresas</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    O Futuro da <br><span style="color: var(--gold);">Securitização: CR</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Acesse o mercado de capitais via Certificado de Recebíveis. Utilizamos o novo marco regulatório para transformar fluxos futuros em capital imediato para sua empresa.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Simular Estrutura
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver Portfólio
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/cr_futuro.png') }}" class="img-fluid" alt="CR - Certificados de Recebíveis" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Marco CVM 175</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Eficiência Regulatória</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- BSI no Novo Marco (Social Proof) -->
<section class="py-5" style="background: linear-gradient(135deg, var(--brand-strong), var(--brand));">
    <div class="container py-4">
        <div class="row g-4 text-center">
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">R$ 1.2Bi</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Pipeline em Estruturação</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">+R$ 10Bi</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Histórico de Custódia</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">5+</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Novos Setores Atendidos</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="px-3">
                    <div class="display-5 fw-bold text-white mb-1">100%</div>
                    <div class="small text-uppercase fw-bold" style="color: var(--gold); letter-spacing: 0.1em;">Aderência à CVM 175</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Preparação para o próximo ciclo de investimentos</h2>
            <p class="text-muted mx-auto" style="max-width: 640px;">O CR permite que empresas de diversos setores acessem investidores institucionais com a mesma eficiência tributária e jurídica do imobiliário e do agro.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Ativos de Longo Prazo</h3>
                    <p class="text-muted mb-0">Estruturas desenhadas para projetos com fluxos previsíveis e maturação estendida, garantindo fôlego financeiro durante a implantação.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Acesso à Liquidez</h3>
                    <p class="text-muted mb-0">Conectamos emissores de saúde, educação e telecomunicações ao mercado de capitais, diversificando as fontes de funding além dos bancos.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Inteligência Regulatória</h3>
                    <p class="text-muted mb-0">Expertise técnica para definir critérios de elegibilidade e arquitetura de garantias conforme as novas normas da CVM e ANBIMA.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Perfil de Atuação e Tíquetes -->
<section class="py-5" style="background-color: #f8f9fa;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diretrizes de Securitização</span>
                <h2 class="h3 fw-bold text-dark mb-4">Perfil para Emissões de CR</h2>
                <p class="text-muted mb-4">Focamos em corporações e projetos com receitas recorrentes e governança auditada que buscam alternativas à dívida bancária.</p>
                
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">R$ 30MM a R$ 500MM</div>
                            <div class="small text-muted">Tíquete médio por operação de CR corporativo.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Faturamento consolidado</div>
                            <div class="small text-muted">Ideal para empresas com receita bruta anual > R$ 100MM.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Regime Fiduciário</div>
                            <div class="small text-muted">Total isolamento de risco via patrimônio separado.</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 border-start border-4 border-gold bg-white shadow-sm h-100">
                            <div class="fw-bold text-brand h5 mb-1">Recebíveis Futuros</div>
                            <div class="small text-muted">Securitização de contratos de performance ou assinatura.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg overflow-hidden" style="border-radius: 24px;">
                    <div class="card-body p-5" style="background: var(--brand);">
                        <h4 class="text-white fw-bold mb-4">Setores com Alto Potencial</h4>
                        <div class="d-flex flex-column gap-3">
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Saúde (Hospitais, Clínicas, Diagnósticos).</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Educação (Redes de Ensino, Faculdades).</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Telecomunicações e Infraestrutura de TI.</span>
                            </div>
                            <div class="d-flex align-items-center gap-3 text-white">
                                <div class="bg-gold p-2 rounded-circle" style="width: 10px; height: 10px;"></div>
                                <span>Energia e Saneamento descentralizado.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Infraestrutura técnica e posicionamento -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Governança pronta para o novo marco</h2>
                <p class="text-muted mb-4 lead">
                    Nossa infraestrutura de gestão e custódia já opera sob as premissas da CVM 175. Atuamos na modelagem de frameworks de elegibilidade específicos para setores que acabam de entrar na rota da securitização.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Frameworks de elegibilidade modelados para Saúde, Educação e Telecom.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Monitoramento de performance operacional via integração tecnológica.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Adaptação imediata às diretrizes regulatórias da CVM e ANBIMA.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/cr_futuro.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
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
                                    "O CR é a maior revolução do mercado de capitais na última década. Ele permite que o isolamento de risco e a eficiência fiduciária, antes restritos ao agro e imobiliário, cheguem a toda a economia real. Nosso papel é ser o elo que transforma bons projetos em ativos de alta liquidez."
                                </p>
                                <footer class="blockquote-footer mt-2">
                                    <span class="fw-bold text-brand fs-5 d-block">Diretoria de Estruturação Corporativa</span>
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

<!-- CR vs. Debênture -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <h2 class="h3 fw-bold text-dark mb-4">CR vs. Debêntures: Vantagens Fiduciárias</h2>
                <p class="text-muted mb-4">Para projetos de infraestrutura e CAPEX corporativo, o Certificado de Recebíveis oferece benefícios que a dívida direta não consegue atingir.</p>
                
                <div class="comparison-table-container">
                    <table class="table align-middle mb-0 border-0">
                        <thead>
                            <tr>
                                <th style="width: 30%;">Característica</th>
                                <th class="text-center highlight-col" style="width: 35%;">CR (Securitização)</th>
                                <th class="text-center" style="width: 35%;">Debênture</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="fw-bold text-dark">Risco de Crédito</td>
                                <td class="text-center highlight-col">Focado no Ativo (Lastro)</td>
                                <td class="text-center text-muted">Risco Total da Empresa</td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-dark">Regime Fiduciário</td>
                                <td class="text-center highlight-col">Patrimônio Segregado</td>
                                <td class="text-center text-muted">Balanço do Emissor</td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-dark">Flexibilidade</td>
                                <td class="text-center highlight-col">Tranches por Performance</td>
                                <td class="text-center text-muted">Fluxo Pré-definido</td>
                            </tr>
                            <tr>
                                <td class="fw-bold text-dark">Garantias</td>
                                <td class="text-center highlight-col">Vínculo em Fluxo Futuro</td>
                                <td class="text-center text-muted">Garantia Real/Flutuante</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="p-4 bg-white shadow-sm rounded-4 border">
                    <div class="text-brand fw-bold small text-uppercase mb-3" style="letter-spacing: 0.05em;">Mitigação de Risco</div>
                    <h3 class="h4 fw-bold mb-3">Segurança em Recebíveis Futuros</h3>
                    <p class="text-muted">A inovação do CR permite securitizar fluxos ainda não faturados. Para garantir a performance, utilizamos mecanismos de controle dinâmico:</p>
                    <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                        <li class="d-flex align-items-start gap-2">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2.5" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span class="small text-dark fw-medium">Contas Escrow Automáticas: Direcionamento direto para segregação de caixa.</span>
                        </li>
                        <li class="d-flex align-items-start gap-2">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2.5" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span class="small text-dark fw-medium">Gatilhos de Lastro: Exigência de excedente para cobrir variações de receita.</span>
                        </li>
                        <li class="d-flex align-items-start gap-2">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2.5" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span class="small text-dark fw-medium">Monitoramento via API: Visibilidade em acompanhamento contínuo do faturamento do emissor.</span>
                        </li>
                    </ul>
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
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Regulação CVM 175</span>
                    <h2 class="h3 fw-bold text-dark mb-4">A Nova Era da Securitização</h2>
                    <p class="text-muted mb-4">Esclarecemos as principais mudanças trazidas pelo novo marco regulatório para emissores e investidores.</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Framework Regulatório</a>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="accordion accordion-flush custom-accordion" id="faqCR">
                    <!-- FAQ 1 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                01. O que mudou com a CVM 175 para o mercado de CR?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqCR">
                            <div class="accordion-body px-0 text-muted">
                                A resolução permitiu maior flexibilidade, possibilitando estruturar títulos em classes sênior e subordinada em um mesmo veículo. Padronizou o Regime Fiduciário, garantindo isolamento jurídico robusto do lastro.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 2 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                02. O que são Recebíveis Futuros e qual a garantia?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqCR">
                            <div class="accordion-body px-0 text-muted">
                                São fluxos de contratos ainda não faturados, como venda de energia ou mensalidades. A garantia vem do monitoramento da performance operacional e reforços como contas reserva e seguro-garantia.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Por que o CR é superior para o Project Finance?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqCR">
                            <div class="accordion-body px-0 text-muted">
                                Ele isola o ativo de outros problemas financeiros da empresa mãe. Investidores financiam a infraestrutura focando apenas na viabilidade técnica e no fluxo de caixa segregado do projeto.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 4 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                04. Setores como Saúde e Educação podem emitir CR?
                            </button>
                        </h2>
                        <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#faqCR">
                            <div class="accordion-body px-0 text-muted">
                                Sim. O CR é agnóstico ao setor. Hospitais e redes de ensino podem antecipar fluxos futuros para financiar expansões com as mesmas vantagens fiscais da securitização tradicional.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Outros segmentos Infra & Empresas -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Outros Segmentos de Infra & Empresas</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Estruturamos soluções de crédito para empresas e projetos de infraestrutura em diferentes estágios.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.infra.recebiveis') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Recebíveis Empresariais</h3>
                    <p class="text-muted mb-3">Securitização de recebíveis comerciais e contratos de longo prazo para empresas fora do sistema bancário tradicional.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.infra.estruturacao') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="9" x2="20" y2="9"></line><line x1="4" y1="15" x2="20" y2="15"></line><line x1="10" y1="3" x2="8" y2="21"></line><line x1="16" y1="3" x2="14" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Estruturação sob Medida</h3>
                    <p class="text-muted mb-3">Modelagem financeira e jurídica personalizada para operações complexas que exigem arquitetura de crédito customizada.</p>
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

    .table th {
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .comparison-table-container {
        background: #ffffff;
        border: 1px solid rgba(9,27,35,0.04);
        border-radius: 16px;
        box-shadow: 0 4px 14px rgba(9,27,35,0.02);
        overflow: hidden;
    }
    .comparison-table-container thead th {
        background: rgba(9,27,35,0.015);
        border-bottom: 1px solid rgba(9,27,35,0.04);
        color: #8c98a4;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        padding: 1.25rem 1.5rem;
        border-top: none;
        vertical-align: middle;
    }
    .comparison-table-container thead th.highlight-col {
        background: rgba(212,175,55,0.06);
        color: var(--brand);
    }
    .comparison-table-container tbody td {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid rgba(9,27,35,0.03);
        vertical-align: middle;
        font-size: 0.95rem;
    }
    .comparison-table-container tbody tr:last-child td {
        border-bottom: none;
    }
    .comparison-table-container tbody td.highlight-col {
        background: rgba(212,175,55,0.03);
        font-weight: 600;
        color: var(--brand);
    }
    .comparison-table-container tbody tr {
        transition: all 0.2s ease;
    }
    .comparison-table-container tbody tr:hover {
        background: rgba(9,27,35,0.01);
    }
    .comparison-table-container tbody tr:hover td.highlight-col {
        background: rgba(212,175,55,0.06);
    }
</style>
@endpush
@endsection

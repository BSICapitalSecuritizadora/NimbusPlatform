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

<!-- Público-Alvo Section -->
<section class="py-5" style="background-color: #ffffff;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Público-Alvo</span>
                <h2 class="h3 fw-bold text-dark mb-4">Para quem é o Certificado de Recebíveis (CR)?</h2>
                <p class="text-muted mb-4 lead">
                    O CR é estruturado para empresas de diversos setores que possuem fluxos financeiros previsíveis e buscam eficiência jurídica e tributária no mercado de capitais.
                </p>
                <div class="d-flex flex-column gap-3">
                    @foreach([
                        'Empresas de Saúde (Hospitais, Clínicas e Redes de Diagnóstico).',
                        'Instituições de Educação (Redes de Ensino e Faculdades).',
                        'Projetos de Telecomunicações e Infraestrutura de TI.',
                        'Empresas com contratos de longo prazo e recebíveis futuros (Performance).',
                        'Grupos empresariais estruturando expansões (CAPEX).'
                    ] as $item)
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-gold p-1 rounded-circle" style="width: 8px; height: 8px; flex-shrink: 0;"></div>
                            <span class="text-dark fw-medium">{{ $item }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="col-lg-7">
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Recebíveis Futuros</h3>
                            <p class="small text-muted mb-0">Securitização de fluxos a performar (contratos e mensalidades), transformando potencial futuro em caixa imediato.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Capilaridade de Setores</h3>
                            <p class="small text-muted mb-0">Com o novo marco regulatório, levamos a securitização fiduciária para áreas fora do escopo tradicional do agro e imobiliário.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Vantagens Competitivas</h3>
                            <p class="small text-muted mb-0">Obtenha condições de captação (prazos e taxas) mais eficientes quando comparadas ao financiamento bancário corporativo.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Governança Auditada</h3>
                            <p class="small text-muted mb-0">Adequado a empresas com boa previsibilidade de receita anual (faturamento > R$ 100MM) e histórico sólido.</p>
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
            <h2 class="h3 fw-bold text-dark mb-3">Inteligência estrutural em cada fase da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Garantimos a previsibilidade do fluxo de caixa e o controle rigoroso da gestão de ativos por meio de estruturas avançadas de CR.</p>
        </div>

        <div class="row g-4">
            <!-- Diferencial 1 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Ativos de Longo Prazo</h3>
                    <p class="text-muted mb-0">Estruturas desenhadas para projetos com fluxos previsíveis e maturação estendida, garantindo fôlego financeiro durante a implantação de infraestrutura.</p>
                </div>
            </div>
            
            <!-- Diferencial 2 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Inteligência Regulatória</h3>
                    <p class="text-muted mb-0">Expertise técnica para definir critérios de elegibilidade e arquitetura de garantias perfeitamente adequadas ao novo marco da CVM 175.</p>
                </div>
            </div>

            <!-- Diferencial 3 -->
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Segregação de Risco</h3>
                    <p class="text-muted mb-0">Ao invés do risco total da corporação, os investidores avaliam e financiam o fluxo de caixa segregado fiduciariamente, reduzindo as taxas exigidas.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Process Flow Section -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Como funciona o fluxo do CR</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossa jornada transforma ativos, contratos e projetos de corporações em capital otimizado com transparência fiduciária.</p>
        </div>

        <div class="row g-4 flow-container position-relative">
            <!-- Step 1 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Originação</h4>
                    <p class="small text-muted mb-0">Empresas demandam securitização para contratos futuros, antecipação de recebíveis ou estruturação de infraestrutura.</p>
                </div>
            </div>
            <!-- Step 2 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Estruturação</h4>
                    <p class="small text-muted mb-0">Isolamento fiduciário do ativo gerador, formalização dos contratos, adequação à CVM 175 e composição do lastro.</p>
                </div>
            </div>
            <!-- Step 3 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Distribuição</h4>
                    <p class="small text-muted mb-0">Colocação do Certificado de Recebíveis no mercado para fundos e investidores focados em crédito corporativo de qualidade.</p>
                </div>
            </div>
            <!-- Step 4 -->
            <div class="col-md-3 flow-item">
                <div class="p-4 rounded-4 bg-light border h-100 text-center position-relative z-1 shadow-sm">
                    <div class="mb-3 d-inline-flex align-items-center justify-content-center bg-brand rounded-circle text-white shadow-sm" style="width: 50px; height: 50px; background: var(--brand-strong) !important;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h4 class="h6 fw-bold text-uppercase mb-2" style="color: var(--brand-strong);">Monitoramento</h4>
                    <p class="small text-muted mb-0">Gestão contínua das contas vinculadas (Escrow), covenants financeiros e acompanhamento de contratos para repasse do recebível.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Infraestrutura técnica e posicionamento -->
<section class="py-5 bg-light border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <div class="mb-4">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Governança Ativa</span>
                    <h2 class="h3 fw-bold text-dark mb-4">Monitoramento e Gestão Digital</h2>
                </div>
                <p class="text-muted mb-4 lead">
                    Nossa infraestrutura de gestão e custódia já opera de forma integrada para assegurar a transparência dos CRs no decorrer de toda sua vida útil.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-5">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Frameworks de monitoramento e elegibilidade setorial (Saúde, Educação, Telecom).</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Acompanhamento automatizado de contas Escrow e gatilhos de lastro (receitas faturadas x performadas).</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Integração por APIs para relatórios operacionais contínuos e prontidão frente a eventos de crédito.</span>
                    </li>
                </ul>
                <div class="d-flex gap-3">
                    <a href="{{ route('investor.login') }}" class="btn btn-brand px-4 py-2">Portal do Investidor</a>
                </div>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div class="position-relative">
                    <div style="background: url('{{ asset('images/cr_futuro.png') }}') center/cover; height: 450px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); filter: grayscale(20%); mix-blend-mode: multiply;"></div>
                    <div class="position-absolute bg-white p-4 rounded-4 shadow-lg border" style="bottom: 30px; right: -20px; max-width: 280px;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="bg-success bg-opacity-10 p-2 rounded-circle">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline><polyline points="16 7 22 7 22 13"></polyline></svg>
                            </div>
                            <div class="fw-bold small">Isolamento Seguro</div>
                        </div>
                        <div class="text-muted smaller">Gestão de regimes fiduciários conforme os padrões estabelecidos pela nova instrução CVM.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CR vs. Debênture -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Mitigação de Riscos</span>
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
                <div class="p-4 bg-light shadow-sm rounded-4 border">
                    <div class="text-brand fw-bold small text-uppercase mb-3" style="letter-spacing: 0.05em;">Segurança Operacional</div>
                    <h3 class="h4 fw-bold mb-3">Proteção em Recebíveis Futuros</h3>
                    <p class="text-muted">A inovação do CR permite securitizar fluxos ainda não faturados. Para garantir a performance, utilizamos mecanismos de controle dinâmico que superam as debêntures convencionais:</p>
                    <ul class="list-unstyled d-flex flex-column gap-3 mb-0 mt-4">
                        <li class="d-flex align-items-start gap-2">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2.5" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span class="small text-dark fw-medium">Contas Escrow Automáticas para direcionamento e segregação de caixa.</span>
                        </li>
                        <li class="d-flex align-items-start gap-2">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2.5" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span class="small text-dark fw-medium">Gatilhos de Overcollateralization para cobrir variações de receita.</span>
                        </li>
                        <li class="d-flex align-items-start gap-2">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2.5" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span class="small text-dark fw-medium">Visibilidade algorítmica e humana no faturamento da infraestrutura do emissor.</span>
                        </li>
                    </ul>
                </div>
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
                                    "O CR é a maior inovação do mercado de capitais na última década. Ele permite que o isolamento de risco e a eficiência fiduciária, antes restritos ao agro e imobiliário, cheguem a toda a economia real. Nosso papel é ser o elo que transforma bons projetos corporativos em ativos de alta liquidez e segurança."
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

<!-- FAQ Section -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row g-5">
            <div class="col-lg-4">
                <div class="pe-lg-4">
                    <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91, 0.05); letter-spacing: 0.1em; font-weight: 600;">Dúvidas Estratégicas</span>
                    <h2 class="h3 fw-bold text-dark mb-4">A Nova Era da Securitização com a CVM 175</h2>
                    <p class="text-muted mb-4">Esclarecemos os principais detalhes e mudanças estruturais trazidos pelo novo marco regulatório para emissores (CRA, CRI e o novo CR).</p>
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-sm px-4 py-2">Consultar Especialista</a>
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
                                A resolução permitiu a consolidação de diferentes ativos e padronizou o Regime Fiduciário, simplificando os processos e ampliando a segurança jurídica. Ela expande a securitização para praticamente qualquer recebível estruturado de corporações (fora de CRI e CRA).
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
                                São fluxos de contratos já acordados, mas que dependem de performance (como venda de energia futura, planos de saúde mensais ou mensalidades escolares). A garantia é amparada pela inteligência de monitoramento operacional e proteções no caixa do projeto.
                            </div>
                        </div>
                    </div>
                    <!-- FAQ 3 -->
                    <div class="accordion-item bg-transparent border-bottom py-3">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent fw-bold text-dark px-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                03. Por que o CR costuma ser superior ao Project Finance bancário?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqCR">
                            <div class="accordion-body px-0 text-muted">
                                Diferente do crédito direto em que o risco de balanço da matriz encarece o projeto, no CR avalia-se apenas a viabilidade financeira e o fluxo independente do projeto/ativo. O isolamento de risco pelo Patrimônio Separado torna os custos frequentemente mais atraentes.
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
                                Sim, perfeitamente. O CR é agnóstico ao setor. Hospitais, redes de laboratórios e redes de ensino podem securitizar os contratos recorrentes para investir em ampliações físicas (CAPEX), usufruindo da liquidez de capitais institucionais.
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
            <h2 class="h3 fw-bold text-dark mb-2">Outros Segmentos Corporativos e de Infraestrutura</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">Estruturamos soluções de securitização avançada e arquitetura de crédito para a nova economia real.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.infra.recebiveis') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Recebíveis Empresariais</h3>
                    <p class="text-muted mb-3">Soluções para otimização de tesouraria de grandes fornecedores, distribuidores e corporações via antecipação de faturas.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.infra.estruturacao') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="9" x2="20" y2="9"></line><line x1="4" y1="15" x2="20" y2="15"></line><line x1="10" y1="3" x2="8" y2="21"></line><line x1="16" y1="3" x2="14" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Estruturação sob Medida</h3>
                    <p class="text-muted mb-3">Modelagem financeira e jurídica de alto grau de complexidade para fusões, aquisições, debêntures estruturadas e project finance.</p>
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

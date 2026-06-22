@extends('site.layout')

@section('title', 'Estrutura Jurídica — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/estrutura_juridica.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Serviços</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Estrutura Jurídica para <br><span style="color: var(--gold);">Operações de Securitização</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Estruturamos os instrumentos jurídicos que sustentam operações de CRI, CRA e CR, alinhando lastro, garantias, regime fiduciário, documentos de oferta e governança contratual às exigências regulatórias aplicáveis.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('proposal.create') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Solicitar análise jurídica da operação
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
                        <img src="{{ asset('images/estrutura_juridica.png') }}" class="img-fluid" alt="Estrutura Jurídica" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Escritura de emissão</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Arquitetura jurídica</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Para quem a estrutura jurídica é indicada -->
<section class="py-5 bg-white border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Para quem a estrutura jurídica é indicada</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Apoiamos diversos perfis do mercado, garantindo a solidez documental da operação e o cumprimento das normativas vigentes.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Emissores em Estruturação</h4>
                        <p class="text-muted small mb-0">Emissores com operação em fase de estruturação inicial.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Originadores de Recebíveis</h4>
                        <p class="text-muted small mb-0">Empresas e fundos estruturando a cessão de carteiras de crédito.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Coordenadores e Assessores</h4>
                        <p class="text-muted small mb-0">Coordenadores e assessores financeiros estruturando ofertas públicas.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Garantias Complexas</h4>
                        <p class="text-muted small mb-0">Empresas com garantias complexas, múltiplos devedores, cedentes ou garantidores.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Reestruturação e Waivers</h4>
                        <p class="text-muted small mb-0">Operações com necessidade de aditamento, waiver ou reestruturação de garantias.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-4">
                <div class="d-flex align-items-start gap-3 p-4 rounded-4 h-100" style="background: var(--surface-alt); border: 1px solid var(--border);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" class="flex-shrink-0 mt-1"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <div>
                        <h4 class="h6 fw-bold mb-2">Parceiros Jurídicos</h4>
                        <p class="text-muted small mb-0">Escritórios e parceiros que precisam coordenar a documentação final de CRI, CRA ou CR.</p>
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
            <h2 class="h3 fw-bold text-dark mb-3">Base jurídica para operações consistentes</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">A documentação deve refletir com precisão a estrutura econômica da operação, os mecanismos de proteção e as exigências regulatórias aplicáveis a cada instrumento.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Engenharia Documental</h3>
                    <p class="text-muted mb-0">Estruturamos o termo de securitização/escritura de emissão, contratos de cessão e instrumentos de garantia, buscando consistência entre a estrutura econômica e a documentação jurídica.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Validação de Lastro e Garantias</h3>
                    <p class="text-muted mb-0">Avaliamos os critérios de elegibilidade do lastro e a regularidade das garantias, focando na alocação de riscos e na formalização jurídica exigida para os instrumentos correspondentes.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Rigor Regulatório</h3>
                    <p class="text-muted mb-0">Revisamos os documentos de oferta e mantemos interface ativa com CVM, B3, ANBIMA e agente fiduciário, adequando os normativos às exigências do regulador para cada tipo de emissão.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Engenharia de Segregação -->
<section class="py-5" style="background-color: #f8fafc; border-top: 1px solid var(--border);">
    <div class="container py-5">
        <div class="row align-items-center mb-5 pb-3">
            <div class="col-lg-5">
                <h2 class="h3 fw-bold text-dark mb-lg-0">Engenharia de Segregação: Patrimônio Separado e Regime Fiduciário</h2>
            </div>
            <div class="col-lg-7">
                <p class="text-muted mb-0" style="font-size: 1.05rem;">
                    A base da estruturação fiduciária é o patrimônio separado. Entenda como estruturamos a segregação patrimonial conforme o regime fiduciário e os documentos da operação.
                </p>
            </div>
        </div>
        
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <!-- Visual Flow Diagram -->
                <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-4 p-4 bg-white rounded-4 shadow-sm border">
                    <!-- Originador -->
                    <div class="text-center p-3 rounded-3 bg-light border w-100" style="max-width: 200px;">
                        <div class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">Originador</div>
                        <div class="smaller text-muted" style="font-size: 0.75rem;">Empresa / Cedente</div>
                    </div>
                    
                    <div class="d-flex align-items-center justify-content-center">
                        <svg class="d-none d-md-block" width="40" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        <svg class="d-md-none" width="24" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
                    </div>

                    <!-- Securitizadora (Container) -->
                    <div class="p-1 rounded-4 border-dashed border-gold border-2 position-relative w-100" style="max-width: 280px; background: rgba(212,175,55, 0.05);">
                         <div class="text-center p-3 bg-white rounded-3 shadow-sm">
                            <div class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">Patrimônio Separado</div>
                            <div class="smaller text-muted" style="font-size: 0.75rem;">Lastro e Regime Fiduciário</div>
                            <hr class="my-2" style="opacity: 0.1;">
                            <div class="fw-bold" style="color: var(--gold); font-size: 0.7rem; letter-spacing: 0.05em;">SEGREGAÇÃO PATRIMONIAL</div>
                         </div>
                         <div class="position-absolute top-0 start-50 translate-middle badge bg-gold text-white px-2 py-1" style="font-size: 0.6rem; background-color: var(--gold) !important;">BSI CAPITAL</div>
                    </div>

                    <div class="d-flex align-items-center justify-content-center">
                        <svg class="d-none d-md-block" width="40" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        <svg class="d-md-none" width="24" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
                    </div>

                    <!-- Investidores -->
                    <div class="text-center p-3 rounded-3 text-white w-100" style="max-width: 200px; background-color: var(--brand);">
                        <div class="fw-bold mb-1" style="font-size: 0.9rem;">Investidores</div>
                        <div class="smaller opacity-75" style="font-size: 0.75rem;">Detentores dos Títulos</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-5">
                <div class="card border-0 p-4 text-white rounded-4 shadow-lg" style="background-color: var(--brand-strong);">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="p-2 rounded bg-gold shadow-sm">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        </div>
                        <h3 class="h5 fw-bold mb-0 text-white">Mecanismos de Proteção</h3>
                    </div>
                    <p class="small opacity-90 mb-0" style="line-height: 1.6;">
                        O <strong>Regime Fiduciário</strong> institui o patrimônio separado, definindo que os ativos da operação e o fluxo de pagamentos se destinem ao cumprimento das obrigações daquela emissão, sujeitos à legislação aplicável e à alocação de riscos conforme os instrumentos jurídicos. Esse patrimônio pertence exclusivamente aos investidores, sob gestão de um agente fiduciário.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Escopo e Enquadramento -->
<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5">
            <div class="col-lg-6">
                <h2 class="h4 fw-bold text-dark mb-4">Documentos da Emissão</h2>
                <div class="bg-white p-4 rounded-4 shadow-sm border border-brand-subtle">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
                                <li>
                                    <span class="fw-bold d-block small mb-1" style="color: var(--brand);">Instrumentos da emissão</span>
                                    <div class="small d-flex align-items-start gap-2 text-muted">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        <span>Termo de Securitização / Escritura de Emissão</span>
                                    </div>
                                </li>
                                <li>
                                    <span class="fw-bold d-block small mb-1" style="color: var(--brand);">Lastro e Cessão</span>
                                    <div class="small d-flex align-items-start gap-2 text-muted">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        <span>Contratos de Cessão e Aquisição de Direitos Creditórios</span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
                                <li>
                                    <span class="fw-bold d-block small mb-1" style="color: var(--brand);">Garantias</span>
                                    <div class="small d-flex align-items-start gap-2 text-muted">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        <span>Alienação Fiduciária, Cessão Fiduciária e Fiança/Aval</span>
                                    </div>
                                </li>
                                <li>
                                    <span class="fw-bold d-block small mb-1" style="color: var(--brand);">Oferta e Regulatórios</span>
                                    <div class="small d-flex align-items-start gap-2 text-muted">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="3" class="mt-1 flex-shrink-0"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        <span>Prospecto, Lâmina e Contratos de Distribuição</span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <h2 class="h4 fw-bold text-dark mb-4">Especialidade por Lastro</h2>
                <div class="row g-3">
                    <div class="col-12">
                        <div class="d-flex align-items-center gap-3 p-3 bg-white rounded-3 shadow-xs card-hover transition-all border-start border-4 border-gold">
                            <div class="fw-bold text-dark small" style="min-width: 100px;">Imobiliário</div>
                            <div class="text-muted smaller">Lei 9.514/97 (CRI) e Marco Legal da Securitização (Lei 14.430/22)</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="d-flex align-items-center gap-3 p-3 bg-white rounded-3 shadow-xs card-hover transition-all border-start border-4 border-gold">
                            <div class="fw-bold text-dark small" style="min-width: 100px;">Agronegócio</div>
                            <div class="text-muted smaller">Lei 11.076/04 (CRA) e legislação agro aplicável</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="d-flex align-items-center gap-3 p-3 bg-white rounded-3 shadow-xs card-hover transition-all border-start border-4 border-gold">
                            <div class="fw-bold text-dark small" style="min-width: 100px;">Empresarial</div>
                            <div class="text-muted smaller">CR, Certificado de Recebíveis, cessão de direitos creditórios e estruturas aplicáveis</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Acompanhamento jurídico pós-fechamento -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Acompanhamento Jurídico ao Longo da Vida da Operação</h2>
                <p class="text-muted mb-4 lead">
                    A documentação e governança contratual não se encerram no closing. Aditamentos, eventos de crédito, reestruturações contratuais e alterações regulatórias exigem suporte contínuo durante toda a vigência da emissão.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Coordenação de aditamentos em eventos de crédito, solicitações de waivers ou necessidade de reestruturação das garantias e fluxo.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Suporte jurídico em assembleias de titulares, elaborando convocações, atas e deliberações em conjunto com o agente fiduciário.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Atualização documental conforme as regulamentações aplicáveis da CVM e interface proativa com agentes da operação.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium">Formalização digital ponta a ponta, agilizando assinaturas eletrônicas com validade legal e adequação para o registro na B3.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/estrutura_juridica.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Case de Sucesso Jurídico -->
<section class="py-5 bg-white border-top">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="p-4 p-md-5 rounded-4 border bg-light d-flex flex-column flex-md-row align-items-center gap-4 shadow-sm">
                    <div class="flex-shrink-0 bg-white p-3 rounded-circle shadow-sm border border-brand-subtle" style="width: 80px; height: 80px; display: grid; place-items: center; color: var(--gold);">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <div>
                        <span class="badge bg-success-subtle text-success mb-2 px-3 py-1 text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Exemplo de Atuação</span>
                        <h3 class="h4 fw-bold text-dark mb-2">Eficiência jurídica na estruturação de CRA</h3>
                        <p class="text-muted mb-0" style="font-size: 0.95rem;">
                            Ao estruturar operações com múltiplas garantias reais (imóveis rurais e penhor de safra), aplicamos nossa "Engenharia Documental" e due diligence integrada. Isso visa conferir celeridade à modelagem da operação, mitigando riscos documentais e preparando a estruturação com alto padrão de diligência para a avaliação do mercado.
                        </p>
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
            <h2 class="h3 fw-bold text-dark mb-2">Etapas adjacentes da operação</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">A estrutura jurídica conecta a originação ao registro e distribuição da oferta — conheça as etapas que precedem e sucedem este serviço.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.servicos.originacao') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Originação</h3>
                    <p class="text-muted mb-3">Avaliação do enquadramento do ativo, modelagem financeira e posicionamento da tese junto ao mercado antes do mandato de estruturação.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.servicos.registro-distribuicao') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Registro e Distribuição</h3>
                    <p class="text-muted mb-3">Coordenação do fluxo regulatório e estratégia de colocação junto a investidores e distribuidores, do protocolo ao fechamento da oferta.</p>
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
</style>
@endpush
@endsection

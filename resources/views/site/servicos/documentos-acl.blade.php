@extends('site.layout')

@section('title', 'Documentos com ACL — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/documentos_acl.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Tecnologia</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Documentos <br>com <span style="color: var(--gold);">ACL</span>
                </h1>
                <p class="h5 fw-medium mb-4" style="color: var(--gold); letter-spacing: 0.05em;">COFRE DIGITAL E DATA ROOM SEGURO</p>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Controle total sobre quem acessa cada documento. Gerencie permissões por perfil de stakeholder e acompanhe o histórico completo de visualizações por operação, com segurança de nível bancário.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Consultar Especialista
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                    <a href="{{ route('site.emissions') }}" class="btn btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3" style="border: 1px solid rgba(230,228,228,0.35); color: #E6E4E4; background: rgba(230,228,228,0.08); transition: all 0.3s ease;">
                        Ver emissões
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/documentos_acl.png') }}" class="img-fluid" alt="Documentos com ACL" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Acesso controlado</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Por perfil e operação</div>
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
            <h2 class="h3 fw-bold text-dark mb-3">Segurança absoluta e controle de visibilidade</h2>
            <p class="text-muted mx-auto" style="max-width: 640px;">Nossa tecnologia de ACL assegura que dados estratégicos nunca saiam do círculo de confiança da operação. Tudo é auditado e rastreado em tempo real.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Acesso por Perfil</h3>
                    <p class="text-muted mb-0">Agentes fiduciários, distribuidores e investidores visualizam apenas o que é pertinente à sua função. O sigilo é mantido de ponta a ponta, sem ruídos informacionais.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Trilha de Auditoria</h3>
                    <p class="text-muted mb-0">Saiba exatamente quem abriu cada arquivo e em que momento. Registros detalhados garantem total transparência para processos de compliance e auditorias externas.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Conformidade LGPD</h3>
                    <p class="text-muted mb-0">Proteção nativa para dados sensíveis. Nossa infraestrutura impede vazamentos e garante que a custódia das informações siga rigorosamente as normas da CVM.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Painel de Transparência e Estatísticas -->
<section class="py-5" style="background: linear-gradient(180deg, var(--bg) 0%, #ffffff 100%);">
    <div class="container">
        <div class="row g-4 text-center">
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-white shadow-sm border h-100">
                    <div class="display-6 fw-bold text-brand mb-2">R$ {{ number_format($stats['total_volume'] / 1000000, 1, ',', '.') }} Mi</div>
                    <div class="text-uppercase small fw-bold text-muted" style="letter-spacing: 0.1em;">Total Estruturado</div>
                    <div class="mt-3 smaller text-muted">Volume de crédito monitorado por nossa infraestrutura.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-white shadow-sm border h-100">
                    <div class="display-6 fw-bold text-brand mb-2">{{ $stats['active_count'] }}</div>
                    <div class="text-uppercase small fw-bold text-muted" style="letter-spacing: 0.1em;">Operações em curso</div>
                    <div class="mt-3 smaller text-muted">Emissões ativas com gestão documental via ACL.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-4 rounded-4 bg-white shadow-sm border h-100">
                    <div class="display-6 fw-bold text-brand mb-2">{{ $stats['document_count'] }}</div>
                    <div class="text-uppercase small fw-bold text-muted" style="letter-spacing: 0.1em;">Arquivos Públicos</div>
                    <div class="mt-3 smaller text-muted">Acesso livre para transparência e RI.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Diferenciação: Público vs Controlado -->
<section class="py-5">
    <div class="container">
        <div class="rounded-4 p-4 p-lg-5 position-relative overflow-hidden" style="background-color: #091B23; box-shadow: 0 20px 40px rgba(9, 27, 35, 0.2);">
            <!-- Elemento decorativo de fundo -->
            <div class="position-absolute top-0 end-0 p-5" style="opacity: 0.05;">
                <svg width="250" height="250" viewBox="0 0 24 24" fill="none" stroke="#A06E28" stroke-width="1"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            </div>
            
            <div class="row align-items-center g-5 position-relative z-1">
                <div class="col-lg-7" style="color: #E6E4E4;">
                    <h2 class="h2 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em; text-wrap: balance;">Informação pública vs. Sigilo estratégico</h2>
                    <p class="lead mb-5" style="color: #E6E4E4; opacity: 0.85; font-weight: 300;">Equilibramos a transparência exigida pelo mercado com o sigilo necessário para a proteção dos ativos. Cada público acessa exatamente o que precisa.</p>
                    
                    <div class="row g-4">
                        <div class="col-sm-6">
                            <div class="d-flex gap-3 align-items-start p-3 rounded-3 h-100" style="background: rgba(230, 228, 228, 0.03); border: 1px solid rgba(230, 228, 228, 0.05); transition: all 0.3s ease;">
                                <div style="color: #A06E28; flex-shrink: 0;">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>
                                </div>
                                <div>
                                    <div class="fw-bold mb-2" style="color: #ffffff;">Portal de RI</div>
                                    <p class="small mb-0" style="color: #E6E4E4; opacity: 0.75; line-height: 1.6;">Download direto de prospectos, anúncios e fatos relevantes sem necessidade de autenticação.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-3 align-items-start p-3 rounded-3 h-100" style="background: rgba(230, 228, 228, 0.03); border: 1px solid rgba(230, 228, 228, 0.05); transition: all 0.3s ease;">
                                <div style="color: #A06E28; flex-shrink: 0;">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                </div>
                                <div>
                                    <div class="fw-bold mb-2" style="color: #ffffff;">Ambiente Restrito</div>
                                    <p class="small mb-0" style="color: #E6E4E4; opacity: 0.75; line-height: 1.6;">O sistema ACL blinda documentos de lastro e dados sensíveis para acesso exclusivo de stakeholders autorizados.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="p-4 p-lg-5 rounded-4 shadow-lg text-center position-relative mt-4 mt-lg-0" style="background: #ffffff; border-top: 4px solid #A06E28;">
                        <div class="position-absolute top-0 start-50 translate-middle">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle shadow-sm" style="width: 56px; height: 56px; background: #ffffff; color: #A06E28; border: 4px solid #ffffff;">
                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle w-100 h-100" style="background: rgba(160, 110, 40, 0.1);">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                </div>
                            </div>
                        </div>
                        <h3 class="h4 fw-bold mb-3 mt-3" style="color: #091B23;">Busca por documentos públicos?</h3>
                        <p class="small mb-4" style="color: #6c757d; line-height: 1.6;">Não é necessário login para acessar documentos de transparência obrigatória das nossas emissões.</p>
                        <a href="{{ route('site.emissions') }}" class="btn w-100 py-3 fw-bold d-inline-flex align-items-center justify-content-center gap-2" style="background-color: #091B23; color: #ffffff; border: none; transition: all 0.3s ease;">
                            Ver Portal de Emissões
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Últimas Emissões Reais -->
@if($latestEmissions->isNotEmpty())
<section class="py-5" style="background-color: #f8f9fa;">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-end mb-5">
            <div>
                <h2 class="h3 fw-bold mb-2" style="color: #091B23;">Operações em monitoramento</h2>
                <p class="text-muted mb-0">Exemplos de emissões que utilizam nossa infraestrutura de documentos.</p>
            </div>
            <a href="{{ route('site.emissions') }}" class="fw-bold text-decoration-none d-flex align-items-center gap-1" style="color: #A06E28; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                Ver todas 
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
            </a>
        </div>
        
        <div class="row g-4">
            @foreach($latestEmissions as $emission)
            <div class="col-md-4">
                <div class="card h-100 border-0 rounded-4" style="background: #ffffff; box-shadow: 0 4px 20px rgba(0,0,0,0.04); transition: transform 0.3s ease, box-shadow 0.3s ease;" onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 15px 30px rgba(0,0,0,0.08)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 20px rgba(0,0,0,0.04)';">
                    <div class="p-4 border-bottom" style="border-color: #f1f1f1 !important;">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="badge rounded-pill" style="background: rgba(160, 110, 40, 0.1); color: #A06E28; font-weight: 700; padding: 0.5em 0.8em; letter-spacing: 0.05em;">{{ $emission->type }}</span>
                            <span class="small fw-semibold text-muted" style="font-family: monospace; letter-spacing: 0.05em;">{{ $emission->if_code }}</span>
                        </div>
                        <div class="d-flex gap-3 align-items-center justify-content-between">
                            <h3 class="h5 fw-bold mb-0" style="color: #091B23; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis; line-height: 1.3;">
                                {{ $emission->name }}
                            </h3>
                            @if($emission->logo_path)
                                <img src="{{ Storage::disk($emission->logo_storage_disk)->url($emission->logo_path) }}" alt="{{ $emission->issuer }}" class="rounded-3 shadow-sm border" style="width: 48px; height: 48px; object-fit: contain; background: #fff; flex-shrink: 0; padding: 4px;">
                            @else
                                <div class="d-flex align-items-center justify-content-center rounded-3 shadow-sm" style="width: 48px; height: 48px; background: rgba(9, 27, 35, 0.03); color: #091B23; font-weight: bold; font-size: 1.2rem; flex-shrink: 0; border: 1px solid rgba(0,0,0,0.05);">
                                    {{ Str::upper(Str::substr($emission->issuer ?? $emission->name, 0, 1)) }}
                                </div>
                            @endif
                        </div>
                    </div>
                    
                    <div class="card-body p-4">
                        <div class="d-flex flex-column gap-3">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2" style="border-color: #f8f9fa !important;">
                                <span class="small text-muted">Emissor</span>
                                <span class="small fw-bold text-dark text-end" style="max-width: 65%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="{{ $emission->issuer }}">{{ Str::limit($emission->issuer, 25) }}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small text-muted">Status</span>
                                <span class="badge rounded-pill" style="background: rgba(16, 185, 129, 0.1); color: #10b981; font-weight: 600; padding: 0.4em 0.8em;">{{ $emission->status_label }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-4 pt-0 mt-auto">
                        <a href="{{ route('site.emissions.show', $emission->if_code) }}" class="btn w-100 rounded-3 fw-bold d-flex align-items-center justify-content-center gap-2 py-2" style="background: #f8f9fa; color: #091B23; border: 1px solid #E6E4E4; transition: all 0.2s;" onmouseover="this.style.background='#091B23'; this.style.color='#ffffff'; this.style.borderColor='#091B23';" onmouseout="this.style.background='#f8f9fa'; this.style.color='#091B23'; this.style.borderColor='#E6E4E4';">
                            Documentos Públicos
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                        </a>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>
@endif

<!-- Visualização de Controle de Acesso -->

<section class="py-5" style="background-color: var(--surface-alt); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);">
    <div class="container py-4">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5">
                <h2 class="h4 fw-bold text-dark mb-4">Arquitetura de Visibilidade</h2>
                <p class="text-muted small mb-4">Um único repositório, múltiplas visões. Nossa tecnologia de ACL garante que cada stakeholder acesse exatamente o que sua função exige, protegendo o sigilo estratégico da operação.</p>
                
                <div class="d-flex flex-column gap-3">
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Agente Fiduciário</div>
                        <div class="text-muted smaller">Acesso total a documentos de lastro, seguros e garantias para monitoramento de covenants.</div>
                    </div>
                    <div class="p-3 bg-white rounded-3 shadow-xs border-start border-4 border-gold">
                        <div class="fw-bold text-dark small mb-1">Investidor</div>
                        <div class="text-muted smaller">Acesso a relatórios de performance, fatos relevantes e demonstrações financeiras da série.</div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-7">
                <div class="bg-white p-4 rounded-4 shadow-sm border border-brand-subtle">
                    <div class="text-center mb-4">
                        <span class="badge bg-light text-dark border px-3 py-1 rounded-pill smaller fw-bold">Simulação de Repositório ACL</span>
                    </div>
                    
                    <div class="row g-3">
                        <!-- Perfil 1 -->
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 border bg-light h-100">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <div class="bg-brand text-white rounded-circle p-1"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
                                    <span class="smaller fw-bold text-brand">Agente Fiduciário</span>
                                </div>
                                <div class="d-flex flex-column gap-2">
                                    <div class="d-flex align-items-center gap-2 smaller text-dark bg-white p-2 rounded border shadow-xs">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg>
                                        Escritura e Aditamentos
                                    </div>
                                    <div class="d-flex align-items-center gap-2 smaller text-dark bg-white p-2 rounded border shadow-xs text-brand fw-bold">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg>
                                        Dossiê de Garantias
                                    </div>
                                    <div class="d-flex align-items-center gap-2 smaller text-muted opacity-50 p-2">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        Dados Sensíveis Sacados
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Perfil 2 -->
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 border bg-light h-100">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <div class="bg-gold text-white rounded-circle p-1"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
                                    <span class="smaller fw-bold text-dark">Investidor Qualificado</span>
                                </div>
                                <div class="d-flex flex-column gap-2">
                                    <div class="d-flex align-items-center gap-2 smaller text-dark bg-white p-2 rounded border shadow-xs">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg>
                                        Relatórios de Performance
                                    </div>
                                    <div class="d-flex align-items-center gap-2 smaller text-dark bg-white p-2 rounded border shadow-xs">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg>
                                        Fatos Relevantes
                                    </div>
                                    <div class="d-flex align-items-center gap-2 smaller text-muted opacity-50 p-2">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        Contratos Sociais Emissor
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Gestão de permissões na prática -->
<section class="py-5 bg-white border-top">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2">
                <h2 class="h3 fw-bold text-dark mb-4">Gestão ativa do ciclo de vida documental</h2>
                <p class="text-muted mb-4 lead">
                    O controle de acesso acompanha a evolução da operação. Do closing à liquidação, cada mudança de mandato ou decisão de compliance é refletida instantaneamente no sistema, com registros permanentes de cada alteração.
                </p>
                <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium"><strong>Setup de Estruturação:</strong> Definição imediata de perfis para agentes, investidores e distribuidores, revisados a cada novo evento contratual.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium"><strong>Data Room Seguro:</strong> Suporte total à troca de minutas, documentos de lastro e due diligence jurídica entre advogados e emissores desde o "Dia Zero".</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium"><strong>Atualização de Mandatos:</strong> Revogação ou concessão imediata de acessos em casos de substituição de agentes, garantindo que apenas os atuais responsáveis visualizem os dados.</span>
                    </li>
                    <li class="d-flex align-items-start gap-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        <span class="text-dark fw-medium"><strong>Workflow de Aprovação:</strong> Fluxo rigoroso de validação pelo compliance para novos acessos, com notificação automática e ativação documentada.</span>
                    </li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1">
                <div style="background: url('{{ asset('images/documentos_acl.png') }}') center/cover; height: 400px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);"></div>
            </div>
        </div>
    </div>
</section>

<!-- Serviços relacionados -->
<section class="py-5 border-top" style="background-color: var(--bg);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Serviços relacionados</h2>
            <p class="text-muted mx-auto" style="max-width: 560px;">O controle de acesso documental conecta-se diretamente à auditoria de acessos e ao portal onde as permissões têm efeito para o investidor.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <a href="{{ route('site.servicos.auditoria-acessos') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Auditoria de Acessos</h3>
                    <p class="text-muted mb-3">Rastreabilidade completa dos acessos ao ambiente operacional — log de ações, revisão de permissões e conformidade com as políticas de segregação definidas no compliance.</p>
                    <span class="small fw-semibold" style="color: var(--brand);">Saiba mais →</span>
                </a>
            </div>

            <div class="col-md-5">
                <a href="{{ route('site.servicos.portal-investidor') }}" class="card card-opea h-100 p-4 border-0 shadow-sm card-hover text-decoration-none" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-2" style="color: #0b1220;">Portal do Investidor</h3>
                    <p class="text-muted mb-3">Ambiente onde as permissões de ACL têm efeito — cada investidor visualiza apenas as operações e documentos autorizados para o seu perfil de acesso.</p>
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

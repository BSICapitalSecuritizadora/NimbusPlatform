@extends('site.layout')

@section('title', 'Compliance — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.12; background: url('{{ asset('images/compliance.png') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Compliance</span> e Ética Corporativa
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Nossa estrutura de compliance reúne políticas, controles internos, diligências e rotinas de acompanhamento para apoiar a conduta ética, a prevenção a riscos (PLD/FTP), a proteção de dados (LGPD) e a aderência às normas aplicáveis ao mercado de capitais, com foco em operações de securitização como CRI, CRA e CR.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="#politicas" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Conhecer políticas de compliance
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
                        <img src="{{ asset('images/compliance.png') }}" class="img-fluid" alt="Compliance & Ética Corporativa" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Compliance ativo</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">KYC · PLD/FTP · LGPD</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Selos e Certificações -->
<section class="py-4" style="background: #f8f9fa; border-bottom: 1px solid rgba(0,32,91,0.05);">
    <div class="container">
        <div class="row align-items-center justify-content-center g-4 opacity-75">
            <div class="col-6 col-md-3 text-center">
                <div class="d-flex flex-column align-items-center">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mb-2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <span class="small fw-bold text-uppercase text-dark" style="letter-spacing: 0.05em; font-size: 0.7rem;">Compliance S.A.</span>
                </div>
            </div>
            <div class="col-6 col-md-3 text-center">
                <div class="d-flex flex-column align-items-center">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mb-2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span class="small fw-bold text-uppercase text-dark" style="letter-spacing: 0.05em; font-size: 0.7rem;">Auditoria Ativa</span>
                </div>
            </div>
            <div class="col-6 col-md-3 text-center">
                <div class="d-flex flex-column align-items-center">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mb-2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <span class="small fw-bold text-uppercase text-dark" style="letter-spacing: 0.05em; font-size: 0.7rem;">CVM & ANBIMA</span>
                </div>
            </div>
            <div class="col-6 col-md-3 text-center">
                <div class="d-flex flex-column align-items-center">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mb-2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <span class="small fw-bold text-uppercase text-dark" style="letter-spacing: 0.05em; font-size: 0.7rem;">LGPD Compliant</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Pilares Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Frentes de atuação do Compliance</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nossa atuação é guiada por princípios de conduta ética. Trabalhamos para apoiar a mitigação de riscos e a aderência da nossa operação de securitizadora às boas práticas do mercado de capitais.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Prevenção a riscos e PLD/FTP</h3>
                    <p class="text-muted mb-0">Controles internos e diligências orientados para prevenir irregularidades, apoiando a integridade das operações e as comunicações aplicáveis.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Conduta ética</h3>
                    <p class="text-muted mb-0">Diretrizes e Código de Ética que orientam o comportamento da nossa equipe, promovendo transparência e prestação de contas nas relações corporativas.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Aderência regulatória</h3>
                    <p class="text-muted mb-0">Acompanhamento das resoluções da CVM e diretrizes da ANBIMA para apoiar a conformidade estrutural em cada etapa aplicável.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Privacidade e proteção de dados</h3>
                    <p class="text-muted mb-0">Governança de dados pessoais e de segurança da informação institucionais em conformidade com as diretrizes da LGPD.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Cultura Ética e Código de Conduta -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5">
                <div class="p-5" style="background: var(--brand-strong); border-radius: 24px; box-shadow: 0 20px 40px rgba(0,32,91,0.15);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="1.5" class="mb-4"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <h2 class="h3 fw-bold mb-3" style="color: #fff;">Nosso Compromisso Ético</h2>
                    <p style="color: #8892b0; line-height: 1.7;">Nosso Código de Ética é o referencial que orienta a conduta profissional, prevenindo conflitos de interesse no relacionamento com clientes, parceiros e investidores, e apoiando o registro e tratamento adequado de situações sensíveis, com responsabilização conforme as políticas internas da companhia.</p>
                    <a href="{{ route('public-documents') }}" class="btn btn-brand mt-3 d-inline-flex align-items-center gap-2">
                        Leitura Completa
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="7" y1="17" x2="17" y2="7"></line><polyline points="7 7 17 7 17 17"></polyline></svg>
                    </a>
                </div>
            </div>
            <div class="col-lg-7">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91,0.05); letter-spacing: 0.1em; font-weight: 600;">Consciência & Prevenção</span>
                <h2 class="h3 fw-bold text-dark mb-4">Cultura de integridade e prevenção</h2>
                <p class="text-muted mb-4">Investimos na conscientização da nossa equipe e na comunicação interna de diretrizes, visando uma governança sólida por meio da difusão de boas práticas aplicáveis.</p>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-brand mt-1">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1" style="font-size: 1rem; color: #0b1220;">Onboarding Ético</h5>
                                <p class="small text-muted mb-0">Cada novo integrante recebe orientações essenciais sobre nossos valores institucionais e diretrizes do Código de Ética e Conduta.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-brand mt-1">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-1" style="font-size: 1rem; color: #0b1220;">Capacitação Contínua</h5>
                                <p class="small text-muted mb-0">Buscamos manter a equipe atualizada sobre políticas internas, suitability, PLD/FTP, LGPD, segurança da informação e mudanças regulatórias.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Políticas Section -->
<section class="compliance-documents-section">
    <div class="compliance-documents-container">
        <div class="compliance-documents-header">
            <h2 id="politicas" class="compliance-documents-title h3 fw-bold mb-3">Políticas e Documentos</h2>
            <p class="compliance-documents-description mb-0">Consulte políticas, manuais e documentos institucionais que sustentam as práticas de compliance, governança, segurança da informação e controles internos da BSI Capital.</p>
        </div>

        <div class="compliance-documents-grid">
            @php
                $expectedDocs = [
                    'Política de Privacidade de Dados',
                    'Segurança da Informação e Cibersegurança',
                    'Política de Suitability',
                    'Manual de Controles Internos',
                    'Código de Ética'
                ];
            @endphp

            @foreach($expectedDocs as $docTitle)
                @php
                    $dbDoc = $documents->firstWhere('title', $docTitle);
                @endphp
                <div class="compliance-document-card">
                    <div>
                        <div class="compliance-document-card__header mb-3">
                            <div class="compliance-document-card__icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            </div>
                            <h4 class="compliance-document-card__title mb-0 fs-5">{{ $docTitle }}</h4>
                        </div>
                        @if($dbDoc)
                        <div class="compliance-document-card__meta">
                            {{ $dbDoc->published_at?->format('d/m/Y') ?? $dbDoc->created_at->format('d/m/Y') }}
                            @if($dbDoc->file_size)
                                · {{ number_format($dbDoc->file_size / 1024, 0) }} KB
                            @endif
                        </div>
                        @else
                        <div class="compliance-document-card__meta">Em validação</div>
                        @endif
                    </div>
                    
                    <div class="compliance-document-card__footer">
                        @if($dbDoc)
                        <a href="{{ route('site.documents.download', $dbDoc) }}" class="compliance-document-card__link d-flex justify-content-between align-items-center w-100" download>
                            <span>Acessar arquivo</span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </a>
                        @else
                        <span class="compliance-document-card__link d-flex justify-content-between align-items-center w-100" style="color: rgba(9, 27, 35, 0.4); pointer-events: none;">
                            <span>Indisponível</span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </span>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- Other published documents --}}
            @foreach($documents as $document)
                @if(!in_array($document->title, $expectedDocs))
                <div class="compliance-document-card">
                    <div>
                        <div class="compliance-document-card__header mb-3">
                            <div class="compliance-document-card__icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            </div>
                            <h4 class="compliance-document-card__title mb-0 fs-5">{{ $document->title }}</h4>
                        </div>
                        <div class="compliance-document-card__meta">
                            {{ $document->published_at?->format('d/m/Y') ?? $document->created_at->format('d/m/Y') }}
                            @if($document->file_size)
                                · {{ number_format($document->file_size / 1024, 0) }} KB
                            @endif
                        </div>
                    </div>
                    
                    <div class="compliance-document-card__footer">
                        <a href="{{ route('site.documents.download', $document) }}" class="compliance-document-card__link d-flex justify-content-between align-items-center w-100" download>
                            <span>Acessar arquivo</span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </a>
                    </div>
                </div>
                @endif
            @endforeach
        </div>
    </div>
</section>

<!-- Canal de Denúncia -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Canal de Integridade</span>
                <h2 class="h3 fw-bold text-dark mb-3">Canal de Integridade e Denúncia</h2>
                <p class="text-muted mb-4">Possuímos um canal institucional para relatos, com tratamento confidencial e possibilidade de anonimato conforme o meio disponível e as políticas internas.</p>
                <p class="text-muted mb-4">Os relatos são tratados conforme procedimento interno, com segregação de responsabilidades e restrição de acesso às informações, quando aplicável.</p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand d-inline-flex align-items-center gap-2 px-4 py-2">
                    Acessar Canal de Integridade
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 p-5" style="background: linear-gradient(135deg, rgba(0,32,91,0.05), rgba(212,175,55,0.05)); border-radius: 20px;">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 52px; height: 52px; background: rgba(0,32,91,0.08); color: var(--brand);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </div>
                        <h4 class="fw-bold mb-0" style="color: var(--brand);">Protocolo de Sigilo</h4>
                    </div>
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span class="text-muted" style="font-size: 0.95rem;"><strong class="text-dark">Recebimento e triagem</strong> — relato registrado e encaminhado para avaliação conforme procedimento interno.</span>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span class="text-muted" style="font-size: 0.95rem;"><strong class="text-dark">Apuração e tratamento</strong> — análise conduzida com acesso restrito às informações e segregação dos envolvidos, quando aplicável.</span>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span class="text-muted" style="font-size: 0.95rem;"><strong class="text-dark">Encerramento formal</strong> — conclusão registrada, com documentação das tratativas e medidas aplicáveis, quando cabíveis.</span>
                        </div>
                    </div>
                </div>
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

    .compliance-documents-section {
        background: #E6E4E4;
        color: #091B23;
        padding: 96px 0;
    }

    .compliance-documents-container {
        width: min(1120px, calc(100% - 48px));
        margin: 0 auto;
    }

    .compliance-documents-header {
        max-width: 680px;
        margin: 0 auto 48px;
        text-align: center;
    }

    .compliance-documents-title {
        color: #091B23;
    }

    .compliance-documents-description {
        color: rgba(9, 27, 35, 0.72);
        line-height: 1.6;
    }

    .compliance-documents-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 24px;
        align-items: stretch;
    }

    .compliance-document-card {
        min-width: 0;
        min-height: 160px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: #FFFFFF;
        border: 1px solid rgba(9, 27, 35, 0.10);
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 16px 34px rgba(9, 27, 35, 0.06);
        transition: border-color 180ms ease, transform 180ms ease, box-shadow 180ms ease;
    }

    .compliance-document-card:hover {
        border-color: rgba(160, 110, 40, 0.42);
        transform: translateY(-2px);
        box-shadow: 0 20px 42px rgba(9, 27, 35, 0.09);
    }

    .compliance-document-card__header {
        display: flex;
        align-items: flex-start;
        gap: 14px;
    }

    .compliance-document-card__icon {
        width: 34px;
        height: 34px;
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #A06E28;
        background: rgba(160, 110, 40, 0.08);
        border: 1px solid rgba(160, 110, 40, 0.22);
    }

    .compliance-document-card__title {
        color: #091B23;
        font-weight: 700;
        line-height: 1.25;
        overflow-wrap: normal;
        word-break: normal;
        hyphens: none;
    }

    .compliance-document-card__meta {
        margin-top: 18px;
        color: rgba(9, 27, 35, 0.58);
        font-size: 0.875rem;
        white-space: nowrap;
    }

    .compliance-document-card__footer {
        margin-top: 22px;
        padding-top: 16px;
        border-top: 1px solid rgba(9, 27, 35, 0.08);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }

    .compliance-document-card__link {
        color: #A06E28;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }

    .compliance-document-card__link:hover {
        color: #091B23;
    }

    @media (max-width: 1024px) {
        .compliance-documents-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .compliance-documents-container {
            width: min(100% - 32px, 1120px);
        }

        .compliance-documents-grid {
            grid-template-columns: 1fr;
        }

        .compliance-document-card__meta,
        .compliance-document-card__link {
            white-space: normal;
        }
    }
</style>
@endpush
@endsection

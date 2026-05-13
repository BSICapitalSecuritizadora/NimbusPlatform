@extends('site.layout')

@section('title', 'Compliance — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.12; background: url('{{ asset('images/compliance.png') }}') center/cover; mix-blend-mode: luminosity;"></div>
    
    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Institucional</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Compliance</span> <br>& Ética Corporativa
                </h1>
                <p class="lead mb-5" style="color: #a5b4fc; max-width: 90%;">
                    O Programa de Compliance da BSI Capital constitui o alicerce de nossa atuação fiduciária. Transcendendo o cumprimento normativo, reflete uma cultura disseminada em todos os níveis da organização, assegurando integridade absoluta e segurança no relacionamento com o mercado.
                </p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                    Falar com Especialista
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Pilares Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Pilares do Nosso Compliance</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">O Programa estrutura-se sobre pilares fundamentais que reforçam a conduta ética, a mitigação de riscos e a conformidade irrestrita aos referenciais normativos.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">PLD / FTP</h3>
                    <p class="text-muted mb-0">Estrutura robusta de Prevenção à Lavagem de Dinheiro e ao Financiamento do Terrorismo, com protocolos rigorosos de diligência e monitoramento.</p>
                </div>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Código de Conduta</h3>
                    <p class="text-muted mb-0">Estabelece diretrizes de comportamento ético e integridade esperados de colaboradores e parceiros em todas as interações institucionais.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Conformidade CVM</h3>
                    <p class="text-muted mb-0">Aderência irrestrita às Resoluções da CVM, garantindo conformidade normativa em todas as fases de estruturação e gestão de ativos.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Privacidade (LGPD)</h3>
                    <p class="text-muted mb-0">Tratamento de dados sob rigorosos padrões de segurança cibernética e sigilo fiduciário, em estrita observância à LGPD.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Políticas Section -->
<section class="py-5" style="background: #0b1220;">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold mb-3" style="color: #ffffff;">Políticas e Documentos</h2>
            <p class="mx-auto" style="max-width: 600px; color: #a5b4fc;">Consulte os normativos institucionais que regem nossas diretrizes de governança, conduta ética e conformidade regulatória.</p>
        </div>

        <div class="row g-4 justify-content-center">
            @forelse($documents as $document)
            <div class="col-md-6 col-lg-4">
                <a href="{{ Storage::disk($document->resolved_storage_disk)->url($document->file_path) }}" target="_blank" class="text-decoration-none" download>
                    <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.05); border-radius: 16px; transition: .3s;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                            <h4 class="fw-bold mb-0" style="color: #fff; font-size: 1rem;">{{ $document->title }}</h4>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span style="color: #8892b0; font-size: 0.85rem;">
                                {{ $document->published_at?->format('d/m/Y') ?? $document->created_at->format('d/m/Y') }}
                                @if($document->file_size)
                                    · {{ number_format($document->file_size / 1024, 0) }} KB
                                @endif
                            </span>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        </div>
                    </div>
                </a>
            </div>
            @empty
            <div class="col-12 text-center py-4">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="1.5" class="mb-3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                <p class="mb-0" style="color: #8892b0;">Os documentos normativos de compliance estarão disponíveis nesta seção após sua publicação oficial.</p>
            </div>
            @endforelse
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
                <p class="text-muted mb-4">A BSI Capital disponibiliza um Canal de Integridade independente para o relato seguro de desvios de conduta, irregularidades ou descumprimento de normas. Asseguramos o anonimato absoluto e mantemos uma política rigorosa de não retaliação ao denunciante de boa-fé.</p>
                <p class="text-muted mb-4">Os relatos são processados pelo Comitê de Compliance, garantindo imparcialidade, sigilo e o devido rito de apuração independente.</p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand d-inline-flex align-items-center gap-2 px-4 py-2">
                    Acessar Canal
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 p-5 text-center" style="background: linear-gradient(135deg, rgba(0,32,91,0.05), rgba(212,175,55,0.05)); border-radius: 20px;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mx-auto mb-4"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <h4 class="fw-bold mb-2" style="color: var(--brand);">Protocolo de Sigilo</h4>
                    <p class="text-muted mb-0">Todas as manifestações são tratadas sob estrito sigilo e em conformidade com os protocolos de apuração interna da companhia.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@extends('site.layout')

@section('title', 'Compliance — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: #001233;">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.12; background: url('{{ asset('images/compliance.png') }}') center/cover; mix-blend-mode: luminosity;"></div>
    
    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">A BSI</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Compliance</span> <br>& Ética Corporativa
                </h1>
                <p class="lead mb-5" style="color: #a5b4fc; max-width: 90%;">
                    A BSI Capital mantém os mais altos padrões de integridade, transparência e ética em todas as suas operações. Nosso compromisso com a conformidade regulatória é parte do nosso DNA.
                </p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                    Falar com o Compliance
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
            <p class="text-muted mx-auto" style="max-width: 600px;">Estruturamos nosso programa de conformidade sobre três pilares fundamentais, alinhados às melhores práticas do mercado financeiro.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">PLD / FT</h3>
                    <p class="text-muted mb-0">Programa robusto de Prevenção à Lavagem de Dinheiro e ao Financiamento do Terrorismo, com processos de KYC (Conheça seu Cliente), monitoramento contínuo de transações e reportes ao COAF.</p>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Código de Conduta</h3>
                    <p class="text-muted mb-0">Nosso Código de Conduta reflete nossos valores fundamentais e estabelece os padrões de comportamento ético esperados de todos os colaboradores, parceiros e prestadores de serviço.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Conformidade CVM</h3>
                    <p class="text-muted mb-0">Atuação em total conformidade com as instruções e resoluções da CVM, incluindo CVM 60, CVM 160 e demais normativos aplicáveis ao mercado de securitização.</p>
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
            <p class="mx-auto" style="max-width: 600px; color: #a5b4fc;">Acesse nossas políticas institucionais de governança e conformidade.</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.05); border-radius: 16px;">
                    <div class="mb-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #fff; font-size: 1rem;">Política de PLD/FT</h4>
                    <p class="mb-0" style="color: #8892b0; font-size: 0.9rem;">Diretrizes de prevenção à lavagem de dinheiro e combate ao financiamento do terrorismo.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.05); border-radius: 16px;">
                    <div class="mb-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #fff; font-size: 1rem;">Código de Conduta</h4>
                    <p class="mb-0" style="color: #8892b0; font-size: 0.9rem;">Princípios éticos e regras de comportamento para colaboradores e parceiros.</p>
                </div>
            </div>

            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-4 border-0" style="background: rgba(255,255,255,0.05); border-radius: 16px;">
                    <div class="mb-3">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #fff; font-size: 1rem;">Política de Conflitos de Interesse</h4>
                    <p class="mb-0" style="color: #8892b0; font-size: 0.9rem;">Diretrizes para identificação, gestão e mitigação de conflitos de interesse.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Canal de Denúncia -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Canal de Denúncia</span>
                <h2 class="h3 fw-bold text-dark mb-3">Canal de Ética e Denúncia</h2>
                <p class="text-muted mb-4">A BSI Capital disponibiliza um canal de denúncia confidencial e anônimo para receber relatos de condutas antiéticas, irregularidades ou descumprimento de normas internas e regulatórias.</p>
                <p class="text-muted mb-4">Todas as denúncias são tratadas com sigilo absoluto e investigadas pelo Comitê de Compliance da companhia.</p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand d-inline-flex align-items-center gap-2 px-4 py-2">
                    Enviar Relato
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
            <div class="col-lg-6">
                <div class="card border-0 p-5 text-center" style="background: linear-gradient(135deg, rgba(0,32,91,0.05), rgba(212,175,55,0.05)); border-radius: 20px;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="1.5" class="mx-auto mb-4"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <h4 class="fw-bold mb-2" style="color: var(--brand);">Confidencial & Anônimo</h4>
                    <p class="text-muted mb-0">Seus dados e relatos são protegidos por criptografia e anonimização.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

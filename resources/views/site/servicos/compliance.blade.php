@extends('site.layout')

@section('title', 'Compliance — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: #001233;">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/compliance.png') }}') center/cover; mix-blend-mode: luminosity;"></div>
    
    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Gestão</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Compliance</span> <br>& Governança
                </h1>
                <p class="lead mb-5" style="color: #a5b4fc; max-width: 90%;">
                    Programa de compliance robusto que assegura a conformidade com a legislação vigente, as normas da CVM e as melhores práticas do mercado de securitização.
                </p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                    Falar com Especialistas
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
            
            <div class="col-lg-6 d-none d-lg-block">
                <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                    <img src="{{ asset('images/compliance.png') }}" class="img-fluid" alt="Compliance & Governança" style="width: 100%; height: 500px; object-fit: cover;">
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Integridade em Cada Operação</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Nosso programa de compliance garante que todas as operações sejam conduzidas com ética, transparência e responsabilidade.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">PLD / FT</h3>
                    <p class="text-muted mb-0">Prevenção à Lavagem de Dinheiro e ao Financiamento do Terrorismo com processos de KYC e monitoramento contínuo de transações.</p>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Código de Ética</h3>
                    <p class="text-muted mb-0">Código de conduta e políticas internas que regem o comportamento de todos os colaboradores e parceiros da BSI Capital.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Monitoramento de Covenants</h3>
                    <p class="text-muted mb-0">Controle ativo dos indicadores financeiros e gatilhos contratuais, assegurando o cumprimento das obrigações em cada emissão.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

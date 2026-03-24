@extends('site.layout')

@section('title', 'Cooperativas — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: #001233;">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/cooperativas_agro.png') }}') center/cover; mix-blend-mode: luminosity;"></div>
    
    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Agronegócio</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Financiamento para <br><span style="color: var(--gold);">Cooperativas</span>
                </h1>
                <p class="lead mb-5" style="color: #a5b4fc; max-width: 90%;">
                    Soluções financeiras estruturadas para potencializar o crescimento de cooperativas agropecuárias, garantindo liquidez e competitividade aos cooperados.
                </p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                    Falar com Especialistas
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
            
            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/cooperativas_agro.png') }}" class="img-fluid" alt="Cooperativas do Agronegócio" style="width: 100%; height: 500px; object-fit: cover;">
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
            <h2 class="h3 fw-bold text-dark mb-3">Modelos de Capitalização</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Operações ágeis e desenhadas para a dinâmica única do sistema cooperativista brasileiro.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Antecipação de Recebíveis</h3>
                    <p class="text-muted mb-0">Transformação de duplicatas e recebíveis futuros de cooperados em capital de giro imediato via mercado de capitais.</p>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Estruturação Segura</h3>
                    <p class="text-muted mb-0">Proteção mútua através de garantias coobrigadas ou fundo garantidor próprio da cooperativa, minimizando spread bancário.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Gestão Operacional</h3>
                    <p class="text-muted mb-0">Monitoramento e inteligência financeira para controle do fluxo de caixa e gestão ativa de safras e armazenamento.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

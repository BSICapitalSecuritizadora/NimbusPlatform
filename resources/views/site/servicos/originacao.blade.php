@extends('site.layout')

@section('title', 'Originação — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: #001233;">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/originacao.png') }}') center/cover; mix-blend-mode: luminosity;"></div>
    
    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Estruturação</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    <span style="color: var(--gold);">Originação</span> <br>de Operações
                </h1>
                <p class="lead mb-5" style="color: #a5b4fc; max-width: 90%;">
                    <strong>Transformamos ativos e projetos em teses de investimento viáveis.</strong><br>
                    Identificamos a melhor estratégia de captação logo no ponto de partida, garantindo que o desenho da operação seja aderente ao perfil do emissor e ao apetite do mercado.
                </p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                    Consultar Especialista
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
            
            <div class="col-lg-6 d-none d-lg-block">
                <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                    <img src="{{ asset('images/originacao.png') }}" class="img-fluid" alt="Originação" style="width: 100%; height: 500px; object-fit: cover;">
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Benefícios Section -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <h2 class="h3 fw-bold text-dark mb-3">Originação Estratégica</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">A fase inicial define o sucesso da captação. Atuamos na leitura precisa do ativo e do emissor para garantir que a estrutura avance com segurança e consistência.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Validação de Teses</h3>
                    <p class="text-muted mb-0">Avaliamos o enquadramento real da operação e a qualidade dos ativos, filtrando os principais direcionadores de viabilidade antes de avançar para a estruturação.</p>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Inteligência Financeira</h3>
                    <p class="text-muted mb-0">Projetamos fluxos e cenários de estresse para calibrar prazo, custo e amortização, garantindo que a estrutura suporte a capacidade de pagamento.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-opea h-100 p-4 border-0 shadow-sm card-hover" style="transition: .3s;">
                    <div class="mb-4 d-inline-flex align-items-center justify-content-center bg-light rounded-circle" style="width: 60px; height: 60px; color: var(--brand);">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <h3 class="h5 fw-bold mb-3" style="color: #0b1220;">Posicionamento de Mercado</h3>
                    <p class="text-muted mb-0">Preparamos a tese para o diálogo com investidores, focando na consistência comercial e na previsibilidade da oferta futura.</p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

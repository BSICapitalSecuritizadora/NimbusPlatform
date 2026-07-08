@extends('site.layout')

@section('title', 'Canal de Ética — BSI Capital')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 50vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.12; background: url('{{ asset('images/compliance2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center justify-content-center text-center g-5">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">INTEGRIDADE E CONFIANÇA</span>
                <h1 class="display-4 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Canal de Ética
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4;">
                    O Canal de Ética da BSI Capital é destinado ao relato de situações que possam representar violações éticas, condutas inadequadas ou descumprimento de políticas institucionais e regulatórias.
                </p>
                <div class="d-flex justify-content-center">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Acessar Canal de Integridade
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Sobre o Canal -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <h2 class="h3 fw-bold text-dark mb-4">Sobre o Canal</h2>
                <p class="text-muted mb-3" style="line-height: 1.7;">Acreditamos que a integridade é o alicerce de relações duradouras no mercado financeiro e de capitais. O Canal de Ética foi desenvolvido para garantir que nossos colaboradores, clientes e parceiros tenham uma via segura e estruturada para comunicar preocupações legítimas.</p>
                <p class="text-muted" style="line-height: 1.7;">Ele reflete o nosso compromisso com a conformidade e apoia diretamente a identificação e mitigação de riscos, assegurando um ambiente corporativo pautado pela ética.</p>
            </div>
            <div class="col-lg-6">
                <div class="card card-opea border-0 p-4 shadow-sm h-100" style="border-radius: 16px;">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle flex-shrink-0" style="width: 50px; height: 50px; color: var(--brand);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        </div>
                        <div>
                            <h4 class="fw-bold fs-5 mb-2" style="color: #0b1220;">Confidencialidade e Proteção</h4>
                            <p class="text-muted mb-0 small" style="line-height: 1.6;">O tratamento dos relatos é conduzido com o máximo de confidencialidade permitido pelas nossas políticas e legislações aplicáveis. Possuímos um ambiente focado na imparcialidade e proteção contra retaliações, conforme as diretrizes internas da BSI Capital.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- O que pode ser relatado -->
<section class="py-5" style="background-color: #f8f9fa;">
    <div class="container py-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-3">O que pode ser relatado</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Este canal é focado no reporte de condutas sensíveis e desalinhadas aos nossos princípios. Situações passíveis de relato incluem:</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-md-6 col-lg-3">
                <div class="card border-0 shadow-sm p-4 h-100 card-hover" style="border-radius: 16px; transition: .3s;">
                    <h5 class="fw-bold mb-2 fs-6" style="color: var(--brand);">Conflitos de Interesse</h5>
                    <p class="text-muted small mb-0">Situações em que o interesse pessoal possa interferir nas obrigações com a companhia ou seus clientes.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card border-0 shadow-sm p-4 h-100 card-hover" style="border-radius: 16px; transition: .3s;">
                    <h5 class="fw-bold mb-2 fs-6" style="color: var(--brand);">Desvios de Conduta</h5>
                    <p class="text-muted small mb-0">Comportamentos como assédio, discriminação, práticas de corrupção ou uso indevido de recursos e informações.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="card border-0 shadow-sm p-4 h-100 card-hover" style="border-radius: 16px; transition: .3s;">
                    <h5 class="fw-bold mb-2 fs-6" style="color: var(--brand);">Violação de Políticas</h5>
                    <p class="text-muted small mb-0">Descumprimento deliberado do nosso Código de Ética, normas de Compliance ou diretrizes operacionais aplicáveis.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Como Funciona -->
<section class="py-5" style="background-color: var(--bg);">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-2 order-lg-1">
                <div class="card border-0 p-5" style="background: linear-gradient(135deg, rgba(0,32,91,0.05), rgba(212,175,55,0.05)); border-radius: 20px;">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 52px; height: 52px; background: rgba(0,32,91,0.08); color: var(--brand);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        </div>
                        <h4 class="fw-bold mb-0" style="color: var(--brand);">Tratamento de Relatos</h4>
                    </div>
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span class="text-muted" style="font-size: 0.95rem;"><strong class="text-dark">1. Recebimento e triagem</strong> — relato registrado e encaminhado para avaliação conforme procedimento interno.</span>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span class="text-muted" style="font-size: 0.95rem;"><strong class="text-dark">2. Apuração e tratamento</strong> — análise conduzida com acesso restrito às informações e segregação dos envolvidos, quando aplicável.</span>
                        </div>
                        <div class="d-flex align-items-start gap-3">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-1 flex-shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            <span class="text-muted" style="font-size: 0.95rem;"><strong class="text-dark">3. Encerramento formal</strong> — conclusão registrada, com documentação das tratativas e medidas aplicáveis, quando cabíveis.</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 order-1 order-lg-2">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--brand); color: var(--brand); background: rgba(0,32,91,0.05); letter-spacing: 0.1em; font-weight: 600;">Procedimento</span>
                <h2 class="h3 fw-bold text-dark mb-4">Como funciona o processo</h2>
                <p class="text-muted mb-4">Ao submeter uma manifestação, nossa equipe de controles e integridade inicia um protocolo formal focado na imparcialidade da apuração.</p>
                <p class="text-muted">Nosso compromisso não é apenas receber o relato, mas assegurar que cada caso seja devidamente mapeado, avaliado e acompanhado até o seu desfecho, retroalimentando as ações de prevenção corporativa.</p>
            </div>
        </div>
    </div>
</section>

<!-- CTA Final -->
<section class="py-5 mb-5">
    <div class="container">
        <div class="card border-0 p-5 text-center shadow-lg" style="background: var(--brand-strong); border-radius: 24px; position: relative; overflow: hidden;">
            <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.05; background: url('{{ asset('images/compliance.jpg') }}') center/cover;"></div>
            <div class="position-relative z-1">
                <h2 class="h3 fw-bold mb-3" style="color: #fff;">Precisa registrar um relato?</h2>
                <p class="mb-4 mx-auto" style="color: #8892b0; max-width: 500px;">Sua atitude é fundamental para manter um ambiente ético e seguro. Utilize o botão abaixo para se manifestar de forma estruturada.</p>
                <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center gap-2">
                    Acessar o Canal de Ética
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>
        </div>
    </div>
</section>

@endsection

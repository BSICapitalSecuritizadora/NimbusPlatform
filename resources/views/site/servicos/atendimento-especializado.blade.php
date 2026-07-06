@extends('site.layout')

@section('title', 'Atendimento Especializado | BSI Capital')

@section('uses_flux', '1')

@section('content')
<!-- Hero Section -->
<section class="hero position-relative d-flex align-items-center" style="min-height: 60vh; overflow: hidden; background: var(--brand-strong);">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.15; background: url('{{ asset('images/atendimento2.jpg') }}') center/cover; mix-blend-mode: luminosity;"></div>

    <div class="container position-relative z-1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge mb-3 px-3 py-2 text-uppercase" style="border: 1px solid var(--gold); color: var(--gold); background: rgba(212,175,55, 0.1); letter-spacing: 0.1em; font-weight: 600;">Gestão</span>
                <h1 class="display-3 fw-bold mb-4" style="color: #ffffff; letter-spacing: -0.02em;">
                    Atendimento <br><span style="color: var(--gold);">Especializado</span>
                </h1>
                <p class="lead mb-5" style="color: #E6E4E4; max-width: 90%;">
                    Muito além do suporte técnico. Acompanhamento fiduciário proativo e alinhamento próximo para garantir a saúde das operações ao longo de todo o ciclo de vida.
                </p>
                <div class="d-grid gap-3 d-sm-flex justify-content-sm-start">
                    <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg d-inline-flex align-items-center justify-content-center gap-2 px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                        Falar com a equipe de gestão
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                </div>
            </div>

            <div class="col-lg-6 d-none d-lg-block">
                <div class="position-relative">
                    <div style="border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
                        <img src="{{ asset('images/atendimento.jpg') }}" class="img-fluid" alt="Atendimento Especializado" style="width: 100%; height: 500px; object-fit: cover;">
                    </div>
                    <div class="position-absolute bg-white px-4 py-3 rounded-4 shadow-lg d-flex align-items-center gap-3" style="bottom: -20px; left: -30px; animation: float 6s ease-in-out infinite;">
                        <div class="bg-light p-3 rounded-circle text-primary" style="background: rgba(9,27,35,0.1) !important; color: #091b23 !important;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                        </div>
                        <div>
                            <div class="text-muted small fw-medium">Relacionamento</div>
                            <div class="fw-bold fs-5" style="color: #0b1220;">Gestão Proativa</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Visão Geral -->
<section class="py-5" style="background-color: #ffffff;">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Visão Geral do Serviço</span>
                <h2 class="h3 fw-bold text-dark mb-4">Proximidade que antecipa demandas operacionais</h2>
                <p class="text-muted mb-4 lead">
                    Operações estruturadas complexas exigem monitoramento contínuo. Nosso Atendimento Especializado não é apenas uma central de chamados, mas um time dedicado a entender os meandros de cada ativo.
                </p>
                <p class="text-muted mb-4">
                    Da resolução de pendências documentais até o alinhamento sobre waivers e amortizações extraordinárias, a equipe atua como o elo de confiança entre o emissor, estruturador e os investidores institucionais.
                </p>
            </div>
            <div class="col-lg-6">
                <div class="row g-4">
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Time Qualificado</h3>
                            <p class="small text-muted mb-0">Especialistas com vivência real em estruturação e rotinas fiduciárias e regulatórias.</p>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 shadow-sm border h-100" style="background: #f8f9fa;">
                            <h3 class="h6 fw-bold text-brand mb-3">Acompanhamento</h3>
                            <p class="small text-muted mb-0">Respostas ágeis que mitigam problemas e protegem a reputação da operação.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Público-Alvo Section -->
<section class="py-5 bg-light border-bottom">
    <div class="container py-5">
        <div class="text-center mb-5 pb-3">
            <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Público-Alvo</span>
            <h2 class="h3 fw-bold text-dark mb-3">Para quem este serviço é desenhado</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">Desenvolvemos o escopo de atendimento fiduciário com foco nos principais atores de operações emitidas no mercado.</p>
        </div>
        <div class="row g-4 justify-content-center">
            @foreach([
                ['Emissores e Cedentes', 'Que precisam de suporte para a manutenção das garantias, reportes e documentação contínua.'],
                ['Gestores de FIIs e Fiagros', 'Que demandam informações ágeis e respostas claras sobre a saúde dos ativos das suas carteiras.'],
                ['Estruturadores e Assessores', 'Que valorizam parceiros ágeis para a resolução de entraves durante o ciclo da operação.'],
                ['Investidores Qualificados', 'Que requerem transparência e alinhamento sobre as ações fiduciárias ao longo da vida do título.']
            ] as $target)
            <div class="col-md-6 col-lg-3">
                <div class="d-flex flex-column p-4 rounded-4 h-100" style="background: #ffffff; border: 1px solid var(--border);">
                    <div class="bg-gold p-2 rounded-circle mb-3 align-self-start" style="width: 12px; height: 12px;"></div>
                    <h4 class="h6 fw-bold mb-2">{{ $target[0] }}</h4>
                    <p class="text-muted small mb-0">{{ $target[1] }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>

<!-- Diferenciais BSI Capital -->
<section class="py-5 bg-white border-top border-bottom">
    <div class="container py-5">
        <div class="row align-items-center g-5">
            <div class="col-lg-5">
                <span class="text-brand fw-bold small text-uppercase mb-2 d-block" style="letter-spacing: 0.1em;">Diferenciais BSI</span>
                <h2 class="h3 fw-bold text-dark mb-4">Mais que atendimento, gestão focada</h2>
                <p class="text-muted mb-4">
                    O acompanhamento próximo faz parte do nosso DNA. Combinamos o suporte especializado com a tecnologia (como o Portal do Investidor e ferramentas com ACL) para oferecer previsibilidade e confiabilidade.
                </p>
            </div>
            <div class="col-lg-7">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="d-flex gap-3">
                            <div class="text-gold flex-shrink-0">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold">Suporte Fiduciário</h4>
                                <p class="small text-muted">Ações embasadas nas diretrizes do Termo de Securitização e convenções.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex gap-3">
                            <div class="text-gold flex-shrink-0">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <h4 class="h6 fw-bold">Escalabilidade com Qualidade</h4>
                                <p class="small text-muted">Apoio a operações pulverizadas mantendo um alto grau de diligência em cada contrato.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Final -->
<section class="py-5 position-relative" style="background: var(--brand-strong); overflow: hidden;">
    <div class="position-absolute top-0 start-0 w-100 h-100" style="opacity: 0.1; background: url('{{ asset('images/atendimento.jpg') }}') center/cover;"></div>
    <div class="container py-5 position-relative z-1 text-center">
        <h2 class="h3 fw-bold text-white mb-3">Precisando de uma securitizadora parceira?</h2>
        <p class="text-light mb-4 mx-auto" style="max-width: 600px;">
            Entenda como nosso atendimento pode otimizar a manutenção da sua operação no dia a dia.
        </p>
        <div class="d-flex flex-wrap gap-3 justify-content-center">
            <a href="{{ route('site.contact') }}" class="btn btn-brand btn-lg px-5 py-3 shadow-lg" style="transition: all 0.3s ease;">
                Entre em Contato
            </a>
        </div>
    </div>
</section>

@endsection

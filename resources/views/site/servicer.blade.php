@extends('site.layout')
@section('title', 'Servicer e Administração de Carteiras - BSI Capital')

@section('content')
<section class="hero-small position-relative overflow-hidden" style="background: linear-gradient(135deg, #020918 0%, #051a3d 100%); padding: 100px 0 60px;">
    <div class="container position-relative z-1">
        <div class="row">
            <div class="col-lg-8">
                <div class="kicker text-gold mb-3">Gestão de Ativos e Passivos</div>
                <h1 class="display-4 fw-bold text-white mb-4">Servicer e Administração de Carteiras</h1>
                <p class="lead text-white-50 mb-0">
                    Soluções completas para gestão, monitoramento e cobrança de recebíveis com tecnologia proprietária BSI Sentinel.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-4">
        <div class="row g-5 align-items-center">
            <div class="col-lg-6">
                <div class="section-kicker mb-2">O desafio da gestão</div>
                <h2 class="display-6 fw-bold mb-4 text-brand">Segurança e controle para operações complexas</h2>
                <p class="text-muted mb-4">
                    Gerir uma carteira de recebíveis exige mais do que apenas processamento financeiro. Exige rigor fiduciário, monitoramento constante de garantias e uma trilha de auditoria inquestionável.
                </p>
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex gap-3">
                        <i class="bi bi-check-circle-fill text-gold"></i>
                        <span>Conciliação bancária automatizada</span>
                    </div>
                    <div class="d-flex gap-3">
                        <i class="bi bi-check-circle-fill text-gold"></i>
                        <span>Monitoramento de covenants e gatilhos</span>
                    </div>
                    <div class="d-flex gap-3">
                        <i class="bi bi-check-circle-fill text-gold"></i>
                        <span>Gestão ativa de garantias e lastro</span>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="surface-card p-4 p-lg-5">
                    <h3 class="h4 fw-bold mb-4">Como a BSI atua como Servicer</h3>
                    <p class="small text-muted mb-4">Assumimos a responsabilidade operacional e fiduciária da carteira, garantindo que o fluxo de caixa siga exatamente as regras da emissão.</p>
                    <ul class="list-unstyled d-flex flex-column gap-3">
                        <li class="d-flex gap-2">
                            <span class="badge badge-soft">01</span>
                            <span><strong>Implantação Técnica:</strong> Configuração da operação no BSI Sentinel.</span>
                        </li>
                        <li class="d-flex gap-2">
                            <span class="badge badge-soft">02</span>
                            <span><strong>Gestão de Recebíveis:</strong> Controle de liquidação e inadimplência.</span>
                        </li>
                        <li class="d-flex gap-2">
                            <span class="badge badge-soft">03</span>
                            <span><strong>Reporting Institucional:</strong> Relatórios detalhados para todos os stakeholders.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5 section-dark">
    <div class="container">
        <div class="text-center mb-5">
            <div class="section-kicker mb-2">Tecnologia BSI Sentinel</div>
            <h2 class="h2 fw-bold">Diferenciais da Nossa Administração</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 border-0 p-4">
                    <h4 class="h5 fw-bold mb-3">Transparência Total</h4>
                    <p class="small text-muted mb-0">Acessos dedicados para emissores, investidores e auditores com visibilidade em tempo real.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 border-0 p-4">
                    <h4 class="h5 fw-bold mb-3">Rigor de Compliance</h4>
                    <p class="small text-muted mb-0">Processos aderentes às normas CVM e melhores práticas de governança do mercado.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 border-0 p-4">
                    <h4 class="h5 fw-bold mb-3">Escalabilidade</h4>
                    <p class="small text-muted mb-0">Estrutura preparada para grandes volumes e complexidade de múltiplos ativos.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="card border-0 overflow-hidden" style="background: linear-gradient(135deg, var(--brand-strong), #0b1f4f);">
            <div class="p-4 p-lg-5 text-center">
                <h2 class="h2 fw-bold text-white mb-3">Solicite um Diagnóstico da sua Carteira</h2>
                <p class="text-white-50 mb-4 mx-auto" style="max-width: 600px;">
                    Nossa equipe técnica está pronta para avaliar a viabilidade e os ganhos de eficiência da sua operação.
                </p>
                <a href="{{ route('proposal.create') }}" class="btn btn-light btn-lg px-5">Falar com um Estruturador</a>
            </div>
        </div>
    </div>
</section>
@endsection

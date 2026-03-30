@extends('site.layout')
@section('title', 'Serviços — BSI Capital')

@section('content')
<section class="hero position-relative d-flex align-items-center" style="min-height: 40vh;">
    <div class="container">
        <div class="row g-4 align-items-end">
            <div class="col-lg-8">
                <span class="badge mb-3 px-3 py-2 text-uppercase">Serviços integrados</span>
                <h1 class="display-4 fw-bold mb-3">Uma plataforma de serviços para cada etapa da operação</h1>
                <p class="lead mb-0" style="max-width: 760px;">
                    Estruturação, gestão e tecnologia em uma linguagem única, com a mesma disciplina operacional e o mesmo padrão de execução ao longo de toda a jornada.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container py-4">
        <div class="row g-4 align-items-end mb-5">
            <div class="col-lg-8">
                <div class="section-kicker mb-2">Arquitetura de serviços</div>
                <h2 class="h2 fw-bold text-brand mb-3">Três frentes conectadas para reduzir ruído e aumentar consistência</h2>
                <p class="section-copy mb-0">
                    Em vez de experiências desconectadas, a BSI opera com uma estrutura integrada que combina desenho da operação, acompanhamento e tecnologia em um mesmo padrão institucional.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="section-divider ms-lg-auto"></div>
            </div>
        </div>

        <div class="row g-4">
            @foreach([
                ['Estruturação', 'Modelagem, documentação, coordenação da oferta e governança para operações estruturadas.', route('site.servicos.originacao')],
                ['Gestão', 'Acompanhamento operacional, prestação de informações, relatórios e relacionamento com investidores.', route('site.servicos.portal-investidor')],
                ['Tecnologia', 'Automação, controle de acesso, auditoria e integração de processos críticos da plataforma.', route('site.servicos.documentos-acl')],
            ] as [$title, $description, $link])
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100 p-4 p-lg-5 border-0 shadow-sm card-hover">
                        <div class="section-kicker mb-2">Frente de atuação</div>
                        <h3 class="h3 fw-bold text-brand mb-3">{{ $title }}</h3>
                        <p class="section-copy mb-4">{{ $description }}</p>
                        <div class="mt-auto">
                            <a href="{{ $link }}" class="btn btn-outline-brand px-4">Acessar área</a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="py-5 section-dark">
    <div class="container py-5">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="surface-card-dark p-4 h-100">
                    <div class="section-kicker mb-2">Por que isso importa</div>
                    <h2 class="h3 fw-bold text-white mb-3">Menos fragmentação, mais clareza operacional</h2>
                    <p class="text-white-50 mb-0">
                        Uma interface coerente reforça a percepção de solidez, reduz atrito entre áreas e melhora a experiência de consulta e relacionamento.
                    </p>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="card h-100 p-4 border-0">
                            <div class="fw-semibold mb-2">Visão única</div>
                            <div class="text-muted small">A mesma identidade acompanha o usuário da apresentação institucional ao fluxo operacional.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100 p-4 border-0">
                            <div class="fw-semibold mb-2">Ações mais claras</div>
                            <div class="text-muted small">CTAs, formulários e estados seguem a mesma hierarquia visual em toda a navegação.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100 p-4 border-0">
                            <div class="fw-semibold mb-2">Marca mais forte</div>
                            <div class="text-muted small">Navy, dourado, superfícies e tipografia passam a operar como um sistema reconhecível.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="card border-0 overflow-hidden" style="background: linear-gradient(135deg, var(--brand-strong), #0b1f4f);">
            <div class="row g-0 align-items-center">
                <div class="col-lg-8">
                    <div class="p-4 p-lg-5">
                        <div class="section-kicker mb-2">Próximo passo</div>
                        <h2 class="h3 fw-bold text-white mb-3">Converse com a BSI sobre o desenho da sua operação</h2>
                        <p class="text-white-50 mb-0">
                            Nossa equipe integra estruturação, gestão e tecnologia para construir operações mais legíveis, rastreáveis e compatíveis com o mercado de capitais.
                        </p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="p-4 p-lg-5">
                        <a href="{{ route('site.contact') }}" class="btn btn-light btn-lg">Falar com a equipe</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

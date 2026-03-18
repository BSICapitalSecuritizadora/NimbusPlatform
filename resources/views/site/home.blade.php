@extends('site.layout')

@section('title', 'BSI Capital — Institucional')

@section('content')
<section class="hero py-5">
    <div class="container py-3">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <div class="hero-kicker mb-2">Securitização • Mercado de Capitais • Real Estate</div>
                <h1 class="display-6 fw-bold mb-3">
                    Estruturação e gestão de operações com padrão bancário.
                </h1>
                <p class="lead text-muted mb-4">
                    Soluções completas em securitização, governança e relacionamento com investidores,
                    com foco em transparência, eficiência e controle.
                </p>

                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-brand" href="#">Conheça nossos serviços</a>
                    <a class="btn btn-outline-brand" href="/portal">Acessar Portal do Investidor</a>
                </div>

                <div class="mt-4 d-flex gap-3 flex-wrap">
                    <span class="badge badge-soft px-3 py-2">Compliance & Auditoria</span>
                    <span class="badge badge-soft px-3 py-2">Documentos com Controle de Acesso</span>
                    <span class="badge badge-soft px-3 py-2">Relatórios e Transparência</span>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card p-4">
                    <div class="fw-semibold mb-2">Destaques</div>
                    <div class="small text-muted mb-3">Visão rápida do que está público hoje no sistema.</div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Emissões públicas</span>
                        <span class="fw-bold">{{ $emissions->count() }}</span>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Documentos de R.I</span>
                        <span class="fw-bold">{{ $riDocuments->count() }}</span>
                    </div>

                    <hr>

                    <div class="small text-muted">
                        Este card puxa dados reais do banco para você já demonstrar o sistema funcionando.
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <h2 class="section-title h4 mb-2">Quem somos</h2>
                <p class="text-muted">
                    A BSI Capital atua na estruturação e gestão de operações, com processos e
                    controles que garantem consistência e rastreabilidade.
                </p>
                <a href="#" class="btn btn-outline-brand btn-sm">Saiba mais</a>
            </div>

            <div class="col-lg-8">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="card p-3 h-100">
                            <div class="fw-semibold">Securitização</div>
                            <div class="text-muted small">Estruturação e acompanhamento de operações.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card p-3 h-100">
                            <div class="fw-semibold">Governança</div>
                            <div class="text-muted small">Fluxos operacionais e compliance.</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card p-3 h-100">
                            <div class="fw-semibold">Relação com Investidores</div>
                            <div class="text-muted small">Portal e documentos com controle.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Emissões --}}
        <div class="d-flex align-items-end justify-content-between mt-5 mb-3">
            <div>
                <h2 class="section-title h4 mb-1">Emissões em destaque</h2>
                <div class="text-muted small">Apenas emissões marcadas como públicas.</div>
            </div>
            <a href="#" class="btn btn-outline-brand btn-sm">Ver todas</a>
        </div>

        <div class="row g-3">
            @forelse($emissions as $e)
                <div class="col-md-6 col-lg-4">
                    <div class="card p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold">{{ $e->name }}</div>
                                <div class="text-muted small">
                                    {{ $e->type ?? '—' }} • {{ $e->status ?? '—' }}
                                </div>
                            </div>
                            <span class="badge badge-soft">{{ $e->type ?? '—' }}</span>
                        </div>

                        <div class="mt-3 small text-muted">
                            Emissor: {{ $e->issuer ?? '—' }} <br>
                            Vencimento: {{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}
                        </div>

                        <div class="mt-3">
                            <a href="#" class="btn btn-outline-brand btn-sm">Detalhes</a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="card p-4 text-muted">
                        Nenhuma emissão pública cadastrada ainda.
                    </div>
                </div>
            @endforelse
        </div>

        {{-- R.I --}}
        <div class="d-flex align-items-end justify-content-between mt-5 mb-3">
            <div>
                <h2 class="section-title h4 mb-1">Relações com Investidores</h2>
                <div class="text-muted small">Documentos publicados e públicos.</div>
            </div>
            <a href="#" class="btn btn-outline-brand btn-sm">Ver todos</a>
        </div>

        <div class="card">
            <div class="list-group list-group-flush">
                @forelse($riDocuments as $d)
                    <div class="list-group-item d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold">{{ $d->title }}</div>
                            <div class="text-muted small">
                                Categoria: {{ $d->category ?? '—' }}
                                • Publicado em: {{ optional($d->published_at)->format('d/m/Y') ?? '—' }}
                            </div>
                        </div>
                        <a href="#" class="btn btn-outline-brand btn-sm">Acessar</a>
                    </div>
                @empty
                    <div class="list-group-item text-muted">Nenhum documento público publicado ainda.</div>
                @endforelse
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="card p-4">
            <div class="row g-3 align-items-center">
                <div class="col-lg-8">
                    <h3 class="h5 fw-bold mb-1">Pronto para demonstrar o sistema?</h3>
                    <div class="text-muted">Você já pode mostrar site + admin + portal com dados reais.</div>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a href="/admin" class="btn btn-outline-brand">Abrir Admin</a>
                    <a href="/portal" class="btn btn-brand ms-2">Portal do Investidor</a>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
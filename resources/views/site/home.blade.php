@extends('site.layout')
@section('title','BSI Capital — Securitizadora')

@section('content')
<section class="hero py-5">
  <div class="container py-3">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <div class="kicker mb-2">Securitização • Mercado de Capitais • Real Estate</div>
        <h1 class="display-6 fw-bold mb-3">
          Soluções estruturadas de crédito para você fazer mais.
        </h1>
        <p class="lead text-muted mb-4">
          Governança, tecnologia e proximidade para estruturar e acompanhar operações
          com transparência e controle.
        </p>

        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-brand" href="#">Comece agora</a>
          <a class="btn btn-outline-brand" href="/portal">Portal do Investidor</a>
        </div>

        <div class="mt-4 d-flex gap-2 flex-wrap">
          <span class="badge badge-soft px-3 py-2">Compliance & Auditoria</span>
          <span class="badge badge-soft px-3 py-2">Controle de Acesso</span>
          <span class="badge badge-soft px-3 py-2">Relações com Investidores</span>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card p-4">
          <div class="fw-semibold mb-2">Visão rápida</div>
          <div class="small text-muted mb-3">Dados reais do seu banco (ambiente local).</div>

          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Emissões públicas</span>
            <span class="fw-bold">{{ $emissions->count() }}</span>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Documentos públicos (R.I)</span>
            <span class="fw-bold">{{ $riDocuments->count() }}</span>
          </div>

          <hr>
          <div class="small text-muted">
            “Vamos além?” — visão institucional com padrão bancário.
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

{{-- Faixa de parceiros (MVP com placeholders) --}}
<section class="py-4">
  <div class="container">
    <div class="text-muted small mb-2">Alguns parceiros</div>
    <div class="card p-3">
      <div class="d-flex flex-wrap align-items-center gap-4 logo-strip">
        <img src="https://dummyimage.com/120x28/ddd/aaa&text=Parceiro+1" alt="">
        <img src="https://dummyimage.com/120x28/ddd/aaa&text=Parceiro+2" alt="">
        <img src="https://dummyimage.com/120x28/ddd/aaa&text=Parceiro+3" alt="">
        <img src="https://dummyimage.com/120x28/ddd/aaa&text=Parceiro+4" alt="">
      </div>
    </div>
  </div>
</section>

{{-- Indústrias / Soluções (cards estilo Opea) --}}
<section class="py-5">
  <div class="container">
    <div class="d-flex align-items-end justify-content-between mb-3">
      <div>
        <h2 class="h4 fw-bold mb-1">Soluções por indústria</h2>
        <div class="text-muted small">Para empresas, estruturadores e investidores.</div>
      </div>
      <a class="btn btn-outline-brand btn-sm" href="#">Ver soluções</a>
    </div>

    <div class="row g-3">
      @php
        $industries = [
          ['Imobiliário','Estruturação e acompanhamento de operações'],
          ['Agronegócio','Crédito estruturado para cadeias e projetos'],
          ['Infraestrutura','Financiamento para desenvolvimento e expansão'],
          ['Fintechs','Operações escaláveis com governança'],
        ];
      @endphp

      @foreach($industries as [$title,$desc])
        <div class="col-md-6 col-lg-3">
          <div class="card p-3 h-100">
            <div class="fw-semibold">{{ $title }}</div>
            <div class="text-muted small mt-1">{{ $desc }}</div>
            <div class="mt-3">
              <a class="btn btn-outline-brand btn-sm" href="#">Saiba mais</a>
            </div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- Cases --}}
<section class="py-5">
  <div class="container">
    <div class="d-flex align-items-end justify-content-between mb-3">
      <div>
        <h2 class="h4 fw-bold mb-1">Cases e resultados</h2>
        <div class="text-muted small">Exemplos de como estruturamos operações com controle e transparência.</div>
      </div>
      <a class="btn btn-outline-brand btn-sm" href="#">Ver todos</a>
    </div>

    <div class="row g-3">
      @php
        $cases = [
          ['Estruturação CRI','Operação com governança e relatórios recorrentes.','Imobiliário'],
          ['Gestão de Documentos','Portal com controle de acesso e auditoria.','R.I'],
          ['Automação Operacional','Fluxos e aprovações com rastreabilidade.','Tecnologia'],
        ];
      @endphp

      @foreach($cases as $case)
        @php [$title, $desc, $tag] = $case; @endphp
        <div class="col-md-6 col-lg-4">
          <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="fw-semibold">{{ $title }}</div>
              <span class="badge badge-soft">{{ $tag }}</span>
            </div>
            <div class="text-muted small mb-3">{{ $desc }}</div>
            <a class="btn btn-outline-brand btn-sm" href="#">Detalhes</a>
          </div>
        </div>
      @endforeach
    </div>
  </div>
</section>

<section class="py-5">
  <div class="container">
    {{-- Emissões em destaque (dados reais) --}}
    <div class="d-flex align-items-end justify-content-between mb-3">
      <div>
        <h2 class="h4 fw-bold mb-1">Emissões em destaque</h2>
        <div class="text-muted small">Somente emissões marcadas como públicas.</div>
      </div>
      <a class="btn btn-outline-brand btn-sm" href="#">Ver todas</a>
    </div>

    <div class="row g-3">
      @forelse($emissions as $e)
        <div class="col-md-6 col-lg-4">
          <div class="card p-3 h-100">
            <div class="d-flex justify-content-between">
              <div>
                <div class="fw-semibold">{{ $e->name }}</div>
                <div class="text-muted small">{{ $e->type ?? '—' }} • {{ $e->status ?? '—' }}</div>
              </div>
              <span class="badge badge-soft">{{ $e->type ?? '—' }}</span>
            </div>
            <div class="text-muted small mt-3">
              Emissor: {{ $e->issuer ?? '—' }}<br>
              Vencimento: {{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}
            </div>
          </div>
        </div>
      @empty
        <div class="col-12"><div class="card p-4 text-muted">Nenhuma emissão pública cadastrada ainda.</div></div>
      @endforelse
    </div>

    {{-- RI (dados reais) --}}
    <div class="d-flex align-items-end justify-content-between mt-5 mb-3">
      <div>
        <h2 class="h4 fw-bold mb-1">Relações com Investidores</h2>
        <div class="text-muted small">Documentos publicados e públicos.</div>
      </div>
      <a class="btn btn-outline-brand btn-sm" href="#">Ver todos</a>
    </div>

    <div class="card">
      <div class="list-group list-group-flush">
        @forelse($riDocuments as $d)
          <div class="list-group-item d-flex justify-content-between align-items-start" style="background: var(--surface); border-color: var(--border);">
            <div>
              <div class="fw-semibold">{{ $d->title }}</div>
              <div class="text-muted small">
                Categoria: {{ $d->category ?? '—' }}
                • Publicado em: {{ optional($d->published_at)->format('d/m/Y') ?? '—' }}
              </div>
            </div>
            <a class="btn btn-outline-brand btn-sm" href="#">Acessar</a>
          </div>
        @empty
          <div class="list-group-item text-muted" style="background: var(--surface); border-color: var(--border);">
            Nenhum documento público publicado ainda.
          </div>
        @endforelse
      </div>
    </div>
  </div>
</section>

{{-- Newsletter (MVP) --}}
<section class="py-5">
  <div class="container">
    <div class="card p-4">
      <div class="row g-3 align-items-center">
        <div class="col-lg-7">
          <h3 class="h5 fw-bold mb-1">Assine nossa newsletter</h3>
          <div class="text-muted">Conteúdo e atualizações sobre operações, relatórios e comunicados.</div>
        </div>
        <div class="col-lg-5">
          <form class="d-flex gap-2">
            <input class="form-control" placeholder="seu@email.com" type="email">
            <button class="btn btn-brand" type="button">Assinar</button>
          </form>
          <div class="text-muted small mt-2">Ao assinar, você concorda com a política de privacidade.</div>
        </div>
      </div>
    </div>
  </div>
</section>
@endsection
@extends('site.layout')
@section('title','BSI Capital — Securitizadora')

@section('content')
<section class="hero position-relative overflow-hidden">
  {{-- Video Background --}}
  <video autoplay loop muted playsinline class="position-absolute w-100 h-100 object-fit-cover" style="top: 0; left: 0; z-index: 0; opacity: 0.35; pointer-events: none;">
    <source src="https://opea.com.br/wp-content/themes/opeacapital/assets/video/nova_intro.mp4" type="video/mp4">
  </video>

  <div class="container py-3 position-relative" style="z-index: 1;">
    <div class="row align-items-center g-4 text-center">
      <div class="col-lg-8 mx-auto">
        <div class="kicker mb-3">Securitização • Mercado de Capitais • Real Estate</div>
        <h1 class="display-4 fw-bold mb-4" style="letter-spacing: -0.02em;">
          Soluções estruturadas de crédito para você fazer mais.
        </h1>
        <p class="lead mb-5" style="color: rgba(255,255,255,0.85); font-weight: 300;">
          Governança, tecnologia e proximidade para estruturar e acompanhar operações
          com transparência e controle.
        </p>

        {{-- Opea style email capture pill --}}
        <div class="bg-white p-2 rounded-pill shadow-lg mx-auto d-flex align-items-center" style="max-width: 500px;">
          <input type="email" class="form-control border-0 shadow-none bg-transparent ps-4" placeholder="Seu melhor e-mail" style="outline: none; box-shadow: none;">
          <button class="btn btn-brand rounded-pill px-4 text-nowrap" type="button" style="background: var(--gold); border-color: var(--gold); color: #000; font-weight: 600;">Comece agora</button>
        </div>


      </div>
    </div>
  </div>
</section>



{{-- Indústrias / Soluções (cards grandes com foto estilo Opea) --}}
<section class="py-5 bg-white">
  <div class="container py-4">
    <div class="text-center mb-5">
      <h2 class="h3 fw-bold mb-2" style="color: var(--brand);">Soluções por indústria</h2>
      <div class="text-muted">Para empresas, estruturadores e investidores.</div>
    </div>

    <div class="row g-4 justify-content-center">
      @php
        $industries = [
          ['Imobiliário','Estruturação e acompanhamento de operações.', 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=800&auto=format&fit=crop', '/imobiliario/cri-real-estate'],
          ['Agronegócio','Crédito estruturado para cadeias e projetos.', 'https://images.unsplash.com/photo-1500382017468-9049fed747ef?q=80&w=800&auto=format&fit=crop', '/agronegocio/cra'],
          ['Infra & Empresas','Financiamento, expansão e novos recebíveis (CR).', 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?q=80&w=800&auto=format&fit=crop', '/infra-empresas/cr-futuro'],
        ];
      @endphp

      @foreach($industries as [$title, $desc, $img, $link])
        <div class="col-md-6 col-lg-4">
          <div class="card h-100 border-0 overflow-hidden position-relative shadow-sm" style="border-radius: 32px; min-height: 400px;">
            <img src="{{ $img }}" class="position-absolute w-100 h-100 object-fit-cover" alt="{{ $title }}">
            <div class="position-absolute w-100 h-100" style="background: linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,32,91,0.85) 100%);"></div>
            <div class="position-absolute bottom-0 p-4 w-100 text-white">
              <h3 class="h5 fw-bold mb-2">{{ $title }}</h3>
              <p class="small mb-4" style="opacity: 0.9;">{{ $desc }}</p>
              <a href="{{ $link }}" class="btn btn-sm btn-light rounded-pill px-3 fw-bold" style="color: var(--brand);">Saiba mais</a>
            </div>
          </div>
        </div>
      @endforeach
    </div>
  </div>
</section>

{{-- Cases (Opea alternating layout in dark section) --}}
<section class="py-5 section-dark">
  <div class="container py-5">
    <div class="text-center mb-5">
      <div class="kicker mb-2">Cases de Sucesso</div>
      <h2 class="h3 fw-bold mb-3">Exemplos práticos de estruturação</h2>
      <p class="text-muted mx-auto" style="max-width: 600px;">
        Como a BSI Capital ajuda empresas a gerenciarem operações complexas com foco em governança e controle.
      </p>
    </div>

    @php
      $cases = [
        [
            'title' => 'Estruturação CRI',
            'desc' => 'Operação completa com governança e relatórios recorrentes, utilizando nossa infraestrutura para garantir o lastro perfeitamente auditável.',
            'img' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=1000&auto=format&fit=crop',
            'slug' => 'estruturacao-cri'
        ],
        [
            'title' => 'Gestão de Documentos',
            'desc' => 'Desenvolvimento de um portal customizado com controle de acesso granular e auditoria completa para todos os investidores da operação.',
            'img' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?q=80&w=1000&auto=format&fit=crop',
            'slug' => 'gestao-de-documentos'
        ],
      ];
    @endphp

    @foreach($cases as $index => $case)
      <div class="row align-items-center g-5 mb-5 {{ $index % 2 !== 0 ? 'flex-row-reverse' : '' }}">
        <div class="col-lg-6">
          <div class="overflow-hidden shadow-lg" style="border-radius: 32px;">
            <img src="{{ $case['img'] }}" class="img-fluid w-100 object-fit-cover" style="height: 400px;" alt="Case Image">
          </div>
        </div>
        <div class="col-lg-6 px-lg-5">
          <h3 class="h2 fw-bold mb-3">{{ $case['title'] }}</h3>
          <p class="lead text-muted mb-4" style="font-weight: 300;">{{ $case['desc'] }}</p>
          <a href="{{ route('site.cases.show', $case['slug']) }}" class="btn btn-outline-brand rounded-pill px-4" style="border-color: var(--gold); color: var(--gold);">Ler estudo de caso</a>
        </div>
      </div>
    @endforeach
  </div>
</section>

<section class="py-5" style="background: var(--bg);">
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
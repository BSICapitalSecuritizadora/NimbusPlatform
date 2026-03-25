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
    <div class="d-flex align-items-end justify-content-between mb-4">
      <div>
        <div class="d-flex align-items-center gap-2 mb-2">
          <div style="width: 4px; height: 28px; background: linear-gradient(180deg, var(--gold), var(--brand)); border-radius: 4px;"></div>
          <h2 class="h4 fw-bold mb-0" style="letter-spacing: -0.02em;">Emissões em destaque</h2>
        </div>
        <div class="text-muted small ms-3">Somente emissões marcadas como públicas.</div>
      </div>
      <a class="btn btn-outline-brand btn-sm px-4" href="{{ route('site.emissions') }}">
        Ver todas
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="ms-1" style="vertical-align: -1px;"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </a>
    </div>

    <div class="row g-4">
      @forelse($emissions as $e)
        <div class="col-md-6 col-lg-4">
          <a href="{{ route('site.emissions.show', $e->if_code ?? $e->id) }}" class="text-decoration-none d-block h-100">
            <div class="card h-100 overflow-hidden emission-card" style="transition: all 0.3s cubic-bezier(.4,0,.2,1); cursor: pointer; position: relative;">
              {{-- Gradient top accent --}}
              <div style="height: 4px; background: linear-gradient(90deg, var(--brand), var(--gold), var(--brand)); flex-shrink: 0;"></div>

              <div class="p-4">
                {{-- Header: Logo/Name + Badges --}}
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                  <div style="flex: 1; min-width: 0;">
                    @if($e->logo_path)
                      <img src="{{ asset('storage/' . $e->logo_path) }}" alt="{{ $e->name }}" style="max-height: 40px; max-width: 180px; object-fit: contain;" loading="lazy">
                    @else
                      <h3 class="h6 fw-bold mb-0" style="color: var(--text); letter-spacing: -0.01em;">{{ $e->name }}</h3>
                    @endif
                  </div>
                  <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    @if($e->status)
                      @php
                        $statusColors = [
                          'active' => ['bg' => 'rgba(34,197,94,0.1)', 'border' => 'rgba(34,197,94,0.25)', 'text' => '#16a34a', 'label' => 'Ativa'],
                          'closed' => ['bg' => 'rgba(239,68,68,0.1)', 'border' => 'rgba(239,68,68,0.25)', 'text' => '#dc2626', 'label' => 'Encerrada'],
                        ];
                        $sc = $statusColors[$e->status] ?? ['bg' => 'rgba(245,158,11,0.1)', 'border' => 'rgba(245,158,11,0.25)', 'text' => '#d97706', 'label' => ucfirst($e->status)];
                      @endphp
                        <span class="badge d-inline-flex align-items-center gap-1" style="background: {{ $sc['bg'] }}; border: 1px solid {{ $sc['border'] }}; color: {{ $sc['text'] }}; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.03em; padding: 0 12px; height: 28px;">
                        <span style="width: 6px; height: 6px; border-radius: 50%; background: {{ $sc['text'] }}; display: inline-block;"></span>
                        {{ $sc['label'] }}
                      </span>
                    @endif
                    @if($e->type)
                      <span class="badge badge-soft d-inline-flex align-items-center" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; padding: 0 12px; height: 28px;">{{ $e->type }}</span>
                    @endif
                  </div>
                </div>

                {{-- Divider --}}
                <hr style="border-color: var(--border); opacity: 0.6; margin: 0 0 0.875rem 0;">

                {{-- Info rows --}}
                <div class="d-flex flex-column gap-2">
                  <div class="d-flex align-items-center gap-2">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px; border-radius: 8px; background: rgba(0,32,91,0.06);">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div style="min-width: 0;">
                      <div style="font-size: 0.68rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600;">Emissor</div>
                      <div style="font-size: 0.82rem; font-weight: 500; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $e->issuer ?? '—' }}</div>
                    </div>
                  </div>

                  <div class="d-flex align-items-center gap-2">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px; border-radius: 8px; background: rgba(212,175,55,0.08);">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div style="min-width: 0;">
                      <div style="font-size: 0.68rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600;">Data de Vencimento</div>
                      <div style="font-size: 0.82rem; font-weight: 500; color: var(--text);">{{ optional($e->maturity_date)->format('d/m/Y') ?? '—' }}</div>
                    </div>
                  </div>

                  <div class="d-flex align-items-center gap-2">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px; border-radius: 8px; background: rgba(0,32,91,0.06);">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <div style="min-width: 0;">
                      <div style="font-size: 0.68rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600;">Valor da Emissão</div>
                      <div style="font-size: 0.82rem; font-weight: 500; color: var(--text);">{{ $e->issued_volume ? 'R$ ' . number_format($e->issued_volume, 2, ',', '.') : '—' }}</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </a>
        </div>
      @empty
        <div class="col-12">
          <div class="card p-5 text-center" style="border-style: dashed;">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="1.5" class="mx-auto mb-3" style="opacity: 0.5;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            <div class="text-muted">Nenhuma emissão pública cadastrada ainda.</div>
          </div>
        </div>
      @endforelse
    </div>

    {{-- RI (dados reais) --}}
    <div class="d-flex align-items-end justify-content-between mt-5 mb-4">
      <div>
        <div class="d-flex align-items-center gap-2 mb-2">
          <div style="width: 4px; height: 28px; background: linear-gradient(180deg, var(--gold), var(--brand)); border-radius: 4px;"></div>
          <h2 class="h4 fw-bold mb-0" style="letter-spacing: -0.02em;">Relações com Investidores</h2>
        </div>
        <div class="text-muted small ms-3">Documentos publicados e públicos.</div>
      </div>
      <a class="btn btn-outline-brand btn-sm px-4" href="{{ route('site.ri') }}">
        Ver todos
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="ms-1" style="vertical-align: -1px;"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </a>
    </div>

    <div class="card overflow-hidden shadow-sm border-0" style="border-radius: 24px;">
      <div class="list-group list-group-flush">
        @forelse($riDocuments as $d)
          <div class="list-group-item p-3 p-md-4 ri-item" style="background: var(--surface); border-color: var(--border); transition: all 0.2s ease;">
            <div class="row align-items-center g-3">
              <div class="col-auto">
                <div class="d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; border-radius: 12px; background: rgba(0,32,91,0.05); color: var(--brand);">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
              </div>
              <div class="col">
                <div class="fw-bold mb-1" style="color: var(--text); font-size: 1.05rem;">{{ $d->title }}</div>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                  <span class="d-flex align-items-center gap-1" style="font-size: 0.8rem; color: var(--muted);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    {{ $d->category_label ?? 'Documento' }}
                  </span>
                  <span class="d-flex align-items-center gap-1" style="font-size: 0.8rem; color: var(--muted);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    {{ optional($d->published_at)->format('d/m/Y') ?? '—' }}
                  </span>
                </div>
              </div>
              <div class="col-md-auto text-end">
                <a href="{{ Storage::disk($d->resolved_storage_disk)->url($d->file_path) }}" target="_blank" class="btn btn-light rounded-pill px-4 fw-bold" style="color: var(--brand); font-size: 0.85rem; border: 1px solid var(--border);">
                  Acessar
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="ms-1"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </a>
              </div>
            </div>
          </div>
        @empty
          <div class="list-group-item p-5 text-center text-muted" style="background: var(--surface); border-color: var(--border);">
            <div class="mb-2">Nenhum documento público publicado ainda.</div>
            <div class="small">Fique atento para futuras atualizações.</div>
          </div>
        @endforelse
      </div>
    </div>
  </div>
</section>

{{-- Newsletter (Premium) --}}
<section class="py-5">
  <div class="container">
    <div class="card p-4 p-md-5 border-0 shadow-lg overflow-hidden position-relative" style="border-radius: 32px; background: #001233;">
      {{-- Decor --}}
      <div class="position-absolute" style="top: -50px; right: -50px; width: 200px; height: 200px; background: radial-gradient(circle, rgba(212,175,55,0.1) 0%, transparent 70%);"></div>
      
      <div class="row g-4 align-items-center position-relative" style="z-index: 1;">
        <div class="col-lg-7">
          <div class="d-flex align-items-center gap-3 mb-2">
            <div class="d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; border-radius: 10px; background: rgba(255,255,255,0.1); color: var(--gold);">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
            </div>
            <h3 class="h4 fw-bold mb-0 text-white">Fique por dentro das novidades</h3>
          </div>
          <p class="text-white opacity-75 mb-0" style="font-weight: 300; font-size: 1.1rem;">Assine nossa newsletter e receba atualizações sobre operações e comunicados.</p>
        </div>
        <div class="col-lg-5">
          <form class="bg-white p-2 rounded-pill shadow-sm d-flex align-items-center">
            <input type="email" class="form-control border-0 shadow-none bg-transparent ps-4" placeholder="seu@email.com" style="outline: none; box-shadow: none;">
            <button class="btn btn-brand rounded-pill px-4 text-nowrap" type="button" style="background: var(--gold); border-color: var(--gold); color: #000; font-weight: 600;">Assinar agora</button>
          </form>
          <div class="text-white opacity-50 small mt-3 px-3">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="me-1"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Garantimos a privacidade dos seus dados.
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
@endsection
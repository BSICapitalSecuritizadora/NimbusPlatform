@extends('site.layout')
@section('title','Relações com Investidores — BSI Capital')

@section('content')
<section class="py-5">
  <div class="container">
    <div class="d-flex align-items-end justify-content-between mb-3">
      <div>
        <div class="kicker mb-2">R.I</div>
        <h1 class="h3 fw-bold mb-1">Documentos públicos</h1>
        <div class="text-muted small">Somente documentos publicados e públicos.</div>
      </div>
    </div>

    <form method="GET" class="card p-4 border-0 shadow-sm mb-5" style="border-radius: 24px;">
      <div class="row g-3 align-items-end">
        <div class="col-md-5">
          <label class="form-label small fw-bold text-muted">BUSCAR POR TÍTULO</label>
          <input class="form-control rounded-pill border-0 bg-light-subtle ps-3" name="q" value="{{ $q }}" placeholder="Ex.: Relatório Anual" style="background: var(--bg); border: 1px solid var(--border);">
        </div>

        <div class="col-md-4">
          <label class="form-label small fw-bold text-muted">CATEGORIA</label>
          <select class="form-select rounded-pill border-0 bg-light-subtle" name="category" style="background: var(--bg); border: 1px solid var(--border);">
            <option value="">Todas</option>
            @foreach($categories as $key => $label)
              <option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </div>

        <div class="col-md-3 d-grid">
          <button class="btn btn-brand rounded-pill px-4">Filtrar resultados</button>
        </div>
      </div>
    </form>

    <div class="d-flex flex-column gap-3">
      @forelse($docs as $d)
        <div class="card p-4 border-0 shadow-sm card-hover" style="border-radius: 20px; transition: all 0.3s ease;">
          <div class="row align-items-center">
            <div class="col-md-8">
              <h3 class="h6 fw-bold mb-1" style="color: var(--brand-outline);">{{ $d->title }}</h3>
              <div class="text-muted small">
                <span class="badge badge-soft rounded-pill me-2 px-3">{{ $categories[$d->category] ?? ($d->category ?? '—') }}</span>
                Publicado em: {{ optional($d->{$dateField})->format('d/m/Y') ?? '—' }}
              </div>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
               <span class="badge badge-soft px-3 py-2 rounded-pill me-2">Documento Público</span>
               <a href="#" class="btn btn-sm btn-outline-brand rounded-pill px-4">Acessar</a>
            </div>
          </div>
        </div>
      @empty
        <div class="card p-5 text-center text-muted border-0 shadow-sm" style="border-radius: 20px;">
           <div class="mb-2">Nenhum documento público publicado ainda.</div>
           <small>Tente ajustar seus filtros de busca.</small>
        </div>
      @endforelse
    </div>

    <div class="mt-3">
      {{ $docs->links() }}
    </div>
  </div>
</section>
@endsection
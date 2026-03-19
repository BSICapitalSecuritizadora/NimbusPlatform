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

    <form method="GET" class="card p-3 mb-4">
      <div class="row g-3 align-items-end">
        <div class="col-md-5">
          <label class="form-label">Buscar por título</label>
          <input class="form-control" name="q" value="{{ $q }}" placeholder="Ex.: Relatório Anual">
        </div>

        <div class="col-md-5">
          <label class="form-label">Categoria</label>
          <select class="form-select" name="category">
            <option value="">Todas</option>
            @foreach($categories as $key => $label)
              <option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </div>

        <div class="col-md-2 d-grid">
          <button class="btn btn-brand">Filtrar</button>
        </div>
      </div>
    </form>

    <div class="card">
      <div class="list-group list-group-flush">
        @forelse($docs as $d)
          <div class="list-group-item d-flex justify-content-between align-items-start"
               style="background: var(--surface); border-color: var(--border);">
            <div>
              <div class="fw-semibold">{{ $d->title }}</div>
              <div class="text-muted small">
                Categoria: {{ $categories[$d->category] ?? ($d->category ?? '—') }}
                • Publicado em: {{ optional($d->{$dateField})->format('d/m/Y') ?? '—' }}
              </div>
            </div>

            {{-- Por enquanto sem download público direto. Depois fazemos rota pública segura. --}}
            <span class="badge badge-soft">Público</span>
          </div>
        @empty
          <div class="list-group-item text-muted"
               style="background: var(--surface); border-color: var(--border);">
            Nenhum documento público publicado ainda.
          </div>
        @endforelse
      </div>
    </div>

    <div class="mt-3">
      {{ $docs->links() }}
    </div>
  </div>
</section>
@endsection